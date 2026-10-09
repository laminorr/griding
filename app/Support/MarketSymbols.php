<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\BotConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Which market symbols the background loops (market-stats heartbeat, public
 * WS consumer) may touch.
 *
 * MarketDataLayer refuses any symbol outside trading.exchange.allowed_symbols
 * (env TRADING_SYMBOLS_ALLOWED), so looping a disallowed one only produces a
 * failure line every minute. Callers filter through here first.
 */
final class MarketSymbols
{
    /** Cache key prefix for the once-a-day "skipped" info line, per symbol. */
    public const SKIP_LOGGED_PREFIX = 'market_symbols:skip_logged:';

    /** @return list<string> trading.exchange.allowed_symbols, upper-cased, de-duplicated */
    public static function allowed(): array
    {
        return self::normalize((array) config('trading.exchange.allowed_symbols', []));
    }

    /**
     * Split $symbols into those in allowed_symbols and those not (order kept,
     * upper-cased, blanks and duplicates dropped).
     *
     * @param  array<int,mixed> $symbols
     * @return array{allowed:list<string>,skipped:list<string>}
     */
    public static function partitionAllowed(array $symbols): array
    {
        $allowed = array_flip(self::allowed());
        $in = $out = [];
        foreach (self::normalize($symbols) as $s) {
            if (isset($allowed[$s])) {
                $in[] = $s;
            } else {
                $out[] = $s;
            }
        }

        return ['allowed' => $in, 'skipped' => $out];
    }

    /** The symbol the monitoring chart shows when no bot is selected. */
    public static function defaultChartSymbol(): string
    {
        $s = strtoupper(trim((string) (config('trading.websocket.candle_symbols')[0] ?? 'BTCIRT')));

        return $s !== '' ? $s : 'BTCIRT';
    }

    /**
     * Symbols the market-stats heartbeat should read: those actually in use
     * (active bots' symbols + the default chart symbol) AND allowed. A symbol
     * in use but not allowed is skipped silently, with at most one INFO line
     * per symbol per day. Never throws (DB down → default chart symbol only).
     *
     * @return list<string>
     */
    public static function marketStatsSymbols(): array
    {
        $inUse = [];
        try {
            $inUse = BotConfig::query()->active()->distinct()->pluck('symbol')->all();
        } catch (\Throwable) {
            // no table / DB down: fall back to the chart symbol below
        }
        $inUse[] = self::defaultChartSymbol();

        ['allowed' => $allowed, 'skipped' => $skipped] = self::partitionAllowed($inUse);

        foreach ($skipped as $s) {
            self::logSkippedOncePerDay($s);
        }

        return $allowed;
    }

    private static function logSkippedOncePerDay(string $symbol): void
    {
        try {
            $key = self::SKIP_LOGGED_PREFIX . $symbol . ':' . date('Y-m-d');
            if (Cache::add($key, 1, 2 * 86400)) {
                Log::channel('trading')->info('MARKET_STATS_SKIPPED_NOT_ALLOWED', [
                    'symbol' => $symbol,
                    'reason' => 'in use but not in trading.exchange.allowed_symbols',
                ]);
            }
        } catch (\Throwable) {
            // logging is best-effort
        }
    }

    /**
     * @param  array<int,mixed> $symbols
     * @return list<string>
     */
    private static function normalize(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $s) {
            $s = strtoupper(trim((string) $s));
            if ($s !== '') {
                $out[$s] = true;
            }
        }

        return array_keys($out);
    }
}
