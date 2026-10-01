<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\DecimalString;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NobitexWebSocketService
 * -----------------------
 * WebSocket consumer for Nobitex (Centrifugo-based).
 * - Subscribes to public orderbook channels: public:orderbook-{SYMBOL}
 * - Subscribes to candle channels public:candle-{SYMBOL}-{RESOLUTION} from
 *   config('trading.websocket.candle_*') and caches the latest candle per
 *   (symbol, resolution) at MarketDataLayer::candleCacheKey() (read by CandleService)
 * - Handles ping/pong ({}) and reconnect with backoff + jitter
 * - Seeds each symbol's orderbook from REST v3 on every (re)connect, since the
 *   orderbook channel only publishes on change
 * - Updates Laravel cache using keys expected by MarketDataLayer, throttled per
 *   symbol (cache.default is a DB store in production: every put is a write)
 * - Writes heartbeat keys (last frame / last publication) read by
 *   App\Support\WsFeedHealthCheck
 * - Keeps small in-memory snapshots for fast read (used by MarketDataLayer)
 *
 * Requires: composer require textalk/websocket
 */
class NobitexWebSocketService
{
    /** WebSocket endpoint (default from .env or config) */
    protected string $wsUrl;

    /** Heartbeat cache keys (read by App\Support\WsFeedHealthCheck) */
    public const HEARTBEAT_FRAME_KEY       = 'nobitex:ws:last_frame_at';
    public const HEARTBEAT_PUBLICATION_KEY = 'nobitex:ws:last_publication_at';

    /** Heartbeat keys are written at most this often (in-memory guard) */
    protected const HEARTBEAT_WRITE_GUARD_SECONDS = 10;
    protected const HEARTBEAT_TTL_SECONDS = 86400;

    /** Candle channel prefix: public:candle-{SYMBOL}-{RESOLUTION} */
    public const CANDLE_CHANNEL_PREFIX = 'public:candle-';
    /** TTL of the latest-candle cache key */
    public const CANDLE_TTL_SECONDS = 300;

    /** Laravel cache store name */
    protected string $cacheStore;

    /** Cache TTLs */
    protected int $ttlPriceSeconds;
    protected int $ttlOrderbookSeconds;

    /** Single-instance lock (avoid duplicated consumers) */
    protected string $lockKey = 'nobitex:ws:consumer:lock';
    protected int $lockTtl  = 60; // sec

    /** Debug / stdout controls */
    protected bool $debugStdout = false;
    protected int  $debugRawFramesLimit = 0; // 0=off, >0 prints first N raw frames

    /** Local snapshots for quick reads by MarketDataLayer */
    /** @var array<string,array{price:int,ts:int}> */
    protected array $lastPriceSnap = [];
    /** @var array<string,array{asks:array,bids:array,lastTradePrice:int,lastUpdate:int}> */
    protected array $orderbookSnap = [];

    /** Per-symbol cache write throttle */
    protected int $cacheWriteIntervalMs;
    /** @var array<string,int> symbol => ms of last cache write */
    protected array $lastCacheWriteMs = [];
    /** @var array<string,true> symbols whose latest snapshot is not yet in cache */
    protected array $dirtySymbols = [];

    /** Max wall time spent seeding per connection (server ping deadline) */
    protected int $seedBudgetSeconds;

    /** @var array<string,int> heartbeat key => last write (unix seconds) */
    protected array $heartbeatWrittenAt = [];

    /**
     * Latest candle per "{SYMBOL}:{RESOLUTION}" (getOhlc row shape), with its
     * own throttle state mirroring the orderbook one.
     * @var array<string,array{symbol:string,resolution:string,candle:array{t:int,o:string,h:string,l:string,c:string,v:string}}>
     */
    protected array $candleSnap = [];
    /** @var array<string,int> candle key => ms of last cache write */
    protected array $lastCandleWriteMs = [];
    /** @var array<string,true> candle keys whose latest candle is not yet in cache */
    protected array $dirtyCandles = [];

    public function __construct()
    {
        $cfg = (array) config('trading.nobitex', []);
        // Important: correct host is ws.nobitex.ir (NOT wss.nobitex.ir)
        $this->wsUrl = $cfg['websocket_url']
            ?? env('NOBITEX_WS_URL', env('WEBSOCKET_URL', 'wss://ws.nobitex.ir/connection/websocket'));

        $this->cacheStore = (string) config('cache.default');
        $this->ttlPriceSeconds     = (int) (config('trading.cache.price_ttl', 5));
        $this->ttlOrderbookSeconds = (int) (config('trading.cache.market_stats_ttl', 60));
        $this->cacheWriteIntervalMs = max(0, (int) config('trading.websocket.cache_write_interval_ms', 1000));
        $this->seedBudgetSeconds    = max(0, (int) config('trading.websocket.seed_budget_seconds', 15));
    }

    /* ====================== Public helpers (used elsewhere) ====================== */

    /** Toggle printing extra logs to stdout (for artisan --debug or tinker) */
    public function enableStdout(bool $enable): void
    {
        $this->debugStdout = $enable;
    }

    /** Print up to N raw frames on connection (debug). 0 disables. */
    public function setDebugRawFramesLimit(int $n): void
    {
        $this->debugRawFramesLimit = max(0, $n);
    }

    /**
     * Last price snapshot for a symbol (if any)
     * @return array{price:int,ts:int}|null
     */
    public function getLastPriceSnapshot(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        return $this->lastPriceSnap[$symbol] ?? null;
    }

    /**
     * Orderbook snapshot for a symbol (if any)
     * @return array{asks:array,bids:array,lastTradePrice:int,lastUpdate:int}|null
     */
    public function getOrderbookSnapshot(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        return $this->orderbookSnap[$symbol] ?? null;
    }

    /* ============================== Main runner =============================== */

    /**
     * Blocking run. Execute via Artisan command (Supervisor/pm2/etc).
     * @param array<int,string> $symbols  e.g. ['BTCIRT','ETHIRT']
     */
    public function run(array $symbols, bool $force = false): void
    {
        $symbols = array_values(array_filter(array_map(
            fn($s) => strtoupper(trim((string) $s)),
            $symbols
        ), fn($s) => $s !== ''));

        $this->out('[WS] Run loop start', ['symbols' => $symbols, 'force' => $force]);

        if (!$force && !Cache::add($this->lockKey, getmypid(), $this->lockTtl)) {
            $this->out('[WS] Another consumer already running; abort.');
            return;
        }

        $attempt = 0;
        while (true) {
            try {
                $attempt++;
                $this->consume($symbols);
                $attempt = 0; // unlikely (consume is a forever loop), but reset backoff if loop returns
            } catch (\Throwable $e) {
                $wait = $this->computeBackoffWithJitter($attempt);
                $this->out('[WS] Crash', ['error' => $e->getMessage(), 'attempt' => $attempt, 'wait' => $wait], 'error');
                sleep($wait);
            } finally {
                Cache::put($this->lockKey, getmypid(), $this->lockTtl);
            }
        }
    }

    /* ============================ Core consume loop =========================== */

    /**
     * One connection lifecycle: connect -> send connect frame -> subscribe -> read loop
     * @param array<int,string> $symbols
     */
    protected function consume(array $symbols): void
    {
        $this->out('[WS] Connecting', [
            'url' => $this->wsUrl,
            'ssl_insecure' => false,
        ]);

        // textalk/websocket client
        $client = new \WebSocket\Client($this->wsUrl, [
            'timeout' => 25,
            'headers' => $this->buildHandshakeHeaders(),
        ]);
        $this->out('[WS] Connected OK');

        // Centrifugo connect frame. For public channels auth is optional; send empty connect {}.
        $client->send(json_encode(['connect' => (object)[], 'id' => 1], JSON_UNESCAPED_SLASHES));

        // The orderbook channel publishes only on change, so seed the cache from
        // REST v3 first (per Nobitex docs). Never blocks connecting/subscribing.
        $this->seedOrderbooks($symbols);

        // Subscribe to orderbooks: public:orderbook-{SYMBOL}
        $nextId = $this->subscribeOrderbooks($client, $symbols);

        // Then candles on the same connection, continuing the frame id sequence
        $this->subscribeCandles($client, $nextId);

        $framesPrinted = 0;

        // Read loop
        while (true) {
            // Keep the single-instance lock fresh
            Cache::put($this->lockKey, getmypid(), $this->lockTtl);

            // Receive frame (string)
            $raw = $client->receive();

            // Heartbeat + flush throttled snapshots on EVERY frame (pings included)
            $this->onFrameReceived();

            if ($raw === null || $raw === '') {
                // Some servers may push no-op/empty occasionally; just continue
                $this->out('[WS] recv empty');
                // Respond pong just in case (Centrifugo ping/pong is {})
                $client->send('{}');
                continue;
            }

            if ($this->debugRawFramesLimit > 0 && $framesPrinted < $this->debugRawFramesLimit) {
                $this->out('[WS] RAW', ['raw' => $raw]);
                $framesPrinted++;
            }

            // Centrifugo can batch multiple JSON messages per frame, separated by \n.
            $lines = preg_split('/\r?\n/', trim((string) $raw));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (!is_array($data)) {
                    $this->out('[WS] Non-JSON line', ['raw' => $line], 'warning');
                    continue;
                }
                $this->handleCentrifugoMessage($data, $client);
            }
        }
    }

    private function handleCentrifugoMessage(array $data, \WebSocket\Client $client): void
    {
        // Empty object {} = server ping; respond with pong
        if (count($data) === 0) {
            $client->send('{}');
            return;
        }

        // Centrifugo push envelope: {"push":{"channel":"...","pub":{"data":...}}}
        if (isset($data['push']['channel'])) {
            $channel = (string) $data['push']['channel'];
            $payload = $data['push']['pub']['data'] ?? null;
            $this->processPublicationPayload($channel, $payload);
            return;
        }

        // Direct {channel, data} variant
        if (isset($data['channel']) && array_key_exists('data', $data)) {
            $this->processPublicationPayload((string) $data['channel'], $data['data']);
            return;
        }

        $this->out('[WS] Frame', ['data' => $data], 'debug');
    }

    /**
     * Headers for the WebSocket handshake. Public channels need no auth, and
     * Nobitex retired the legacy `Authorization: Token` scheme, so none is sent.
     * @return array<string,string>
     */
    protected function buildHandshakeHeaders(): array
    {
        return [];
    }

    /* ================================ Seeding ================================ */

    /**
     * Seed each symbol's orderbook cache from REST (NobitexService::getOrderBook,
     * primary GET /v3/orderbook/{symbol}). Bypasses the write throttle. A failure
     * for one symbol is logged and skipped — it must never stop the consumer.
     * Bounded by a time budget because the socket is already open and the
     * server's {} pings must be answered within 20s.
     * @param array<int,string> $symbols
     */
    protected function seedOrderbooks(array $symbols): void
    {
        $started = $this->nowMs();

        foreach ($symbols as $symbol) {
            $symbol = strtoupper($symbol);

            if ($this->seedBudgetSeconds > 0 && ($this->nowMs() - $started) >= $this->seedBudgetSeconds * 1000) {
                $this->out('[WS] Seed budget exhausted; skipping', ['symbol' => $symbol, 'budget_s' => $this->seedBudgetSeconds], 'warning');
                continue;
            }

            try {
                $dto = app(NobitexService::class)->getOrderBook($symbol);

                if ($dto->asks === [] && $dto->bids === [] && $dto->lastPrice <= 0) {
                    $this->out('[WS] Orderbook seed empty; skipping', ['symbol' => $symbol], 'warning');
                    continue;
                }

                $toL2 = fn(array $rows): array => array_map(
                    fn($r) => [(string) ($r['price'] ?? 0), (string) ($r['quantity'] ?? '0')],
                    $rows
                );

                $this->ingestOrderbook($symbol, [
                    'asks'           => $toL2($dto->asks),
                    'bids'           => $toL2($dto->bids),
                    'lastTradePrice' => $dto->lastPrice > 0 ? $dto->lastPrice : null,
                    'lastUpdate'     => $dto->ts > 0 ? $dto->ts * 1000 : null,
                ], true);

                $this->out('[WS] Seeded orderbook from REST', ['symbol' => $symbol]);
            } catch (\Throwable $e) {
                $this->out('[WS] Orderbook seed failed', ['symbol' => $symbol, 'error' => $e->getMessage()], 'warning');
            }
        }
    }

    /* ============================== Subscriptions ============================= */

    /**
     * Subscribe to given symbols' orderbooks.
     * @param array<int,string> $symbols
     * @return int next unused frame id
     */
    protected function subscribeOrderbooks(\WebSocket\Client $client, array $symbols): int
    {
        $i = 2; // we've used id=1 for connect
        foreach ($symbols as $symbol) {
            $channel = 'public:orderbook-' . strtoupper($symbol);

            // Per docs for non-SDK clients:
            // { "id": N, "subscribe": { "channel": "public:orderbook-BTCIRT" } }
            $frame = [
                'id' => $i++,
                'subscribe' => ['channel' => $channel],
            ];
            $client->send(json_encode($frame, JSON_UNESCAPED_SLASHES));
            $this->out('[WS] Subscribed', ['channel' => $channel]);
        }

        return $i;
    }

    /**
     * Subscribe to the configured candle channels, ids starting at $firstId.
     * Delta/fossil is deliberately NOT requested.
     * @return int next unused frame id
     */
    protected function subscribeCandles(\WebSocket\Client $client, int $firstId): int
    {
        $i = $firstId;
        foreach ($this->candleChannels() as $channel) {
            $client->send(json_encode([
                'id' => $i++,
                'subscribe' => ['channel' => $channel],
            ], JSON_UNESCAPED_SLASHES));
            $this->out('[WS] Subscribed', ['channel' => $channel]);
        }

        return $i;
    }

    /**
     * Candle channels from config: one per (symbol, resolution). Resolutions not
     * in NobitexService::OHLC_RESOLUTIONS (and malformed symbols) are skipped
     * with a warning — never fatal.
     * @return array<int,string>
     */
    protected function candleChannels(): array
    {
        $symbols     = (array) config('trading.websocket.candle_symbols', ['BTCIRT']);
        $resolutions = (array) config('trading.websocket.candle_resolutions', ['1', '15', '60', 'D']);

        $validResolutions = [];
        foreach ($resolutions as $res) {
            $res = strtoupper(trim((string) $res));
            if (!in_array($res, NobitexService::OHLC_RESOLUTIONS, true)) {
                $this->out('[WS] Invalid candle resolution in config; skipped', ['resolution' => $res], 'warning');
                continue;
            }
            $validResolutions[$res] = true;
        }

        $channels = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            if (preg_match('/^[A-Z0-9]+$/', $symbol) !== 1) {
                $this->out('[WS] Invalid candle symbol in config; skipped', ['symbol' => $symbol], 'warning');
                continue;
            }
            foreach (array_keys($validResolutions) as $res) {
                $channels[] = self::CANDLE_CHANNEL_PREFIX . $symbol . '-' . $res;
            }
        }

        return array_values(array_unique($channels));
    }

    /* ============================== Publications ============================= */

    /**
     * $payload can be:
     *  - string JSON (as docs show under push.pub.data)
     *  - array (already decoded)
     * @param mixed $payload
     */
    protected function processPublicationPayload(string $channel, $payload): void
    {
        if (strpos($channel, self::CANDLE_CHANNEL_PREFIX) === 0) {
            $this->processCandlePublication($channel, $payload);
            return;
        }

        if (strpos($channel, 'public:orderbook-') !== 0) {
            return; // ignore other channels here
        }
        $symbol = strtoupper(substr($channel, strlen('public:orderbook-')));

        $pub = $this->decodePublication($channel, $payload);
        if ($pub === null) {
            return;
        }

        $this->ingestOrderbook($symbol, $pub, false);
        $this->writeHeartbeat(self::HEARTBEAT_PUBLICATION_KEY);
    }

    /**
     * Decode a publication payload (JSON string or already-decoded array).
     * @param mixed $payload
     * @return array<string,mixed>|null null (logged) when unusable
     */
    protected function decodePublication(string $channel, $payload): ?array
    {
        if (is_string($payload)) {
            $pub = json_decode($payload, true);
            if (!is_array($pub)) {
                $this->out('[WS] Bad publication payload (string not json)', ['channel' => $channel], 'warning');
                return null;
            }
            return $pub;
        }

        if (is_array($payload)) {
            return $payload;
        }

        $this->out('[WS] Bad publication payload (unknown type)', ['channel' => $channel], 'warning');
        return null;
    }

    /* ================================ Candles ================================ */

    /**
     * Parse "public:candle-{SYMBOL}-{RESOLUTION}" by splitting on the LAST hyphen
     * (so "public:candle-BTCIRT-1D" -> BTCIRT / 1D).
     * @return array{symbol:string,resolution:string}|null null for anything malformed
     */
    public static function parseCandleChannel(string $channel): ?array
    {
        if (strpos($channel, self::CANDLE_CHANNEL_PREFIX) !== 0) {
            return null;
        }
        $rest = substr($channel, strlen(self::CANDLE_CHANNEL_PREFIX));
        $cut = strrpos($rest, '-');
        if ($cut === false) {
            return null;
        }

        $symbol     = strtoupper(substr($rest, 0, $cut));
        $resolution = strtoupper(substr($rest, $cut + 1));

        if (preg_match('/^[A-Z0-9]+$/', $symbol) !== 1
            || !in_array($resolution, NobitexService::OHLC_RESOLUTIONS, true)) {
            return null;
        }

        return ['symbol' => $symbol, 'resolution' => $resolution];
    }

    /**
     * Candle publication -> getOhlc() row -> throttled cache write. Does NOT touch
     * HEARTBEAT_PUBLICATION_KEY (that heartbeat means "orderbook publications").
     * Malformed input is logged and dropped; nothing is thrown.
     * @param mixed $payload
     */
    protected function processCandlePublication(string $channel, $payload): void
    {
        $parsed = self::parseCandleChannel($channel);
        if ($parsed === null) {
            $this->out('[WS] Unrecognised candle channel; ignored', ['channel' => $channel], 'debug');
            return;
        }

        $pub = $this->decodePublication($channel, $payload);
        if ($pub === null) {
            return;
        }

        $candle = $this->normalizeCandle($pub);
        if ($candle === null) {
            $this->out('[WS] Malformed candle payload; dropped', ['channel' => $channel], 'warning');
            return;
        }

        $this->ingestCandle($parsed['symbol'], $parsed['resolution'], $candle);
    }

    /**
     * WS candle {"t":int,"o":double,...} -> exactly the REST getOhlc() row shape.
     * @param array<string,mixed> $pub
     * @return array{t:int,o:string,h:string,l:string,c:string,v:string}|null
     */
    protected function normalizeCandle(array $pub): ?array
    {
        $t = $pub['t'] ?? null;
        if (!(is_int($t) || is_float($t) || (is_string($t) && is_numeric($t)))) {
            return null;
        }
        $tf = (float) $t;
        if (!is_finite($tf) || floor($tf) !== $tf || $tf <= 0) {
            return null;
        }

        $row = ['t' => (int) $tf];
        foreach (['o', 'h', 'l', 'c', 'v'] as $field) {
            $value = $pub[$field] ?? null;
            if (!(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
                return null;
            }
            try {
                $row[$field] = DecimalString::fromNumber($value);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return $row;
    }

    /** Update the in-memory candle and write it to cache (throttled per symbol+resolution). */
    protected function ingestCandle(string $symbol, string $resolution, array $candle): void
    {
        $key = $symbol . ':' . $resolution;

        // Never let a late/out-of-order publication regress to an older candle.
        $current = $this->candleSnap[$key]['candle']['t'] ?? null;
        if ($current !== null && $candle['t'] < $current) {
            $this->out('[WS] Stale candle ignored', ['symbol' => $symbol, 'resolution' => $resolution, 't' => $candle['t'], 'current_t' => $current], 'debug');
            return;
        }

        $this->candleSnap[$key] = ['symbol' => $symbol, 'resolution' => $resolution, 'candle' => $candle];

        if ($this->intervalElapsed($this->lastCandleWriteMs[$key] ?? null)) {
            $this->writeCandleToCache($key);
        } else {
            $this->dirtyCandles[$key] = true;
        }

        $this->out('[WS] Candle update', ['symbol' => $symbol, 'resolution' => $resolution, 't' => $candle['t']], 'debug');
    }

    protected function writeCandleToCache(string $key): void
    {
        if (!isset($this->candleSnap[$key])) {
            return;
        }
        $snap = $this->candleSnap[$key];
        $this->lastCandleWriteMs[$key] = $this->nowMs();

        try {
            Cache::store($this->cacheStore)->put(
                MarketDataLayer::candleCacheKey($snap['symbol'], $snap['resolution']),
                $snap['candle'],
                self::CANDLE_TTL_SECONDS
            );
            unset($this->dirtyCandles[$key]);
        } catch (\Throwable $e) {
            // Keep it dirty: retried on a later frame once the interval elapses.
            $this->dirtyCandles[$key] = true;
            $this->out('[WS] Candle cache write failed', ['key' => $key, 'error' => $e->getMessage()], 'warning');
        }
    }

    /**
     * Single entry point for orderbook state (WS publications AND REST seed):
     * normalizes, updates the in-memory snapshot, then writes the cache — at
     * most once per throttle interval per symbol unless $bypassThrottle.
     * A throttled symbol is marked dirty and flushed by onFrameReceived().
     * @param array<string,mixed> $pub  asks/bids/lastTradePrice(/last/lastPrice)/lastUpdate
     */
    protected function ingestOrderbook(string $symbol, array $pub, bool $bypassThrottle): void
    {
        // Normalize bids/asks as array of [price, amount] strings
        $asks = $this->normalizeL2($pub['asks'] ?? []);
        $bids = $this->normalizeL2($pub['bids'] ?? []);

        // Last price (string) -> int; or infer mid-price if absent
        $last = $pub['lastTradePrice'] ?? $pub['last'] ?? $pub['lastPrice'] ?? null;
        $lastPrice = is_numeric($last) ? (int) $last : $this->inferMidPrice($asks, $bids);

        // lastUpdate in ms; if absent, now()
        $lastUpdate = (int) ($pub['lastUpdate'] ?? $this->nowMs());

        // --- Update in-memory snapshots (always: this is the latest state)
        $this->orderbookSnap[$symbol] = [
            'asks' => $asks,
            'bids' => $bids,
            'lastTradePrice' => $lastPrice,
            'lastUpdate' => $lastUpdate,
        ];
        $this->lastPriceSnap[$symbol] = ['price' => $lastPrice, 'ts' => $this->nowSeconds()];

        if ($bypassThrottle || $this->cacheWriteDue($symbol)) {
            $this->writeSnapshotToCache($symbol);
        } else {
            $this->dirtySymbols[$symbol] = true;
        }

        $this->out('[WS] OB update', ['symbol' => $symbol, 'last' => $lastPrice], 'debug');
    }

    /** Write a symbol's current in-memory snapshot to the keys MarketDataLayer reads. */
    protected function writeSnapshotToCache(string $symbol): void
    {
        if (!isset($this->orderbookSnap[$symbol], $this->lastPriceSnap[$symbol])) {
            return;
        }

        Cache::store($this->cacheStore)->put(
            \App\Services\MarketDataLayer::CACHE_PREFIX_ORDERBOOK . $symbol,
            $this->orderbookSnap[$symbol],
            $this->ttlOrderbookSeconds
        );

        Cache::store($this->cacheStore)->put(
            \App\Services\MarketDataLayer::CACHE_PREFIX_PRICE . $symbol,
            $this->lastPriceSnap[$symbol],
            $this->ttlPriceSeconds
        );

        $this->lastCacheWriteMs[$symbol] = $this->nowMs();
        unset($this->dirtySymbols[$symbol]);
    }

    protected function cacheWriteDue(string $symbol): bool
    {
        return $this->intervalElapsed($this->lastCacheWriteMs[$symbol] ?? null);
    }

    /** True when no write happened yet or cache_write_interval_ms has passed since $lastWriteMs. */
    protected function intervalElapsed(?int $lastWriteMs): bool
    {
        if ($lastWriteMs === null) {
            return true;
        }
        return ($this->nowMs() - $lastWriteMs) >= $this->cacheWriteIntervalMs;
    }

    /* ======================== Per-frame housekeeping ======================== */

    /**
     * Called for every received frame, including empty {} pings: refreshes the
     * frame heartbeat and flushes any throttled (dirty) symbol whose interval
     * has elapsed, so the latest state is never dropped.
     */
    protected function onFrameReceived(): void
    {
        $this->writeHeartbeat(self::HEARTBEAT_FRAME_KEY);

        foreach (array_keys($this->dirtySymbols) as $symbol) {
            if ($this->cacheWriteDue($symbol)) {
                $this->writeSnapshotToCache($symbol);
            }
        }

        foreach (array_keys($this->dirtyCandles) as $key) {
            if ($this->intervalElapsed($this->lastCandleWriteMs[$key] ?? null)) {
                $this->writeCandleToCache($key);
            }
        }
    }

    /** Put a heartbeat key = now, at most once per guard window (limits DB writes). */
    protected function writeHeartbeat(string $key): void
    {
        $now = $this->nowSeconds();
        $last = $this->heartbeatWrittenAt[$key] ?? null;
        if ($last !== null && ($now - $last) < self::HEARTBEAT_WRITE_GUARD_SECONDS) {
            return;
        }

        try {
            Cache::store($this->cacheStore)->put($key, $now, self::HEARTBEAT_TTL_SECONDS);
            $this->heartbeatWrittenAt[$key] = $now;
        } catch (\Throwable $e) {
            $this->out('[WS] Heartbeat write failed', ['key' => $key, 'error' => $e->getMessage()], 'warning');
        }
    }

    /** @param array<int,mixed> $rows
     * @return array<int,array{0:string,1:string}>
     */
    protected function normalizeL2(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                if (isset($row[0], $row[1])) {
                    $out[] = [(string) $row[0], (string) $row[1]];
                } elseif (isset($row['price'], $row['amount'])) {
                    $out[] = [(string) $row['price'], (string) $row['amount']];
                }
            }
        }
        return $out;
    }

    protected function inferMidPrice(array $asks, array $bids): int
    {
        $ask = $this->rowPriceInt($asks[0] ?? null);
        $bid = $this->rowPriceInt($bids[0] ?? null);
        if ($ask === null && $bid === null) return 0;
        if ($ask === null) return $bid;
        if ($bid === null) return $ask;
        return (int) floor(($ask + $bid) / 2);
    }

    protected function rowPriceInt(?array $row): ?int
    {
        if (!$row) return null;
        $p = (int) ($row[0] ?? 0);
        return $p > 0 ? $p : null;
    }

    /* ============================== Utilities ============================== */

    /** Clock (ms). Overridable in tests. */
    protected function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** Clock (unix seconds). Overridable in tests. */
    protected function nowSeconds(): int
    {
        return time();
    }

    protected function computeBackoffWithJitter(int $attempt): int
    {
        $cfg       = (array) (config('trading.nobitex.retry') ?? []);
        $initialMs = (int) ($cfg['initial_ms'] ?? (int) config('trading.retry.initial_ms', 500));
        $maxMs     = (int) ($cfg['max_ms']     ?? (int) config('trading.retry.max_ms', 4000));
        $factor    = (float)($cfg['factor']    ?? (float) config('trading.retry.factor', 2.0));
        $jitterMs  = (int) ($cfg['jitter_ms']  ?? (int) config('trading.retry.jitter_ms', 250));

        $ms = (int) min($maxMs, $initialMs * ($factor ** max(0, $attempt - 1)));
        $ms += random_int(0, $jitterMs);
        return (int) ceil($ms / 1000);
    }

    /**
     * Small helper to print logs both to Laravel log and optionally to stdout
     */
    protected function out(string $msg, array $ctx = [], string $level = 'info'): void
    {
        $ctxOut = $ctx;
        try {
            Log::channel('nobitex')->{$level}($msg, $ctxOut);
        } catch (\Throwable $e) {
            // fallback to default log if channel missing
            Log::{$level}($msg, $ctxOut);
        }

        if ($this->debugStdout) {
            // Compact stdout without array->string notices
            $safe = json_encode($ctxOut, JSON_UNESCAPED_SLASHES);
            echo $msg . ($safe ? ' ' . $safe : '') . PHP_EOL;
        }
    }
}
