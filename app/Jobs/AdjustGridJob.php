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

            // Whitelist allowed symbols from env
            $allowedSymbols = collect(explode(',', env('TRADING_ALLOWED_SYMBOLS', 'BTCIRT')))
                ->map(fn($s) => strtoupper(trim($s)))
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
                        $prices = array_column($existingOrders, 'price');
                        $minPrice = min($prices);
                        $maxPrice = max($prices);

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

                    // 4) Apply changes with bot_id context
                    if (method_exists($exec, 'applyForBot')) {
                        $exec->applyForBot($bot->id, $diff, simulation: $simulate, role: 'rebalance');
                    } else {
                        $exec->apply($diff, simulation: $simulate);
                        Log::channel('trading')->warning('USING_UNSCOPED_APPLY', [
                            'bot_id' => $bot->id,
                            'message' => 'GridOrderExecutor::applyForBot not implemented'
                        ]);
                    }

                    Log::channel('trading')->info('ADJUST_GRID_BOT_COMPLETE', [
                        'bot_id' => $bot->id,
                        'symbol' => $symbol
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
}
