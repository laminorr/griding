<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\NobitexService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Market precision — the ONE source of a symbol's quantity step and price tick.
 *
 * Authoritative source: Nobitex GET /v2/options (NobitexService::getOptionsV2)
 *   nobitex.amountPrecisions  {"BTCIRT":"0.000001", ...}  → qty decimals (6)
 *   nobitex.pricePrecisions   {"BTCIRT":"10", ...}        → price tick (10)
 *
 * Resolution chain (per symbol, per field):
 *   1. live map (parsed, cached 6h)
 *   2. last-known-good map (same shape, no TTL — survives an exchange outage)
 *   3. config: trading.exchange.precision.{SYM}.qty_decimals / trading.ticks.{SYM}
 *
 * This class NEVER throws and never blocks order placement: every failure
 * (network, signing, malformed payload, cache error) degrades to the next
 * step of the chain and logs PRECISION_FALLBACK. A failed fetch is not
 * retried for FAIL_BACKOFF_SECONDS, so an outage costs one HTTP attempt per
 * minute, not one per order.
 *
 * IRT prices are whole rials (DECIMAL(20,0)); a non-integer tick for an IRT
 * symbol is invalid and ignored (PRECISION_INVALID). See docs/market-precision.md.
 *
 * Log scope: every symbol is parsed, but PRECISION_INVALID and the
 * symbol-missing PRECISION_FALLBACK are only logged for symbols this system
 * trades (trading.exchange.allowed_symbols + any bot_configs.symbol). Invalid
 * entries for other symbols (sub-rial meme-coin IRT ticks) are rolled into one
 * debug line per parse.
 */
final class MarketPrecision
{
    public const LIVE_CACHE_KEY      = 'market_precision:v1:live';
    public const LKG_CACHE_KEY       = 'market_precision:v1:last_known_good';
    public const FAIL_CACHE_KEY      = 'market_precision:v1:fail_backoff';
    public const LIVE_TTL_SECONDS    = 21600; // 6h
    public const FAIL_BACKOFF_SECONDS = 60;

    /** How long the relevant-symbol set is reused before bot_configs is re-read. */
    public const RELEVANT_SYMBOLS_TTL_SECONDS = 60;

    public const DEFAULT_QTY_DECIMALS = 8;
    public const DEFAULT_TICK         = 10;

    public const SIDE_BUY  = 'buy';
    public const SIDE_SELL = 'sell';

    /** @var array{at:int, config:array, symbols:array<string,true>}|null */
    private static ?array $relevant = null;

    /** Quantity decimals for a symbol (step 10^-n). */
    public static function qtyDecimals(string $symbol): int
    {
        $sym  = QtyPrecision::canonicalSymbol($symbol);
        $live = self::resolved($sym, 'qty');

        $cfg = self::configQtyDecimals($sym);
        if ($live === null) {
            return $cfg ?? self::DEFAULT_QTY_DECIMALS;
        }
        if ($cfg !== null && $cfg !== $live) {
            self::logDrift($sym, 'qty_decimals', $live, $cfg);
        }
        return $live;
    }

    /** Integer price tick for a symbol (whole rials for IRT markets). */
    public static function priceTick(string $symbol): int
    {
        $sym  = QtyPrecision::canonicalSymbol($symbol);
        $live = self::resolved($sym, 'tick');

        $cfg = self::configTick($sym);
        if ($live === null) {
            return $cfg ?? self::DEFAULT_TICK;
        }
        if ($cfg !== null && $cfg !== $live) {
            self::logDrift($sym, 'tick', $live, $cfg);
        }
        return $live;
    }

    /**
     * Fit a price to the market tick in the SIDE-SAFE direction:
     * buy → DOWN (never pay more), sell → UP (never sell cheaper). Exact
     * bcmath on the decimal string — no float round-trip. Returns whole rials.
     */
    public static function roundPrice(string|int $price, string $symbol, string $side): int
    {
        $tick = (string) max(1, self::priceTick($symbol));
        return self::alignToTick((string) $price, $tick, strtolower($side) === self::SIDE_SELL);
    }

    /**
     * Send-boundary safety net (NobitexService::placeOrder,
     * CreateOrderDto::toApiPayload). An off-tick price is fitted side-safely
     * and ORDER_PRICE_ROUNDED is logged. Callers are expected to have aligned
     * the price already (planner / exit path), so this should never fire.
     */
    public static function priceForSend(string|int $price, string $symbol, string $side): string
    {
        $rounded = (string) self::roundPrice($price, $symbol, $side);
        if (Money::compare(Money::normalize((string) $price), $rounded) !== 0) {
            self::warn('ORDER_PRICE_ROUNDED', [
                'symbol'    => QtyPrecision::canonicalSymbol($symbol),
                'side'      => strtolower($side),
                'requested' => (string) $price,
                'sent'      => $rounded,
                'tick'      => self::priceTick($symbol),
                'note'      => 'Off-tick price reached the send boundary; the caller should have aligned it.',
            ]);
        }
        return $rounded;
    }

    /** True when $price is a whole number on the market tick. */
    public static function isOnTick(string|int $price, string $symbol): bool
    {
        $p = Money::normalize((string) $price);
        if (Money::compare($p, Money::floorToScale($p, 0)) !== 0) {
            return false;
        }
        $tick = (string) max(1, self::priceTick($symbol));
        return bcmod(Money::floorToScale($p, 0), $tick, 0) === '0';
    }

    /**
     * Align $price to a multiple of $tick: up when $up, else down. Shared by
     * roundPrice() and the planners' roundToTick() helpers.
     */
    public static function alignToTick(string $price, string $tick, bool $up): int
    {
        $tick = max(1, (int) $tick);
        $q    = Money::div(Money::normalize($price), (string) $tick, Money::DEFAULT_SCALE);
        $q    = $up ? Money::ceilToScale($q, 0) : Money::floorToScale($q, 0);
        return (int) Money::mul($q, (string) $tick, 0);
    }

    /**
     * Parse an amount step string ("0.000001" → 6, "1" → 0). Only powers of
     * ten ≤ 1 are expressible as a decimal count; anything else → null.
     */
    public static function parseQtyStep(mixed $step): ?int
    {
        if (! is_string($step) && ! is_int($step)) {
            return null; // floats are refused on purpose: no binary round-trip
        }
        $s = trim((string) $step);
        if (! preg_match('/^\d+(\.\d+)?$/', $s)) {
            return null;
        }
        $s = Money::trimZeros($s);
        if ($s === '1') {
            return 0;
        }
        if (preg_match('/^0\.(0*)1$/', $s, $m)) {
            $dec = strlen($m[1]) + 1;
            return $dec <= 18 ? $dec : null;
        }
        return null;
    }

    /** Parse a price tick string ("10" → 10). Non-integer or ≤ 0 → null. */
    public static function parseTick(mixed $tick): ?int
    {
        if (! is_string($tick) && ! is_int($tick)) {
            return null;
        }
        $s = trim((string) $tick);
        if (! preg_match('/^\d+(\.0+)?$/', $s)) {
            return null;
        }
        $i = (int) Money::floorToScale($s, 0);
        return $i > 0 ? $i : null;
    }

    /**
     * Parse the options payload into ['qty' => [SYM => int], 'tick' => [SYM => int]].
     * Returns null when neither precision map is present/usable.
     *
     * @param array<string,mixed> $nobitex  getOptionsV2()['nobitex']
     * @return array{qty: array<string,int>, tick: array<string,int>}|null
     */
    public static function parseOptions(array $nobitex): ?array
    {
        $out = ['qty' => [], 'tick' => []];
        $skipped = 0;

        foreach ((array) ($nobitex['amountPrecisions'] ?? []) as $sym => $step) {
            $dec = self::parseQtyStep($step);
            $key = QtyPrecision::canonicalSymbol((string) $sym);
            if ($dec === null) {
                if (self::isRelevantSymbol($key)) {
                    self::logInvalid($key, 'amountPrecision', $step);
                } else {
                    $skipped++;
                }
                continue;
            }
            $out['qty'][$key] = $dec;
        }

        foreach ((array) ($nobitex['pricePrecisions'] ?? []) as $sym => $tick) {
            $key = QtyPrecision::canonicalSymbol((string) $sym);
            $int = self::parseTick($tick);
            if ($int === null) {
                // Only IRT prices are integer-constrained in this system; a
                // fractional tick on e.g. BTCUSDT is legitimate there but not
                // usable here, so it is skipped quietly. On IRT it is invalid.
                if (str_ends_with($key, 'IRT')) {
                    if (self::isRelevantSymbol($key)) {
                        self::logInvalid($key, 'pricePrecision', $tick);
                    } else {
                        $skipped++;
                    }
                }
                continue;
            }
            $out['tick'][$key] = $int;
        }

        if ($skipped > 0) {
            try {
                Log::channel('trading')->debug('PRECISION_INVALID_SKIPPED', [
                    'count' => $skipped,
                    'note'  => 'Invalid precision entries for symbols no bot trades; ignored.',
                ]);
            } catch (\Throwable) {
                // never throw
            }
        }

        return ($out['qty'] === [] && $out['tick'] === []) ? null : $out;
    }

    /** Drop the cached live map (the last-known-good copy is kept). */
    public static function forgetLive(): void
    {
        self::$relevant = null;
        try {
            Cache::forget(self::LIVE_CACHE_KEY);
            Cache::forget(self::FAIL_CACHE_KEY);
        } catch (\Throwable) {
            // never throw
        }
    }

    /* ------------------------------------------------------------------ */

    /** Live → last-known-good value for one field, or null (→ config). */
    private static function resolved(string $sym, string $field): ?int
    {
        $live = self::liveMap();
        if ($live !== null && isset($live[$field][$sym])) {
            return $live[$field][$sym];
        }

        $lkg = self::cacheGet(self::LKG_CACHE_KEY);
        if (is_array($lkg) && isset($lkg[$field][$sym]) && is_int($lkg[$field][$sym])) {
            if ($live !== null && self::isRelevantSymbol($sym)) {
                // The live map exists but lacks this symbol — say so once a day.
                self::logOncePerDay("market_precision:missing:{$field}:{$sym}", 'PRECISION_FALLBACK', [
                    'symbol' => $sym, 'field' => $field, 'source' => 'last_known_good',
                    'reason' => 'symbol missing from live options',
                ]);
            }
            return $lkg[$field][$sym];
        }

        if ($live !== null && self::isRelevantSymbol($sym)) {
            self::logOncePerDay("market_precision:missing:{$field}:{$sym}", 'PRECISION_FALLBACK', [
                'symbol' => $sym, 'field' => $field, 'source' => 'config',
                'reason' => 'symbol missing from live options',
            ]);
        }
        return null;
    }

    /** @return array{qty: array<string,int>, tick: array<string,int>}|null */
    private static function liveMap(): ?array
    {
        $cached = self::cacheGet(self::LIVE_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        if (! (bool) config('trading.exchange.precision_live', true)) {
            return null; // live source disabled (tests / manual override)
        }
        if (self::cacheGet(self::FAIL_CACHE_KEY) !== null) {
            return null; // a recent fetch failed — don't hammer the exchange
        }

        try {
            $options = app(NobitexService::class)->getOptionsV2();
            $parsed  = self::parseOptions((array) ($options['nobitex'] ?? []));
            if ($parsed === null) {
                throw new \UnexpectedValueException('options payload has no usable amountPrecisions/pricePrecisions');
            }
        } catch (\Throwable $e) {
            self::cachePut(self::FAIL_CACHE_KEY, 1, self::FAIL_BACKOFF_SECONDS);
            self::warn('PRECISION_FALLBACK', [
                'source' => self::cacheGet(self::LKG_CACHE_KEY) !== null ? 'last_known_good' : 'config',
                'reason' => 'live options unavailable or unparsable',
                'error'  => mb_substr($e->getMessage(), 0, 255),
            ]);
            return null;
        }

        self::cachePut(self::LIVE_CACHE_KEY, $parsed, self::LIVE_TTL_SECONDS);
        try {
            Cache::forever(self::LKG_CACHE_KEY, $parsed);
        } catch (\Throwable) {
            // never throw
        }

        return $parsed;
    }

    /**
     * A symbol this system trades: in trading.exchange.allowed_symbols or used
     * by any bot_configs row. Only these get per-symbol precision warnings.
     * The set is memoised for RELEVANT_SYMBOLS_TTL_SECONDS; a DB failure
     * degrades to the config list alone.
     */
    public static function isRelevantSymbol(string $symbol): bool
    {
        $now = time();
        $configured = (array) config('trading.exchange.allowed_symbols', []);
        if (self::$relevant === null
            || self::$relevant['config'] !== $configured
            || ($now - self::$relevant['at']) >= self::RELEVANT_SYMBOLS_TTL_SECONDS) {
            $symbols = [];
            foreach ($configured as $s) {
                $symbols[QtyPrecision::canonicalSymbol((string) $s)] = true;
            }
            try {
                foreach (DB::table('bot_configs')->distinct()->pluck('symbol') as $s) {
                    if (is_string($s) && $s !== '') {
                        $symbols[QtyPrecision::canonicalSymbol($s)] = true;
                    }
                }
            } catch (\Throwable) {
                // no table / DB down: config list only
            }
            self::$relevant = ['at' => $now, 'config' => $configured, 'symbols' => $symbols];
        }

        return isset(self::$relevant['symbols'][QtyPrecision::canonicalSymbol($symbol)]);
    }

    private static function configQtyDecimals(string $sym): ?int
    {
        $v = config("trading.exchange.precision.{$sym}.qty_decimals");
        return $v === null ? null : max(0, min(18, (int) $v));
    }

    private static function configTick(string $sym): ?int
    {
        $v = config("trading.ticks.{$sym}");
        return $v === null ? null : max(1, (int) $v);
    }

    private static function logDrift(string $sym, string $field, int $live, int $config): void
    {
        self::logOncePerDay("market_precision:drift:{$field}:{$sym}", 'PRECISION_DRIFT', [
            'symbol' => $sym, 'field' => $field, 'live' => $live, 'config' => $config,
            'note'   => 'Live exchange precision differs from config; using live. Update config/trading.php.',
        ]);
    }

    private static function logInvalid(string $sym, string $field, mixed $value): void
    {
        self::logOncePerDay("market_precision:invalid:{$field}:{$sym}", 'PRECISION_INVALID', [
            'symbol' => $sym, 'field' => $field, 'value' => is_scalar($value) ? (string) $value : gettype($value),
            'note'   => 'Ignored; falling back to last-known-good/config for this symbol.',
        ]);
    }

    private static function logOncePerDay(string $key, string $event, array $context): void
    {
        try {
            if (! Cache::add($key . ':' . now()->format('Ymd'), 1, 86400)) {
                return;
            }
        } catch (\Throwable) {
            // cache down: log anyway (better noisy than silent)
        }
        self::warn($event, $context);
    }

    private static function warn(string $event, array $context): void
    {
        try {
            Log::channel('trading')->warning($event, $context);
        } catch (\Throwable) {
            // never throw
        }
    }

    private static function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cachePut(string $key, mixed $value, int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable) {
            // never throw
        }
    }
}
