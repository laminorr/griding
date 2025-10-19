<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MarketData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReadMarketStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $symbol;
    public ?int $maxAge;

    public function __construct(string $symbol, ?int $maxAge = null)
    {
        $this->symbol = strtoupper(trim($symbol));
        $this->maxAge = $maxAge;
        $this->onQueue('default');
    }

    public function handle(MarketData $market): void
    {
        try {
            $obDto = $market->getOrderBook($this->symbol);
            $ob    = $obDto->toArray();

            $asks = $ob['asks'] ?? [];
            $bids = $ob['bids'] ?? [];

            // استخراج امن قیمت اول هر سمت (هم ساختار map هم array)
            $bestAsk = $this->firstPrice($asks);
            $bestBid = $this->firstPrice($bids);

            $spread = ($bestAsk !== null && $bestBid !== null) ? max(0, $bestAsk - $bestBid) : 0;
            $mid    = ($bestAsk !== null && $bestBid !== null) ? ($bestAsk + $bestBid) / 2 : 0;
            $sprPct = $mid > 0 ? round(($spread / $mid) * 100, 4) : 0.0;

            $price = $market->getLastPrice($this->symbol, $this->maxAge);

            Log::channel('trading')->info('MARKET_STATS', [
                'symbol'       => $this->symbol,
                'price'        => $price,
                'lastPrice'    => (int)($ob['lastPrice'] ?? $ob['lastTradePrice'] ?? 0),
                'bestAsk'      => $bestAsk,
                'bestBid'      => $bestBid,
                'spread'       => $spread,
                'spread_pct'   => $sprPct,
                'levels_asks'  => count($asks),
                'levels_bids'  => count($bids),
                'orderbook_ts' => (int)($ob['ts'] ?? $ob['lastUpdate'] ?? (int)round(microtime(true) * 1000)),
            ]);
        } catch (Throwable $e) {
            Log::channel('trading')->warning('MARKET_STATS_FAILED', [
                'symbol' => $this->symbol,
                'error'  => $e->getMessage(),
            ]);
            $this->release(5);
        }
    }

    /** @param array<int,mixed> $levels */
    private function firstPrice(array $levels): ?int
    {
        $row = $levels[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        if (isset($row['price'])) {
            $p = (int)$row['price'];
            return $p > 0 ? $p : null;
        }
        if (array_key_exists(0, $row)) {
            $p = (int)$row[0];
            return $p > 0 ? $p : null;
        }
        return null;
    }
}
