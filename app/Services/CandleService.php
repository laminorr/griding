<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CandleService
 * -------------
 * Read API for OHLC candles: REST history (NobitexService::getOhlc, GET
 * /market/udf/history) overlaid with the live candle the WS consumer caches at
 * MarketDataLayer::candleCacheKey() (public:candle-{SYMBOL}-{RESOLUTION}).
 *
 * REST results are cached for REST_CACHE_TTL_SECONDS per (symbol, resolution,
 * count) to stay well inside the endpoint's 60 requests/minute limit; errors are
 * negatively cached for ERROR_CACHE_TTL_SECONDS so a failing endpoint is not
 * hammered either.
 *
 * PRICE UNIT: for IRT-quoted markets Nobitex returns candle prices in TOMAN —
 * both the REST UDF history and the WS candle channel (verified on the host:
 * BTCIRT candles ~21,639,000,000 vs orderbook/order/stats ~216,390,000,000) —
 * while every other price in this bot (orderbook, orders, stats, grid_orders)
 * is in RIAL. getCandles() therefore multiplies o/h/l/c by 10 for IRT markets
 * (exact decimal-string math via Money; v is base-asset volume and untouched)
 * and reports 'unit' => 'IRR'. The conversion happens ONLY at this output:
 * the REST cache (candles:rest:*) and the WS cache (mdl:candle:*) keep the raw
 * exchange values, and NobitexService::getOhlc() is unchanged.
 */
class CandleService
{
    public const MAX_COUNT = 500;
    public const REST_CACHE_TTL_SECONDS  = 60;
    public const ERROR_CACHE_TTL_SECONDS = 10;
    public const REST_CACHE_PREFIX = 'candles:rest:';

    public function __construct(private readonly NobitexService $nobitex)
    {
    }

    /**
     * Candles for a market, oldest first, with the live WS candle applied.
     *
     * Contract (stable — consumed by the panel chart):
     *   [
     *     'status'  => 'ok'|'no_data'|'error',
     *     'candles' => list<array{t:int,o:string,h:string,l:string,c:string,v:string}>,
     *                  ascending by t (unix seconds, candle open time); o/h/l/c/v are
     *                  decimal strings — the NobitexService::getOhlc() row shape, with
     *                  o/h/l/c expressed in 'unit' (for IRT markets: the exchange's
     *                  TOMAN values ×10 = RIAL; v is base-asset volume, unchanged).
     *                  At most min($count, 500) rows (oldest dropped if the live
     *                  candle is appended).
     *     'live'    => bool, true only when the live candle was applied (replaced
     *                  the last row with equal t, or appended as a newer row),
     *     'errmsg'  => ?string, REST error message when status is 'error',
     *     'unit'    => ?string, price unit of o/h/l/c: 'IRR' for *IRT markets, the
     *                  quote currency (e.g. 'USDT') otherwise; null if unknown,
     *   ]
     * Overlay: live.t == last.t -> replace last; live.t > last.t -> append;
     * older -> ignored. If REST yields no_data/error but a live candle exists,
     * the result is status 'ok' with just that candle and live=true.
     * Network/REST failures never throw: they come back as status 'error'.
     *
     * @param string $symbol      e.g. "BTCIRT" (case-insensitive)
     * @param string $resolution  one of NobitexService::OHLC_RESOLUTIONS
     * @param int    $count       requested candles, clamped to 1..500
     * @return array{status:string,candles:array<int,array{t:int,o:string,h:string,l:string,c:string,v:string}>,live:bool,errmsg:?string,unit:?string}
     * @throws \InvalidArgumentException for an unsupported resolution or empty symbol
     */
    public function getCandles(string $symbol, string $resolution, int $count = 200): array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->toOutputUnit($symbol, $this->rawCandles($symbol, $resolution, $count));
    }

    /**
     * Quote-currency price unit of getCandles() output for a market symbol.
     * IRT markets are reported in RIAL ('IRR'); USDT markets in 'USDT'.
     */
    public static function priceUnit(string $symbol): ?string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_ends_with($symbol, 'IRT')) {
            return 'IRR';
        }
        if (str_ends_with($symbol, 'USDT')) {
            return 'USDT';
        }
        return null;
    }

    /**
     * Convert exchange-unit candles to the output unit and attach 'unit'.
     * IRT markets: o/h/l/c TOMAN -> RIAL (×10, exact string math); v unchanged.
     */
    protected function toOutputUnit(string $symbol, array $result): array
    {
        $unit = self::priceUnit($symbol);

        if ($unit === 'IRR' && $result['candles'] !== []) {
            foreach ($result['candles'] as $i => $row) {
                foreach (['o', 'h', 'l', 'c'] as $f) {
                    $row[$f] = Money::mul($row[$f], '10');
                }
                $result['candles'][$i] = $row;
            }
        }

        $result['unit'] = $unit;
        return $result;
    }

    /**
     * Raw exchange-unit candles (REST history overlaid with the live WS candle).
     * @return array{status:string,candles:array,live:bool,errmsg:?string}
     */
    protected function rawCandles(string $symbol, string $resolution, int $count): array
    {
        $symbol     = strtoupper(trim($symbol));
        $resolution = strtoupper(trim($resolution));

        if ($symbol === '') {
            throw new \InvalidArgumentException('Symbol is required');
        }
        if (!in_array($resolution, NobitexService::OHLC_RESOLUTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported OHLC resolution: ' . $resolution);
        }
        $count = max(1, min($count, self::MAX_COUNT));

        $history = $this->history($symbol, $resolution, $count);
        $live    = $this->liveCandle($symbol, $resolution);

        if ($history['status'] !== 'ok' || $history['candles'] === []) {
            if ($live !== null) {
                return ['status' => 'ok', 'candles' => [$live], 'live' => true, 'errmsg' => null];
            }
            return [
                'status'  => $history['status'] === 'ok' ? 'no_data' : $history['status'],
                'candles' => [],
                'live'    => false,
                'errmsg'  => $history['errmsg'],
            ];
        }

        $candles = array_values($history['candles']);
        usort($candles, fn(array $a, array $b) => $a['t'] <=> $b['t']);

        $applied = false;
        if ($live !== null) {
            $lastIdx = count($candles) - 1;
            $lastT   = $candles[$lastIdx]['t'];
            if ($live['t'] === $lastT) {
                $candles[$lastIdx] = $live;
                $applied = true;
            } elseif ($live['t'] > $lastT) {
                $candles[] = $live;
                $applied = true;
            }
        }

        if (count($candles) > $count) {
            $candles = array_slice($candles, -$count);
        }

        return ['status' => 'ok', 'candles' => $candles, 'live' => $applied, 'errmsg' => null];
    }

    /**
     * REST history, cached per (symbol, resolution, count).
     * @return array{status:string,candles:array,errmsg:?string}
     */
    protected function history(string $symbol, string $resolution, int $count): array
    {
        $key = self::REST_CACHE_PREFIX . $symbol . ':' . $resolution . ':' . $count;

        try {
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['status'])) {
                return $cached;
            }
        } catch (\Throwable $e) {
            Log::warning('[Candles] REST cache read failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        try {
            $result = $this->nobitex->getOhlc($symbol, $resolution, $this->nowSeconds(), countback: $count);
            $result = [
                'status'  => (string) ($result['status'] ?? 'error'),
                'candles' => (array) ($result['candles'] ?? []),
                'errmsg'  => isset($result['errmsg']) ? (string) $result['errmsg'] : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[Candles] REST history failed', [
                'symbol' => $symbol, 'resolution' => $resolution, 'error' => $e->getMessage(),
            ]);
            $result = ['status' => 'error', 'candles' => [], 'errmsg' => $e->getMessage()];
        }

        $ttl = $result['status'] === 'error' ? self::ERROR_CACHE_TTL_SECONDS : self::REST_CACHE_TTL_SECONDS;
        try {
            Cache::put($key, $result, $ttl);
        } catch (\Throwable $e) {
            Log::warning('[Candles] REST cache write failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Latest WS candle, only if it has the exact getOhlc() row shape.
     * @return array{t:int,o:string,h:string,l:string,c:string,v:string}|null
     */
    protected function liveCandle(string $symbol, string $resolution): ?array
    {
        try {
            $row = Cache::get(MarketDataLayer::candleCacheKey($symbol, $resolution));
        } catch (\Throwable $e) {
            Log::warning('[Candles] Live candle read failed', [
                'symbol' => $symbol, 'resolution' => $resolution, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_array($row) || !is_int($row['t'] ?? null) || $row['t'] <= 0) {
            return null;
        }
        foreach (['o', 'h', 'l', 'c', 'v'] as $f) {
            if (!is_string($row[$f] ?? null)) {
                return null;
            }
        }

        return [
            't' => $row['t'], 'o' => $row['o'], 'h' => $row['h'],
            'l' => $row['l'], 'c' => $row['c'], 'v' => $row['v'],
        ];
    }

    /** Clock (unix seconds); follows Carbon test-now. */
    protected function nowSeconds(): int
    {
        return now()->getTimestamp();
    }
}
