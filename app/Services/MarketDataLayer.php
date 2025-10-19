<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExchangeClient;
use App\Contracts\MarketData;
use App\DTOs\OrderBookDto;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MarketDataLayer
 * ----------------
 * منبع واحد دادهٔ بازار با ترتیب منابع:
 *   1) Cache  2) WebSocket snapshot (اختیاری)  3) REST (ExchangeClient)
 *
 * Cache keys:
 *  - mdl:last_price:{SYMBOL} => ['price'=>int,'ts'=>int]
 *  - mdl:orderbook:{SYMBOL}  => OrderBookDto::toArray()
 */
class MarketDataLayer implements MarketData
{
    public const CACHE_PREFIX_PRICE     = 'mdl:last_price:';
    public const CACHE_PREFIX_ORDERBOOK = 'mdl:orderbook:';

    protected ExchangeClient $rest;
    protected ?NobitexWebSocketService $ws;

    /** seconds */ protected int $ttlPrice;
    /** seconds */ protected int $ttlMarket;

    /** @var array<string,bool> */
    protected array $allowedSymbols;

    /** پیشوند سفارشی برای کلیدهای کش (اختیاری) */
    protected ?string $cachePrefixOverride = null;

    public function __construct(ExchangeClient $rest, ?NobitexWebSocketService $ws = null)
    {
        $this->rest      = $rest;
        $this->ws        = $ws;
        $this->ttlPrice  = (int) config('trading.cache.ttl_price', config('trading.cache.price_ttl', 5));
        $this->ttlMarket = (int) config('trading.cache.ttl_market', config('trading.cache.market_stats_ttl', 60));

        $symbols = (array) config('trading.exchange.allowed_symbols', ['BTCIRT','ETHIRT','USDTIRT']);
        $this->allowedSymbols = [];
        foreach ($symbols as $sym) {
            $this->allowedSymbols[$this->normalizeSymbol($sym)] = true;
        }
    }

    public function setWebSocket(?NobitexWebSocketService $ws): void
    {
        $this->ws = $ws;
    }

    public function setCachePrefix(?string $prefix): void
    {
        $this->cachePrefixOverride = $prefix;
    }

    // =========================================================================
    // MarketData (Public API)
    // =========================================================================

    /** آخرین قیمت معامله (int) */
    public function getLastPrice(string $symbol, ?int $maxAge = null): int
    {
        $symbol = $this->assertAndNormalize($symbol);
        $maxAge = $maxAge ?? $this->ttlPrice;

        // 1) Cache (price)
        $cached = $this->cacheGetPrice($symbol);
        if ($cached !== null && !$this->isStale($cached['ts'], $maxAge)) {
            return (int) $cached['price'];
        }

        // 1.5) Cache (orderbook → lastPrice)
        $obCached = $this->cacheGetOrderbook($symbol);
        if ($obCached !== null && !$this->isStale(($obCached['ts'] ?? $obCached['lastUpdate'] ?? 0), $this->ttlMarket)) {
            $p = (int) ($obCached['lastPrice'] ?? $obCached['lastTradePrice'] ?? 0);
            if ($p > 0) {
                $this->cachePutPrice($symbol, $p);
                return $p;
            }
        }

        // 2) WebSocket snapshot
        if ($this->ws !== null) {
            try {
                $snap = $this->ws->getLastPriceSnapshot($symbol); // ['price'=>int,'ts'=>int]
                if (!empty($snap) && (int)($snap['price'] ?? 0) > 0) {
                    $price = (int) $snap['price'];
                    $this->cachePutPrice($symbol, $price);
                    return $price;
                }
            } catch (Throwable $e) {
                Log::channel('nobitex')->warning('WS snapshot failed in MarketDataLayer', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
            }
        }

        // 3) REST (fallback از orderbook)
        $dto   = $this->rest->getOrderBook($symbol);
        $price = (int) $dto->lastPrice;
        if ($price <= 0) {
            $mid = $dto->midPrice();
            if ($mid <= 0) {
                throw new \RuntimeException("No last price for {$symbol}");
            }
            $price = $mid;
        }

        // کش
        $this->cachePutOrderbook($symbol, $dto->toArray());
        $this->cachePutPrice($symbol, $price);
        return $price;
    }

    /** اوردربوک تایپ‌دار (WS-first → REST-fallback) */
    public function getOrderBook(string $symbol): OrderBookDto
    {
        $symbol = $this->assertAndNormalize($symbol);

        // 0) Cache
        $cached = $this->cacheGetOrderbook($symbol);
        if ($cached !== null && !$this->isStale(($cached['ts'] ?? $cached['lastUpdate'] ?? 0), $this->ttlMarket)) {
            return OrderBookDto::fromApi($cached, $symbol);
        }

        // 1) WS snapshot
        if ($this->ws !== null) {
            try {
                $snap = $this->ws->getOrderbookSnapshot($symbol);
                if (!empty($snap) && isset($snap['asks'], $snap['bids'])) {
                    $raw = $this->normalizeOrderbook($snap);
                    $dto = OrderBookDto::fromApi($raw, $symbol);
                    $this->cachePutOrderbook($symbol, $dto->toArray());
                    $this->cachePutPrice($symbol, (int) $dto->lastPrice);
                    return $dto;
                }
            } catch (Throwable $e) {
                Log::channel('nobitex')->warning('WS orderbook snapshot failed', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
            }
        }

        // 2) REST
        $dto = $this->rest->getOrderBook($symbol);
        $this->cachePutOrderbook($symbol, $dto->toArray());
        $this->cachePutPrice($symbol, (int) $dto->lastPrice);
        return $dto;
    }

    /**
     * جایگزین امن برای کدهای قدیمی که آرایه می‌خواستند.
     * @return array{asks:array,bids:array,lastTradePrice:int,lastUpdate:int,ts?:int}
     */
    public function getOrderBookArray(string $symbol, bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->invalidate($symbol);
        }
        return $this->getOrderBook($symbol)->toArray();
    }

    // =========================================================================
    // ابزارهای کمکی عمومی
    // =========================================================================

    public function getBestAsk(string $symbol): ?int
    {
        $dto = $this->getOrderBook($symbol);
        return $this->priceOf(($dto->toArray()['asks'][0] ?? null));
    }

    public function getBestBid(string $symbol): ?int
    {
        $dto = $this->getOrderBook($symbol);
        return $this->priceOf(($dto->toArray()['bids'][0] ?? null));
    }

    public function getSpread(string $symbol): int
    {
        $dto     = $this->getOrderBook($symbol);
        $arr     = $dto->toArray();
        $bestAsk = $this->priceOf($arr['asks'][0] ?? null);
        $bestBid = $this->priceOf($arr['bids'][0] ?? null);
        if ($bestAsk === null || $bestBid === null) return 0;
        return max(0, $bestAsk - $bestBid);
    }

    public function getSpreadPercent(string $symbol): float
    {
        $dto     = $this->getOrderBook($symbol);
        $arr     = $dto->toArray();
        $bestAsk = $this->priceOf($arr['asks'][0] ?? null);
        $bestBid = $this->priceOf($arr['bids'][0] ?? null);
        if ($bestAsk === null || $bestBid === null) return 0.0;
        $mid = ($bestAsk + $bestBid) / 2;
        return $mid > 0 ? round((($bestAsk - $bestBid) / $mid) * 100, 4) : 0.0;
    }

    public function warmup(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $sym) {
            try {
                $sym       = $this->assertAndNormalize($sym);
                $out[$sym] = $this->getLastPrice($sym);
            } catch (Throwable $e) {
                Log::channel('nobitex')->warning('MarketData warmup failed', [
                    'symbol' => $sym, 'error' => $e->getMessage(),
                ]);
            }
        }
        return $out;
    }

    public function invalidate(string $symbol): void
    {
        $symbol = $this->normalizeSymbol($symbol);
        Cache::forget($this->keyPrice($symbol));
        Cache::forget($this->keyOrderbook($symbol));
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    protected function keyPrice(string $symbol): string
    {
        $base = self::CACHE_PREFIX_PRICE . $symbol;
        return $this->cachePrefixOverride ? ($this->cachePrefixOverride . $base) : $base;
    }

    protected function keyOrderbook(string $symbol): string
    {
        $base = self::CACHE_PREFIX_ORDERBOOK . $symbol;
        return $this->cachePrefixOverride ? ($this->cachePrefixOverride . $base) : $base;
    }

    protected function normalizeSymbol(string $symbol): string
    {
        return strtoupper(trim($symbol));
    }

    protected function assertAndNormalize(string $symbol): string
    {
        $symbol = $this->normalizeSymbol($symbol);
        if (!isset($this->allowedSymbols[$symbol])) {
            throw new \InvalidArgumentException(
                "Symbol '{$symbol}' is not allowed. Configure trading.exchange.allowed_symbols if needed."
            );
        }
        return $symbol;
    }

    /** آیا داده با توجه به maxAge کهنه است؟ (ts می‌تواند ثانیه یا میلی‌ثانیه باشد) */
    protected function isStale(int|float $tsUnix, int $maxAge): bool
    {
        if ($tsUnix <= 0) return true;
        $t = (int) $tsUnix;
        if ($t > 20_000_000_000) { // ms → s
            $t = intdiv($t, 1000);
        }
        return (time() - $t) > $maxAge;
    }

    /** @return array{price:int,ts:int}|null */
    protected function cacheGetPrice(string $symbol): ?array
    {
        /** @var array{price:int,ts:int}|null $val */
        $val = Cache::get($this->keyPrice($symbol));
        return $val ?: null;
    }

    protected function cachePutPrice(string $symbol, int $price): void
    {
        $payload = ['price' => $price, 'ts' => time()];
        Cache::put($this->keyPrice($symbol), $payload, $this->ttlPrice);
    }

    /** @return array|null */
    protected function cacheGetOrderbook(string $symbol): ?array
    {
        /** @var array|null $val */
        $val = Cache::get($this->keyOrderbook($symbol));
        return $val ?: null;
    }

    protected function cachePutOrderbook(string $symbol, array $dtoArray): void
    {
        if (!isset($dtoArray['ts'])) {
            $dtoArray['ts'] = (int) round(microtime(true) * 1000);
        }
        Cache::put($this->keyOrderbook($symbol), $dtoArray, $this->ttlMarket);
    }

    /** نرمال‌سازی ساختار اردربوک WS به فرمت سازگار با OrderBookDto::fromApi */
    protected function normalizeOrderbook(array $raw): array
    {
        $asks = $this->normalizeL2(array_values((array) Arr::get($raw, 'asks', [])));
        $bids = $this->normalizeL2(array_values((array) Arr::get($raw, 'bids', [])));

        $lastTradePrice = (int) (Arr::get($raw, 'lastTradePrice') ?? Arr::get($raw, 'lastPrice') ?? 0);
        if ($lastTradePrice <= 0) {
            $lastTradePrice = $this->inferMidPrice($asks, $bids);
        }

        $lastUpdate = (int) (Arr::get($raw, 'lastUpdate') ?? 0);
        if ($lastUpdate <= 0) {
            $lastUpdate = (int) round(microtime(true) * 1000);
        }

        return [
            'asks'           => $asks,
            'bids'           => $bids,
            'lastTradePrice' => $lastTradePrice,
            'lastUpdate'     => $lastUpdate,
        ];
    }

    /**
     * نرمال‌سازی L2:
     * ورودی: [[price,amount], ...] یا [['price'=>..,'amount'=>..], ...]
     * خروجی: [[price(string), amount(string)], ...]
     * @param  array<int,mixed> $rows
     * @return array<int,array{0:string,1:string}>
     */
    protected function normalizeL2(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists(0, $row) && array_key_exists(1, $row)) {
                $out[] = [(string) $row[0], (string) $row[1]];
            } elseif (is_array($row) && isset($row['price'], $row['amount'])) {
                $out[] = [(string) $row['price'], (string) $row['amount']];
            }
        }
        return $out;
    }

    /** اگر lastTradePrice نبود، میانگین بهترین ask/bid را برمی‌گرداند. */
    protected function inferMidPrice(array $asks, array $bids): int
    {
        $ask = $this->priceOf($asks[0] ?? null);
        $bid = $this->priceOf($bids[0] ?? null);
        if ($ask === null && $bid === null) return 0;
        if ($ask === null) return $bid;
        if ($bid === null) return $ask;
        return (int) floor(($ask + $bid) / 2);
    }

    /**
     * @param array{0:string,1:string}|array{price:int,quantity?:string,amount?:string}|null $row
     */
    protected function priceOf(?array $row): ?int
    {
        if (!$row) return null;
        if (array_key_exists(0, $row)) {
            $p = (int) ($row[0] ?? 0);
            return $p > 0 ? $p : null;
        }
        if (isset($row['price'])) {
            $p = (int) $row['price'];
            return $p > 0 ? $p : null;
        }
        return null;
    }
}
