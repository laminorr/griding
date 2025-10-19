<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NobitexWebSocketService
 * -----------------------
 * WebSocket consumer for Nobitex (Centrifugo-based).
 * - Subscribes to public orderbook channels: public:orderbook-{SYMBOL}
 * - Handles ping/pong ({}) and reconnect with backoff + jitter
 * - Updates Laravel cache using keys expected by MarketDataLayer
 * - Keeps small in-memory snapshots for fast read (used by MarketDataLayer)
 *
 * Requires: composer require textalk/websocket
 */
class NobitexWebSocketService
{
    /** WebSocket endpoint (default from .env or config) */
    protected string $wsUrl;

    /** Optional connection token (not required for public channels) */
    protected ?string $token;

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

    public function __construct()
    {
        $cfg = (array) config('trading.nobitex', []);
        // Important: correct host is ws.nobitex.ir (NOT wss.nobitex.ir)
        $this->wsUrl = $cfg['websocket_url']
            ?? env('NOBITEX_WS_URL', env('WEBSOCKET_URL', 'wss://ws.nobitex.ir/connection/websocket'));

        $this->token = $cfg['api_key'] ?? null; // not needed for public channels
        $this->cacheStore = (string) config('cache.default');
        $this->ttlPriceSeconds     = (int) (config('trading.cache.price_ttl', 5));
        $this->ttlOrderbookSeconds = (int) (config('trading.cache.market_stats_ttl', 60));
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
        // Prepare headers (token not required for public channels)
        $headers = [];
        if ($this->token) {
            $headers['Authorization'] = 'Token '.$this->token;
        }

        $this->out('[WS] Connecting', [
            'url' => $this->wsUrl,
            'ssl_insecure' => false,
        ]);

        // textalk/websocket client
        $client = new \WebSocket\Client($this->wsUrl, [
            'timeout' => 25,
            'headers' => $headers,
        ]);
        $this->out('[WS] Connected OK');

        // Centrifugo connect frame. For public channels auth is optional; send empty connect {}.
        $client->send(json_encode(['connect' => (object)[], 'id' => 1], JSON_UNESCAPED_SLASHES));

        // Subscribe to orderbooks: public:orderbook-{SYMBOL}
        $this->subscribeOrderbooks($client, $symbols);

        $framesPrinted = 0;

        // Read loop
        while (true) {
            // Keep the single-instance lock fresh
            Cache::put($this->lockKey, getmypid(), $this->lockTtl);

            // Receive frame (string)
            $raw = $client->receive();

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

            // Decode; we expect JSON
            $data = json_decode($raw, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                // Could be "{}" (still valid), or a non-JSON glitch. Try to see if it's literally "{}"
                if (trim($raw) === '{}') {
                    // Ping from server – respond with {}
                    $client->send('{}');
                    continue;
                }
                $this->out('[WS] Non-JSON frame', ['raw' => $raw], 'warning');
                continue;
            }

            // If it is an empty object, treat as ping and respond "{}"
            if (is_array($data) && count($data) === 0) {
                $client->send('{}'); // pong
                continue;
            }

            // Handle Centrifugo "push" envelope (publications)
            // Example per doc:
            // { "push": { "channel": "public:orderbook-BTCIRT", "pub": { "data": "{...json...}", "offset": 123 }}}
            if (isset($data['push']['channel'])) {
                $channel = (string) $data['push']['channel'];
                $payload = $data['push']['pub']['data'] ?? null; // can be string JSON or already array
                $this->processPublicationPayload($channel, $payload);
                continue;
            }

            // Some impls may send direct {channel, data}
            if (isset($data['channel']) && array_key_exists('data', $data)) {
                $this->processPublicationPayload((string) $data['channel'], $data['data']);
                continue;
            }

            // Unknown frame – log at debug
            $this->out('[WS] Frame', ['data' => $data], 'debug');
        }
    }

    /* ============================== Subscriptions ============================= */

    /**
     * Subscribe to given symbols' orderbooks.
     * @param array<int,string> $symbols
     */
    protected function subscribeOrderbooks(\WebSocket\Client $client, array $symbols): void
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
        if (strpos($channel, 'public:orderbook-') !== 0) {
            return; // ignore other channels here
        }
        $symbol = strtoupper(substr($channel, strlen('public:orderbook-')));

        // Decode or accept as-is
        if (is_string($payload)) {
            $pub = json_decode($payload, true);
            if (!is_array($pub)) {
                $this->out('[WS] Bad publication payload (string not json)', ['channel' => $channel], 'warning');
                return;
            }
        } elseif (is_array($payload)) {
            $pub = $payload;
        } else {
            $this->out('[WS] Bad publication payload (unknown type)', ['channel' => $channel], 'warning');
            return;
        }

        // Normalize bids/asks as array of [price, amount] strings
        $asks = $this->normalizeL2($pub['asks'] ?? []);
        $bids = $this->normalizeL2($pub['bids'] ?? []);

        // Last price (string) -> int; or infer mid-price if absent
        $last = $pub['lastTradePrice'] ?? $pub['last'] ?? $pub['lastPrice'] ?? null;
        $lastPrice = is_numeric($last) ? (int) $last : $this->inferMidPrice($asks, $bids);

        // lastUpdate in ms; if absent, now()
        $lastUpdate = (int) ($pub['lastUpdate'] ?? (int) round(microtime(true) * 1000));

        // --- Write to cache with keys MarketDataLayer expects
        Cache::store($this->cacheStore)->put(
            \App\Services\MarketDataLayer::CACHE_PREFIX_ORDERBOOK . $symbol,
            [
                'asks' => $asks,
                'bids' => $bids,
                'lastTradePrice' => $lastPrice,
                'lastUpdate' => $lastUpdate,
            ],
            $this->ttlOrderbookSeconds
        );

        Cache::store($this->cacheStore)->put(
            \App\Services\MarketDataLayer::CACHE_PREFIX_PRICE . $symbol,
            ['price' => $lastPrice, 'ts' => time()],
            $this->ttlPriceSeconds
        );

        // --- Update in-memory snapshots
        $this->orderbookSnap[$symbol] = [
            'asks' => $asks,
            'bids' => $bids,
            'lastTradePrice' => $lastPrice,
            'lastUpdate' => $lastUpdate,
        ];
        $this->lastPriceSnap[$symbol] = ['price' => $lastPrice, 'ts' => time()];

        $this->out('[WS] OB update', ['symbol' => $symbol, 'last' => $lastPrice], 'debug');
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
