<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\NobitexPrivateWsService;
use App\Services\NobitexWebSocketService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WsFeedHealthCheck
 * ------------------------------------------------------------------------
 * Makes a silently dead WebSocket market-data feed visible. The keepalive cron
 * (scripts/ws-keepalive.sh) only restarts nobitex:ws-consumer when the process
 * is gone; a process that is alive but stuck — or connected but receiving no
 * orderbook publications — would otherwise go unnoticed while MarketDataLayer
 * quietly falls back to REST.
 *
 * The consumer writes two heartbeat keys (NobitexWebSocketService::HEARTBEAT_*):
 * the last time ANY frame arrived (including {} pings) and the last time an
 * orderbook publication was processed. This guard compares their age against
 * config('trading.websocket.health.*') and LOGS only — no restarts, no other
 * side effects. Scheduled INLINE via Schedule::call() in routes/console.php,
 * and never throws out of schedule:run.
 *
 * W3: also watches the private order/trade consumer (nobitex:ws-private) via
 * NobitexPrivateWsService::HEARTBEAT_FRAME_KEY — but ONLY when that key exists
 * (missing = feature not deployed → silent). Stale beyond the same dead
 * threshold → CRITICAL WS_PRIVATE_DEAD. This is independent of, and never
 * changes, the public-feed result that check() returns.
 */
final class WsFeedHealthCheck
{
    public const OK         = 'ok';
    public const DEAD       = 'WS_FEED_DEAD';
    public const SILENT     = 'WS_FEED_SILENT';
    public const NEVER_SEEN = 'WS_FEED_NEVER_SEEN';
    public const PRIVATE_DEAD = 'WS_PRIVATE_DEAD';

    /**
     * Run the check. Returns the observed status, or null when the check could
     * not run (cache error — swallowed so a scheduler tick is never brought
     * down by the guard).
     */
    public function check(?int $now = null): ?string
    {
        $now ??= time();

        $status = $this->checkPublic($now);

        // null = cache/log unavailable, already reported by the public check.
        if ($status !== null) {
            $this->checkPrivate($now);
        }

        return $status;
    }

    /**
     * Private consumer heartbeat. Returns null when the key is absent (not
     * deployed — nothing logged) or the check could not run; OK or
     * PRIVATE_DEAD otherwise. Never throws.
     */
    public function checkPrivate(?int $now = null): ?string
    {
        try {
            $now ??= time();

            $frameAt = Cache::get(NobitexPrivateWsService::HEARTBEAT_FRAME_KEY);
            if (!is_numeric($frameAt)) {
                return null;
            }

            $age = $now - (int) $frameAt;
            $deadAfter = self::deadAfterSeconds();
            if ($age > $deadAfter) {
                Log::channel('queue')->critical(self::PRIVATE_DEAD, [
                    'last_frame_at'     => (int) $frameAt,
                    'age_seconds'       => $age,
                    'threshold_seconds' => $deadAfter,
                    'hint' => 'No frame on the private order/trade WebSocket (nobitex:ws-private). '
                        . 'Is scripts/ws-private-keepalive.sh in cron? Check storage/logs/ws-private.log.',
                ]);

                return self::PRIVATE_DEAD;
            }

            return self::OK;
        } catch (Throwable $e) {
            try {
                Log::channel('queue')->warning('WS_PRIVATE_CHECK_FAILED', [
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // never take down the scheduler tick
            }

            return null;
        }
    }

    private function checkPublic(int $now): ?string
    {
        try {
            $frameAt = Cache::get(NobitexWebSocketService::HEARTBEAT_FRAME_KEY);
            $pubAt   = Cache::get(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY);

            $deadAfter   = self::deadAfterSeconds();
            $silentAfter = self::silentAfterSeconds();

            // Not CRITICAL: dev/test environments run no consumer at all.
            if (!is_numeric($frameAt)) {
                Log::channel('queue')->warning(self::NEVER_SEEN, [
                    'hint' => 'No heartbeat from nobitex:ws-consumer has ever been recorded '
                        . '(or it expired). Is the consumer running?',
                ]);

                return self::NEVER_SEEN;
            }

            $frameAge = $now - (int) $frameAt;
            if ($frameAge > $deadAfter) {
                Log::channel('queue')->critical(self::DEAD, [
                    'last_frame_at'     => (int) $frameAt,
                    'age_seconds'       => $frameAge,
                    'threshold_seconds' => $deadAfter,
                    'hint' => 'No WebSocket frame (not even a ping) received. The consumer '
                        . 'is hung or disconnected; market data is falling back to REST.',
                ]);

                return self::DEAD;
            }

            $pubAge = is_numeric($pubAt) ? $now - (int) $pubAt : null;
            if ($pubAge === null || $pubAge > $silentAfter) {
                Log::channel('queue')->warning(self::SILENT, [
                    'last_frame_at'       => (int) $frameAt,
                    'last_publication_at' => is_numeric($pubAt) ? (int) $pubAt : null,
                    'age_seconds'         => $pubAge,
                    'threshold_seconds'   => $silentAfter,
                    'hint' => 'Connection is alive but no orderbook publications arrive. '
                        . 'Check subscriptions on nobitex:ws-consumer.',
                ]);

                return self::SILENT;
            }

            return self::OK;
        } catch (Throwable $e) {
            try {
                Log::channel('queue')->warning('WS_FEED_CHECK_FAILED', [
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // A guard must never take down the scheduler tick it rides on.
            }

            return null;
        }
    }

    public static function deadAfterSeconds(): int
    {
        return (int) config('trading.websocket.health.dead_after_seconds', 180);
    }

    public static function silentAfterSeconds(): int
    {
        return (int) config('trading.websocket.health.silent_after_seconds', 600);
    }
}
