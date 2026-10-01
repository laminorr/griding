<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WsFrameClient;
use App\Exceptions\PrivateWsReconnectException;
use App\Models\ExchangeWsEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NobitexPrivateWsService
 * -----------------------
 * Consumer for the Nobitex PRIVATE WebSocket channels (Centrifugo): the user's
 * own order and trade events on private:orders#{websocketAuthParam} and
 * private:trades#{websocketAuthParam}. OBSERVE-ONLY (W3): every event is handed
 * to ExchangeWsEventRecorder and stored; nothing here trades, touches a grid
 * order or creates a pair order.
 *
 * Deliberately a SEPARATE process from the public market-data consumer
 * (NobitexWebSocketService / nobitex:ws-consumer), which is left untouched.
 *
 * Centrifugo JSON protocol (centrifugal/protocol definitions/client.proto):
 *  - connect:   {"id":1,"connect":{"token":"<JWT>"}}
 *               reply {"id":1,"connect":{"client":..,"expires":true,"ttl":1200,..}}
 *  - subscribe: {"id":N,"subscribe":{"channel":"private:orders#<param>"}}
 *               error reply {"id":N,"error":{"code":103,"message":"permission denied"}}
 *  - refresh:   {"id":N,"refresh":{"token":"<new JWT>"}}
 *               reply {"id":N,"refresh":{"client":..,"expires":true,"ttl":1200}}
 *  - push:      {"push":{"channel":..,"pub":{"data":..}}}
 *               {"push":{"disconnect":{"code":3005,"reason":"connection expired"}}}
 *  - ping/pong: server sends {} and expects {} back within the ping window.
 * Delta/fossil is NOT requested.
 *
 * The token is refreshed REFRESH_MARGIN_SECONDS before the ttl from the connect
 * (or previous refresh) reply runs out. A failed refresh, an expired token, a
 * server disconnect or a closed socket drops the connection; run() reconnects
 * with a fresh token after backoff.
 *
 * SECURITY: the token is never logged; the websocketAuthParam (and channel
 * names, which embed it) are only ever logged masked.
 */
class NobitexPrivateWsService
{
    public const HEARTBEAT_FRAME_KEY = 'nobitex:ws:private:last_frame_at';
    public const LOCK_KEY            = 'nobitex:ws:private:lock';

    public const ORDERS_CHANNEL_PREFIX = 'private:orders#';
    public const TRADES_CHANNEL_PREFIX = 'private:trades#';

    /** Refresh this long before the connection token's ttl runs out. */
    public const REFRESH_MARGIN_SECONDS = 120;

    protected const HEARTBEAT_WRITE_GUARD_SECONDS = 10;
    /**
     * Long TTL on purpose: WsFeedHealthCheck treats a MISSING key as "feature
     * not deployed", so a dead consumer's last heartbeat must outlive any
     * realistic outage to keep alerting.
     */
    protected const HEARTBEAT_TTL_SECONDS = 30 * 86400;

    protected const LOCK_TTL_SECONDS = 60;

    protected string $wsUrl;
    protected bool $debugStdout = false;

    /* ---- per-connection state (reset on every connect) ---- */
    protected int $nextId = 1;
    /** @var array<int,array{type:string,channel?:string}> pending command id => what it was */
    protected array $pending = [];
    protected ?int $expiresAt = null;
    protected ?int $refreshAt = null;
    protected ?int $refreshInFlightId = null;
    protected bool $established = false;
    protected string $ordersChannel = '';
    protected string $tradesChannel = '';

    protected ?int $heartbeatWrittenAt = null;

    public function __construct(
        protected NobitexService $api,
        protected ExchangeWsEventRecorder $recorder,
    ) {
        $cfg = (array) config('trading.nobitex', []);
        $this->wsUrl = (string) ($cfg['websocket_url']
            ?? env('NOBITEX_WS_URL', env('WEBSOCKET_URL', 'wss://ws.nobitex.ir/connection/websocket')));
    }

    public function enableStdout(bool $enable): void
    {
        $this->debugStdout = $enable;
    }

    /* ============================== Main runner =============================== */

    /** Blocking run (artisan nobitex:ws-private). Single instance via cache lock. */
    public function run(bool $force = false): void
    {
        $this->out('[WS-PRIVATE] Run loop start', ['force' => $force]);

        if (!$force && !Cache::add(self::LOCK_KEY, getmypid(), self::LOCK_TTL_SECONDS)) {
            $this->out('[WS-PRIVATE] Another private consumer already running; abort.');
            return;
        }

        $attempt = 0;
        while ($this->keepRunning()) {
            try {
                $attempt++;
                $this->consume();
                $attempt = 0;
            } catch (\Throwable $e) {
                if ($this->established) {
                    $attempt = 1; // a healthy connection dropped: restart the backoff ladder
                }
                $wait = $this->computeBackoffWithJitter($attempt);
                $level = $e instanceof PrivateWsReconnectException ? 'warning' : 'error';
                $this->out('[WS-PRIVATE] Connection dropped; reconnecting', [
                    'error' => $e->getMessage(), 'attempt' => $attempt, 'wait' => $wait,
                ], $level);
                $this->sleepSeconds($wait);
            } finally {
                Cache::put(self::LOCK_KEY, getmypid(), self::LOCK_TTL_SECONDS);
            }
        }
    }

    /* ============================ Connection lifecycle =========================== */

    /** One connection: fresh token -> connect -> subscribe both channels -> read loop. */
    protected function consume(): void
    {
        $this->resetConnectionState();

        // Credentials first: a failure here never opens a socket.
        $token = $this->api->getWebsocketToken();
        $param = $this->api->getWebsocketAuthParam();
        $this->ordersChannel = self::ORDERS_CHANNEL_PREFIX . $param;
        $this->tradesChannel = self::TRADES_CHANNEL_PREFIX . $param;

        $this->out('[WS-PRIVATE] Connecting', ['url' => $this->wsUrl, 'auth_param' => self::mask($param)]);
        $client = $this->openClient();

        try {
            $this->sendCommand($client, ['connect' => ['token' => $token]], ['type' => 'connect']);
            unset($token);

            foreach ([$this->ordersChannel, $this->tradesChannel] as $channel) {
                $this->sendCommand($client, ['subscribe' => ['channel' => $channel]], ['type' => 'subscribe', 'channel' => $channel]);
                $this->out('[WS-PRIVATE] Subscribe sent', ['channel' => self::maskChannel($channel)]);
            }

            while (true) {
                Cache::put(self::LOCK_KEY, getmypid(), self::LOCK_TTL_SECONDS);

                $raw = $client->receive();
                $this->writeHeartbeat();

                if ($raw === null || $raw === '') {
                    if (!$client->isConnected()) {
                        throw new PrivateWsReconnectException('Socket closed by server');
                    }
                    $client->send('{}');
                } else {
                    // Centrifugo may batch several JSON replies per frame, \n-separated.
                    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        $data = json_decode($line, true);
                        if (!is_array($data)) {
                            $this->out('[WS-PRIVATE] Non-JSON line ignored', ['bytes' => strlen($line)], 'warning');
                            continue;
                        }
                        $this->handleMessage($data, $client);
                    }
                }

                $this->maybeRefresh($client);
            }
        } finally {
            try {
                $client->close();
            } catch (\Throwable) {
                // already gone
            }
        }
    }

    protected function resetConnectionState(): void
    {
        $this->nextId = 1;
        $this->pending = [];
        $this->expiresAt = null;
        $this->refreshAt = null;
        $this->refreshInFlightId = null;
        $this->established = false;
    }

    /** @param array{type:string,channel?:string} $meta */
    protected function sendCommand(WsFrameClient $client, array $body, array $meta): int
    {
        $id = $this->nextId++;
        $this->pending[$id] = $meta;
        $client->send(json_encode(['id' => $id] + $body, JSON_UNESCAPED_SLASHES));
        return $id;
    }

    /* ============================== Frame handling ============================== */

    protected function handleMessage(array $data, WsFrameClient $client): void
    {
        // {} = server ping -> pong
        if ($data === []) {
            $client->send('{}');
            return;
        }

        if (isset($data['push']) && is_array($data['push'])) {
            $this->handlePush($data['push']);
            return;
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            $this->out('[WS-PRIVATE] Unrecognised frame ignored', ['keys' => array_keys($data)], 'debug');
            return;
        }

        $meta = $this->pending[$id] ?? ['type' => 'unknown'];
        unset($this->pending[$id]);

        if (isset($data['error'])) {
            $this->handleErrorReply($id, $meta, (array) $data['error']);
            return;
        }

        switch ($meta['type']) {
            case 'connect':
                $this->established = true;
                $this->applyExpiry((array) ($data['connect'] ?? []));
                $this->out('[WS-PRIVATE] Connected', ['ttl' => $data['connect']['ttl'] ?? null]);
                break;
            case 'subscribe':
                $this->out('[WS-PRIVATE] Subscribed', ['channel' => self::maskChannel($meta['channel'] ?? '')]);
                break;
            case 'refresh':
                $this->refreshInFlightId = null;
                $this->applyExpiry((array) ($data['refresh'] ?? []));
                $this->out('[WS-PRIVATE] Token refreshed', ['ttl' => $data['refresh']['ttl'] ?? null]);
                break;
        }
    }

    protected function handlePush(array $push): void
    {
        if (isset($push['disconnect'])) {
            $d = (array) $push['disconnect'];
            throw new PrivateWsReconnectException(sprintf(
                'Server disconnect (code=%s reason=%s)',
                (string) ($d['code'] ?? '?'),
                (string) ($d['reason'] ?? '')
            ));
        }

        $channel = (string) ($push['channel'] ?? '');

        if (isset($push['unsubscribe'])) {
            $this->out('WS_PRIVATE_UNSUBSCRIBED', [
                'channel' => self::maskChannel($channel),
                'code'    => $push['unsubscribe']['code'] ?? null,
                'reason'  => $push['unsubscribe']['reason'] ?? null,
            ], 'critical');
            throw new PrivateWsReconnectException('Server unsubscribed a private channel');
        }

        if (!isset($push['pub']) || !is_array($push['pub'])) {
            return; // join/leave/message/etc: not used
        }
        $payload = $push['pub']['data'] ?? null;

        if ($channel === $this->ordersChannel) {
            $this->recorder->record(ExchangeWsEvent::CHANNEL_ORDERS, $payload);
        } elseif ($channel === $this->tradesChannel) {
            $this->recorder->record(ExchangeWsEvent::CHANNEL_TRADES, $payload);
        } else {
            $this->out('[WS-PRIVATE] Publication on unexpected channel ignored', ['channel' => self::maskChannel($channel)], 'warning');
        }
    }

    /** @param array{type:string,channel?:string} $meta */
    protected function handleErrorReply(int $id, array $meta, array $error): void
    {
        $ctx = [
            'id'      => $id,
            'code'    => $error['code'] ?? null,
            'message' => $error['message'] ?? null,
        ];

        switch ($meta['type']) {
            case 'subscribe':
                // Permission denied etc. Reconnecting would not help and would
                // hammer the token endpoint: alert loudly and stay connected.
                $this->out('WS_PRIVATE_SUBSCRIBE_FAILED', $ctx + ['channel' => self::maskChannel($meta['channel'] ?? '')], 'critical');
                return;
            case 'connect':
                $this->out('WS_PRIVATE_CONNECT_FAILED', $ctx, 'critical');
                throw new PrivateWsReconnectException('Connect rejected (code=' . ($ctx['code'] ?? '?') . ')');
            case 'refresh':
                $this->refreshInFlightId = null;
                $this->out('WS_PRIVATE_REFRESH_FAILED', $ctx, 'error');
                throw new PrivateWsReconnectException('Token refresh rejected (code=' . ($ctx['code'] ?? '?') . ')');
            default:
                $this->out('[WS-PRIVATE] Error reply', $ctx, 'warning');
        }
    }

    /* ============================== Token refresh ============================== */

    /** Arm expiry/refresh deadlines from a connect or refresh result. */
    protected function applyExpiry(array $result): void
    {
        $ttl = (int) ($result['ttl'] ?? 0);
        if (empty($result['expires']) || $ttl <= 0) {
            $this->expiresAt = null;
            $this->refreshAt = null;
            return;
        }

        $now = $this->nowSeconds();
        $lead = $ttl > self::REFRESH_MARGIN_SECONDS ? $ttl - self::REFRESH_MARGIN_SECONDS : intdiv($ttl, 2);
        $this->expiresAt = $now + $ttl;
        $this->refreshAt = $now + max(1, $lead);
    }

    protected function maybeRefresh(WsFrameClient $client): void
    {
        $now = $this->nowSeconds();

        if ($this->expiresAt !== null && $now >= $this->expiresAt) {
            throw new PrivateWsReconnectException('Connection token expired before a refresh succeeded');
        }

        if ($this->refreshAt === null || $now < $this->refreshAt || $this->refreshInFlightId !== null) {
            return;
        }

        try {
            $token = $this->api->getWebsocketToken();
        } catch (\Throwable $e) {
            $this->out('WS_PRIVATE_REFRESH_FAILED', ['stage' => 'token_fetch', 'error' => $e->getMessage()], 'error');
            throw new PrivateWsReconnectException('Token refresh failed: could not fetch a new token', 0, $e);
        }

        $this->refreshInFlightId = $this->sendCommand($client, ['refresh' => ['token' => $token]], ['type' => 'refresh']);
        $this->out('[WS-PRIVATE] Token refresh sent', ['id' => $this->refreshInFlightId]);
    }

    /* ================================ Heartbeat ================================ */

    protected function writeHeartbeat(): void
    {
        $now = $this->nowSeconds();
        if ($this->heartbeatWrittenAt !== null && ($now - $this->heartbeatWrittenAt) < self::HEARTBEAT_WRITE_GUARD_SECONDS) {
            return;
        }

        try {
            Cache::put(self::HEARTBEAT_FRAME_KEY, $now, self::HEARTBEAT_TTL_SECONDS);
            $this->heartbeatWrittenAt = $now;
        } catch (\Throwable $e) {
            $this->out('[WS-PRIVATE] Heartbeat write failed', ['error' => $e->getMessage()], 'warning');
        }
    }

    /* ================================ Utilities ================================ */

    /** First 4 chars + ellipsis. Never log a secret any other way. */
    public static function mask(string $secret): string
    {
        return $secret === '' ? '' : mb_substr($secret, 0, 4) . '…';
    }

    public static function maskChannel(string $channel): string
    {
        $hash = strpos($channel, '#');
        return $hash === false ? $channel : substr($channel, 0, $hash + 1) . self::mask(substr($channel, $hash + 1));
    }

    /** Socket factory. Overridden in tests with a scripted fake. */
    protected function openClient(): WsFrameClient
    {
        $ws = new \WebSocket\Client($this->wsUrl, ['timeout' => 25, 'headers' => []]);

        return new class($ws) implements WsFrameClient {
            public function __construct(private \WebSocket\Client $ws)
            {
            }

            public function send(string $payload): void
            {
                $this->ws->send($payload);
            }

            public function receive(): ?string
            {
                $m = $this->ws->receive();
                return is_string($m) ? $m : null;
            }

            public function isConnected(): bool
            {
                return $this->ws->isConnected();
            }

            public function close(): void
            {
                if ($this->ws->isConnected()) {
                    $this->ws->close();
                }
            }
        };
    }

    /** Loop guard. Overridable in tests. */
    protected function keepRunning(): bool
    {
        return true;
    }

    /** Overridable in tests. */
    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
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
        $ms += random_int(0, max(0, $jitterMs));
        return max(1, (int) ceil($ms / 1000));
    }

    protected function out(string $msg, array $ctx = [], string $level = 'info'): void
    {
        try {
            Log::channel('nobitex')->{$level}($msg, $ctx);
        } catch (\Throwable) {
            Log::{$level}($msg, $ctx);
        }

        if ($this->debugStdout) {
            $safe = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo $msg . ($safe ? ' ' . $safe : '') . PHP_EOL;
        }
    }
}
