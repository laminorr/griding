<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class OrderRegistry
{
    // Cache TTL set to 5 minutes - balances performance with data freshness during active trading
    private const CACHE_TTL = 300; // 5 minutes

    protected function key(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        return "gridbot:open_orders:{$symbol}";
    }

    /**
     * Cache-backed hint of open orders per symbol. Entries only carry
     * id/side/price/quantity (see remember()), so they lack paired_order_id
     * and role — protection decisions must use getOpenForBot(), which reads
     * the database and is the source of truth. This cache path is
     * intentionally left untouched.
     *
     * @return array<int,array{id?:string,side:string,price:int,quantity:string}>
     */
    public function getOpen(string $symbol): array
    {
        return array_values((array) Cache::get($this->key($symbol), []));
    }

    /**
     * Get open orders for a specific bot from database (not cache)
     * This ensures we see the latest orders including paired orders created by CheckTradesJob
     *
     * grid_orders has no symbol column (symbol lives on bot_configs), so the
     * query filters by bot_config_id only; a bot trades a single symbol, so
     * $symbol is already implied by $botId and kept only for signature
     * compatibility with callers.
     */
    public function getOpenForBot(int $botId, string $symbol): array
    {
        return \App\Models\GridOrder::where('bot_config_id', $botId)
            ->whereIn('status', ['placed', 'active'])
            ->get()
            ->map(fn($order) => [
                'id' => $order->nobitex_order_id,
                'side' => $order->type,
                'price' => (int) $order->price,
                'quantity' => (string) $order->amount,
                'paired_order_id' => $order->paired_order_id,  // protection signal
                'role' => $order->role,                        // protection signal
            ])
            ->toArray();
    }

    /**
     * Min/max PRICE of the bot's CURRENT grid generation, for the rebalance
     * range check ONLY — measured over ALL statuses (filled + open), NOT just
     * the surviving open orders.
     *
     * WHY THIS EXISTS (early-rebalance bug)
     * -------------------------------------
     * getOpenForBot() returns only open orders (status placed/active). Once one
     * side of the grid fills, those prices drop out and the open-order band
     * shrinks and goes one-sided, so a price still well INSIDE the original grid
     * looks "outside" and triggers a spurious rebalance (proved against bot 46:
     * open band 124.60B/126.47B fired at ~121B, but the full grid 119.10B/126.47B
     * would correctly skip). The range check must therefore measure the FULL grid
     * the bot is currently trading, recovered from the persisted grid_orders rows
     * that keep their price after filling (status → 'filled', never deleted).
     *
     * CURRENT GENERATION
     * ------------------
     * A rebalance supersedes the initial grid: if the bot has ever rebalanced the
     * live grid is the most recent 'rebalance' batch, otherwise it is the
     * 'initial_grid'. Measuring 'initial_grid' forever would keep reading a grid
     * that no longer exists after a rebalance, so the role is chosen dynamically.
     * cycle_exit / manual orders never define the grid extent. When a bot has
     * rebalanced MORE THAN once, only the newest placement batch counts — a batch
     * is written in a single job run (sub-second spread) while successive
     * rebalances are >= the adjust-grid cadence apart, so we keep rows from the
     * newest created_at cluster and stop at the first larger gap. With 0 or 1
     * rebalance there is a single batch and every row is kept.
     *
     * @return array{min:int,max:int}|null null when the current generation has no
     *         grid rows (caller keeps today's no-rebalance-on-empty behavior or
     *         falls back to a reconstructed extent).
     */
    public function getGridExtentForBot(int $botId, string $symbol): ?array
    {
        $hasRebalance = \App\Models\GridOrder::where('bot_config_id', $botId)
            ->where('role', 'rebalance')
            ->exists();
        $currentRole = $hasRebalance ? 'rebalance' : 'initial_grid';

        $rows = \App\Models\GridOrder::where('bot_config_id', $botId)
            ->where('role', $currentRole)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['price', 'created_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        // Isolate the newest placement batch. The clustering window is half the
        // adjust-grid cadence, floored at 30s: comfortably larger than the
        // sub-second spread within one batch and strictly smaller than the gap
        // between successive rebalances (>= the full cadence).
        $cadence = (int) config('trading.scheduler.interval_adjust_grid', 600);
        $window  = max(30, intdiv($cadence, 2));

        $prices = [];
        $prevTs = null;
        foreach ($rows as $row) {
            $ts = $row->created_at?->getTimestamp() ?? 0;
            if ($prevTs !== null && ($prevTs - $ts) > $window) {
                break; // crossed into an older grid generation
            }
            $prices[] = (int) $row->price;
            $prevTs = $ts;
        }

        return ['min' => min($prices), 'max' => max($prices)];
    }

    /** @param array{id?:string,side:string,price:int,quantity:string} $order */
    public function remember(string $symbol, array $order): void
    {
        $key = $this->key($symbol);
        $bag = (array) Cache::get($key, []);
        $id  = (string) ($order['id'] ?? uniqid('L'));
        $bag[$id] = ['id'=>$id] + $order;
        // Cache expires after 5 minutes to prevent memory leaks and ensure data freshness
        Cache::put($key, $bag, self::CACHE_TTL);
    }

    public function forget(string $symbol, string $id): void
    {
        $key = $this->key($symbol);
        $bag = (array) Cache::get($key, []);
        unset($bag[$id]);
        // Cache expires after 5 minutes to prevent memory leaks and ensure data freshness
        Cache::put($key, $bag, self::CACHE_TTL);
    }

    /** bulk replace (اختیاری) */
    public function replaceAll(string $symbol, array $orders): void
    {
        $key = $this->key($symbol);
        $bag = [];
        foreach ($orders as $o) {
            $id = (string) ($o['id'] ?? uniqid('L'));
            $bag[$id] = ['id'=>$id] + $o;
        }
        // Cache expires after 5 minutes to prevent memory leaks and ensure data freshness
        Cache::put($key, $bag, self::CACHE_TTL);
    }
}
