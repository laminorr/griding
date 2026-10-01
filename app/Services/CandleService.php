<?php

declare(strict_types=1);

namespace App\Services;

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
     *                  decimal strings — the exact NobitexService::getOhlc() row shape.
     *                  At most min($count, 500) rows (oldest dropped if the live
     *                  candle is appended).
     *     'live'    => bool, true only when the live candle was applied (replaced
     *                  the last row with equal t, or appended as a newer row),
     *     'errmsg'  => ?string, REST error message when status is 'error',
     *   ]
     * Overlay: live.t == last.t -> replace last; live.t > last.t -> append;
     * older -> ignored. If REST yields no_data/error but a live candle exists,
     * the result is status 'ok' with just that candle and live=true.
     * Network/REST failures never throw: they come back as status 'error'.
     *
     * @param string $symbol      e.g. "BTCIRT" (case-insensitive)
     * @param string $resolution  one of NobitexService::OHLC_RESOLUTIONS
     * @param int    $count       requested candles, clamped to 1..500
     * @return array{status:string,candles:array<int,array{t:int,o:string,h:string,l:string,c:string,v:string}>,live:bool,errmsg:?string}
     * @throws \InvalidArgumentException for an unsupported resolution or empty symbol
     */
    public function getCandles(string $symbol, string $resolution, int $count = 200): array
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
