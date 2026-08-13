<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Observers\GridOrderObserver;
use App\Services\GridPlanner;
use App\Services\GridOrderSync;
use App\Support\Money;
use App\Support\OrderRegistry;
use App\Services\GridOrderExecutor;
use App\Services\KillSwitchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdjustGridJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No constructor parameters - we get everything from BotConfig
    public function __construct() {}

    public function handle(
        GridPlanner $planner,
        GridOrderSync $sync,
        OrderRegistry $reg,
        GridOrderExecutor $exec,
        KillSwitchService $killSwitch
    ): void {
        // Global lock to prevent concurrent runs (1 second wait, same semantics
        // as the previous MySQL GET_LOCK(?, 1) call). 30s TTL covers a single
        // run and self-expires if a worker dies mid-job, instead of relying on
        // a held DB connection that a connection pool could recycle.
        $globalLock = Cache::lock('grid:adjust:global', 30);

        try {
            $globalLock->block(1);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::channel('trading')->info('ADJUST_GRID_SKIP', [
                'reason' => 'Global lock busy - another instance running'
            ]);
            return;
        }

        try {
            // Get ONLY active bots
            $activeBots = BotConfig::where('is_active', true)->get();

            if ($activeBots->isEmpty()) {
                Log::channel('trading')->info('ADJUST_GRID_SKIP', [
                    'reason' => 'No active bots found'
                ]);
                return;
            }

            Log::channel('trading')->info('ADJUST_GRID_START', [
                'active_bots' => $activeBots->count(),
                'bot_ids' => $activeBots->pluck('id')->toArray()
            ]);

            // Whitelist allowed symbols. Read from config (config/trading.php
            // 'adjust_grid.allowed_symbols'), NOT env() directly: under
            // `php artisan config:cache` an env() call here returns null and
            // collapses the whitelist. The config key reads the same
            // TRADING_ALLOWED_SYMBOLS env var (default 'BTCIRT') inside the
            // config file, so the effective list is unchanged.
            $allowedSymbols = collect(config('trading.adjust_grid.allowed_symbols', ['BTCIRT']))
                ->map(fn($s) => strtoupper(trim((string) $s)))
                ->filter()
                ->values();

            foreach ($activeBots as $bot) {
                $symbol = strtoupper($bot->symbol ?? 'BTCIRT');

                // Check if symbol is whitelisted
                if (!$allowedSymbols->contains($symbol)) {
                    Log::channel('trading')->warning('SKIP_SYMBOL_NOT_ALLOWED', [
                        'bot_id' => $bot->id,
                        'bot_name' => $bot->name,
                        'symbol' => $symbol,
                        'allowed_symbols' => $allowedSymbols->toArray()
                    ]);
                    continue;
                }

                // Per-bot lock (1 second wait, same semantics as previous
                // GET_LOCK(?, 1)); 30s TTL self-expires if a worker dies.
                $botLockKey = "grid:adjust:bot:{$bot->id}";
                $botLock = Cache::lock($botLockKey, 30);

                try {
                    $botLock->block(1);
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    Log::channel('trading')->info('ADJUST_GRID_BOT_SKIP', [
                        'bot_id' => $bot->id,
                        'reason' => 'Bot lock busy'
                    ]);
                    continue;
                }

                try {
                    // Kill Switch gate (Phase 11 Step 3). Re-evaluate the risk
                    // thresholds on every rebalance cycle. If a threshold is
                    // breached the switch trips (sets is_active = false) and we
                    // skip rebalancing this bot — no NEW orders are planned or
                    // placed. Existing cycle_exit sells are left untouched so
                    // open cycles can still close.
                    $kill = $killSwitch->checkAndTrigger($bot);
                    if ($kill['triggered']) {
                        Log::channel('trading')->warning('ADJUST_GRID_BOT_SKIP_KILLED', [
                            'bot_id'  => $bot->id,
                            'reason'  => $kill['reason'],
                            'details' => $kill['details'],
                        ]);
                        continue;
                    }

                    $simulate = (bool) $bot->simulation;

                    Log::channel('trading')->info('ADJUST_GRID_BOT_START', [
                        'bot_id' => $bot->id,
                        'bot_name' => $bot->name,
                        'symbol' => $symbol,
                        'simulation' => $simulate,
                        'grid_levels' => $bot->grid_levels ?? 6,
                        'grid_spacing' => $bot->grid_spacing ?? 0.25,
                        'capital' => $bot->total_capital ?? 50_000_000
                    ]);

                    // Phase 11 Step 4 — deduct capital already locked in open
                    // cycles from the budget handed to the rebalance planner.
                    //
                    // Once some initial buys have filled, the IRT they spent now
                    // sits in crypto waiting to sell (a cycle_exit *sell*). That
                    // IRT is NOT available to fund NEW buy orders, yet without
                    // this adjustment the planner sizes the whole grid as if the
                    // full budget were free. capital_locked_irt (populated by
                    // GridOrderObserver in Phase 11 Step 2 — the summed notional
                    // of the filled buys behind open cycle_exit sells) is exactly
                    // that spent-and-waiting IRT, so we subtract it to get the
                    // budget actually available to redeploy this pass.
                    //
                    // FIELD NOTE: this job has always fed GridPlanner
                    // total_capital (see the original budgetIrt argument below),
                    // so we deduct from total_capital — not budget_irt — to keep
                    // the locked==0 case byte-for-byte identical to prior
                    // behavior. Initial placement (TradingEngineService::
                    // initializeGrid) needs no change: at init there are no open
                    // cycles, so capital_locked_irt is 0 and effectiveBudget ==
                    // total_capital.
                    $totalBudget   = (string) ($bot->total_capital ?? 50_000_000);
                    $lockedCapital = $bot->capital_locked_irt ?? '0';

                    // Guard against a stale/missing capital_locked_irt: if the
                    // observer never ran (or failed silently) the column can be
                    // null/0 while placed cycle_exit orders exist. Detect that
                    // mismatch and recompute inline via the observer's public
                    // helper before trusting the value.
                    $cycleExitCount = GridOrder::where('bot_config_id', $bot->id)
                        ->where('role', 'cycle_exit')
                        ->where('status', 'placed')
                        ->count();

                    if ($cycleExitCount > 0
                        && ($lockedCapital === null || Money::isZero((string) $lockedCapital))
                    ) {
                        Log::channel('trading')->warning('REBALANCE_STALE_LOCKED_CAPITAL', [
                            'bot_id'           => $bot->id,
                            'cycle_exit_count' => $cycleExitCount,
                        ]);
                        (new GridOrderObserver())->recomputeInventoryForBot($bot);
                        $bot->refresh();
                        $lockedCapital = $bot->capital_locked_irt ?? '0';
                    }

                    $effectiveBudget = Money::sub($totalBudget, (string) $lockedCapital);

                    // If nothing is available to deploy (locked >= total) we skip
                    // ONLY this bot's rebalance for this cycle. This is NOT a Kill
                    // Switch: the bot stays active and its existing cycle_exit
                    // sells keep working; we simply plan no new grid this pass.
                    if (Money::compare($effectiveBudget, '0') <= 0) {
                        Log::channel('trading')->warning('REBALANCE_SKIP_NO_AVAILABLE_BUDGET', [
                            'bot_id'           => $bot->id,
                            'total_budget'     => $totalBudget,
                            'locked_capital'   => (string) $lockedCapital,
                            'effective_budget' => $effectiveBudget,
                        ]);
                        continue;
                    }

                    Log::channel('trading')->info('REBALANCE_EFFECTIVE_BUDGET', [
                        'bot_id'           => $bot->id,
                        'total_budget'     => $totalBudget,
                        'locked_capital'   => (string) $lockedCapital,
                        'effective_budget' => $effectiveBudget,
                    ]);

                    // Phase 11 Step 6 — honor the bot's directional mode instead
                    // of always planning a two-sided grid. Legacy bots may carry a
                    // null/invalid mode; default to 'both' with a warning rather
                    // than fail the rebalance for the whole batch.
                    $mode = strtolower(trim((string) ($bot->mode ?? '')));
                    if (!in_array($mode, ['both', 'buy', 'sell'], true)) {
                        Log::channel('trading')->warning('ADJUST_GRID_MODE_INVALID', [
                            'bot_id' => $bot->id,
                            'mode'   => $bot->mode,
                        ]);
                        $mode = 'both';
                    }

                    // 1) Plan grid using bot's configuration
                    $plan = $planner->plan(
                        $symbol,
                        levels: (int)($bot->grid_levels ?? 6),
                        stepPct: (float)($bot->grid_spacing ?? 0.25),
                        mode: $mode,
                        budgetIrt: (int) $effectiveBudget
                    );

                    // ✅ Get existing orders using bot-specific method
                    $existingOrders = method_exists($reg, 'getOpenForBot')
                        ? $reg->getOpenForBot($bot->id, $symbol)
                        : $reg->getOpen($symbol);

                    // ✅ Only adjust grid if price moved significantly outside current grid range
                    if (!empty($existingOrders)) {
                        $currentPrice = (int) ($plan['mid'] ?? 0);

                        // RANGE SOURCE (early-rebalance fix): measure min/max from
                        // the FULL current grid generation (filled + open orders),
                        // not the shrunken open-order band. getOpenForBot() drops
                        // filled legs, so after one side fills its band narrows and
                        // one-sides and a price still INSIDE the grid looks
                        // "outside" — the spurious rebalance proved against bot 46.
                        // getGridExtentForBot() recovers the true edges from the
                        // persisted grid_orders rows (they keep their price after
                        // filling). Fall back to the reconstructed planned extent
                        // when no current-generation rows survive (grid never fully
                        // placed), and only as a last resort to the legacy
                        // open-order band, so this never crashes or uses an empty
                        // range. NOTE: only the min/max SOURCE changes here — the
                        // threshold, distances and skip condition below are
                        // unchanged, and the diff/apply step keeps using open
                        // orders ($existingOrders) exactly as before.
                        $extent = $reg->getGridExtentForBot($bot->id, $symbol)
                            ?? $this->reconstructGridExtent($bot, $plan);

                        if ($extent !== null) {
                            $minPrice = $extent['min'];
                            $maxPrice = $extent['max'];
                        } else {
                            $prices = array_column($existingOrders, 'price');
                            $minPrice = min($prices);
                            $maxPrice = max($prices);
                        }

                        // Calculate grid range
                        $gridRange = $maxPrice - $minPrice;
                        $threshold = $gridRange * 0.5;  // 50% of grid range

                        // Check if current price is still within acceptable range
                        $distanceFromTop = $maxPrice - $currentPrice;
                        $distanceFromBottom = $currentPrice - $minPrice;

                        if ($distanceFromTop > -$threshold && $distanceFromBottom > -$threshold) {
                            Log::channel('trading')->info('AdjustGridJob: Price still within grid range, skipping adjustment', [
                                'bot_id' => $bot->id,
                                'current_price' => $currentPrice,
                                'grid_min' => $minPrice,
                                'grid_max' => $maxPrice,
                                'threshold' => $threshold
                            ]);
                            continue;
                        }

                        Log::channel('trading')->info('AdjustGridJob: Price moved outside grid range, proceeding with adjustment', [
                            'bot_id' => $bot->id,
                            'current_price' => $currentPrice,
                            'grid_min' => $minPrice,
                            'grid_max' => $maxPrice,
                            'distance_from_top' => $distanceFromTop,
                            'distance_from_bottom' => $distanceFromBottom
                        ]);
                    }

                    // 2) Use the orders we already fetched above for price check
                    $existing = $existingOrders;

                    // 3) Calculate diff
                    $diff = $sync->diff($plan, $existing, 1, 3.0);

                    // Only a diff that actually places or cancels orders counts as
                    // a real rebalance. An empty diff means the plan matched the
                    // existing grid — nothing changed — so the bookkeeping below
                    // must NOT be bumped (the within-range no-op already `continue`d
                    // above; this guards the empty-diff no-op too).
                    $rebalanceApplied = !empty($diff['to_place']) || !empty($diff['to_cancel']);

                    // 4) Apply changes with bot_id context
                    $exec->applyForBot($bot->id, $diff, simulation: $simulate, role: 'rebalance');

                    // 5) Persist rebalance bookkeeping only when a real rebalance
                    // actually placed/changed orders. `last_rebalance_at` is fillable;
                    // `rebalance_count` is bumped atomically so it does not depend on
                    // mass-assignment.
                    if ($rebalanceApplied) {
                        $bot->update(['last_rebalance_at' => now()]);
                        $bot->increment('rebalance_count');
                    }

                    Log::channel('trading')->info('ADJUST_GRID_BOT_COMPLETE', [
                        'bot_id' => $bot->id,
                        'symbol' => $symbol,
                        'rebalance_applied' => $rebalanceApplied,
                    ]);

                } catch (\Throwable $e) {
                    Log::channel('trading')->error('ADJUST_GRID_BOT_ERROR', [
                        'bot_id' => $bot->id,
                        'bot_name' => $bot->name ?? 'unknown',
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                } finally {
                    // Release per-bot lock
                    $botLock->release();
                }
            }

            Log::channel('trading')->info('ADJUST_GRID_COMPLETE', [
                'processed_bots' => $activeBots->count()
            ]);

        } finally {
            // Release global lock
            $globalLock->release();
        }
    }

    /**
     * Reconstruct the planned grid bounds from the bot's center and its
     * levels/spacing, mirroring GridPlanner's geometry (pow(1 ± step, i)) and
     * tick rounding.
     *
     * This is the graceful fallback for the rebalance range check when the
     * persisted current-generation grid rows are missing or too sparse to
     * measure (e.g. the initial grid never fully placed). It anchors on the
     * frozen grid_center_price when available — the same stable center the Kill
     * Switch uses — and otherwise on the current plan mid. It reflects the FULL
     * planned extent on the deployed side(s), so a partially-placed or one-sided
     * grid still gets a stable range instead of a collapsing open-order band.
     *
     * READ-ONLY w.r.t. grid_center_price — it is only read here, never written.
     *
     * @param array<string,mixed> $plan
     * @return array{min:int,max:int}|null null when there is no usable center.
     */
    private function reconstructGridExtent(BotConfig $bot, array $plan): ?array
    {
        $center = ($bot->grid_center_price !== null && (float) $bot->grid_center_price > 0)
            ? (int) $bot->grid_center_price
            : (int) ($plan['mid'] ?? 0);
        if ($center <= 0) {
            return null;
        }

        $levels = (int) ($bot->grid_levels ?? 6);
        $step   = ((float) ($bot->grid_spacing ?? 0.25)) / 100.0;
        $tick   = max(1, (int) ($plan['tick'] ?? 1));
        $mode   = strtolower(trim((string) ($bot->mode ?? 'both')));
        if (!in_array($mode, ['both', 'buy', 'sell'], true)) {
            $mode = 'both';
        }
        if ($levels < 1 || $step <= 0) {
            return null;
        }

        // Same per-side split as GridPlanner::plan (GridPlanner.php:84).
        $perSide = $mode === 'both' ? max(1, intdiv($levels, 2)) : max(1, $levels);

        // Buys sit below center (rounded DOWN to tick), sells above (rounded UP),
        // matching GridPlanner's roundToTick usage (GridPlanner.php:100,110).
        $lowestBuy   = (int) round($center * pow(1 - $step, $perSide));
        $highestBuy  = (int) round($center * pow(1 - $step, 1));
        $lowestSell  = (int) round($center * pow(1 + $step, 1));
        $highestSell = (int) round($center * pow(1 + $step, $perSide));

        if ($mode === 'buy') {
            return [
                'min' => $this->roundToTick($lowestBuy, $tick, down: true),
                'max' => $this->roundToTick($highestBuy, $tick, down: true),
            ];
        }
        if ($mode === 'sell') {
            return [
                'min' => $this->roundToTick($lowestSell, $tick, down: false),
                'max' => $this->roundToTick($highestSell, $tick, down: false),
            ];
        }

        return [
            'min' => $this->roundToTick($lowestBuy, $tick, down: true),
            'max' => $this->roundToTick($highestSell, $tick, down: false),
        ];
    }

    /**
     * Tick rounding identical to GridPlanner::roundToTick (GridPlanner.php:227),
     * replicated here (small, intentionally not abstracted) so the reconstructed
     * fallback bounds land on the same ticks the planner would have placed.
     */
    private function roundToTick(int $price, int $tick, bool $down): int
    {
        if ($tick <= 1) {
            return $price;
        }
        $q = intdiv($price, $tick);
        $hasRemainder = ($price % $tick) !== 0;

        if ($down) {
            return $q * $tick;
        }
        return $hasRemainder ? ($q + 1) * $tick : $price;
    }
}
