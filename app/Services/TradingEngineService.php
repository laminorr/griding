<?php

namespace App\Services;

use App\Models\GridOrder;
use App\Models\BotConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

/**
 * TradingEngineService - Grid initialization wrapper.
 *
 * This service is now a thin wrapper around grid initialization only.
 * The ongoing grid lifecycle (order monitoring, pair-order creation,
 * rebalance, simulation/live adjust) is handled by CheckTradesJob and
 * AdjustGridJob — not by this class.
 *
 * Live entry point: initializeGrid(), invoked from the three Filament
 * pages (CreateBotConfig, ListBotConfigs, BotConfigResource).
 */
class TradingEngineService
{
    private NobitexService $nobitexService;
    private GridCalculatorService $gridCalculator;
    private BotActivityLogger $activityLogger;
    private GridPlanner $gridPlanner;
    private GridOrderSync $gridOrderSync;
    private GridOrderExecutor $gridOrderExecutor;

    public function __construct(
        NobitexService $nobitexService,
        GridCalculatorService $gridCalculator,
        BotActivityLogger $activityLogger,
        GridPlanner $gridPlanner,
        GridOrderSync $gridOrderSync,
        GridOrderExecutor $gridOrderExecutor
    ) {
        $this->nobitexService = $nobitexService;
        $this->gridCalculator = $gridCalculator;
        $this->activityLogger = $activityLogger;
        $this->gridPlanner = $gridPlanner;
        $this->gridOrderSync = $gridOrderSync;
        $this->gridOrderExecutor = $gridOrderExecutor;
    }

    /**
     * راه‌اندازی کامل گرید
     */
    public function initializeGrid(BotConfig $botConfig, array $options = []): array
    {
        try {
            Log::info("Starting grid initialization", ['bot_id' => $botConfig->id]);

            // 1. بررسی‌های اولیه
            $preflightResult = $this->performPreflightChecks($botConfig);
            if (!$preflightResult['success']) {
                throw new Exception($preflightResult['error']);
            }

            // 2. تحلیل بازار
            $marketAnalysis = $this->analyzeMarketForGrid($botConfig);
            if (!$marketAnalysis['suitable']) {
                if (!($options['force_start'] ?? false)) {
                    throw new Exception('Market conditions not suitable: ' . $marketAnalysis['reason']);
                }

                $this->logForceStartOverride($botConfig, $marketAnalysis);
            }

            // 3. محاسبه قیمت مرکز
            $centerPrice = $this->calculateOptimalCenterPrice($botConfig, $options);

            // 4. محاسبه سطوح گرید
            $gridResult = $this->gridCalculator->calculateGridLevels(
                $centerPrice,
                $botConfig->grid_spacing,
                $botConfig->grid_levels
            );

            if (!$gridResult['success']) {
                throw new Exception('Grid calculation failed: ' . $gridResult['error']);
            }

            // 5. محاسبه اندازه سفارشات
            // Ensure active_capital_percent has a valid value
            $activePercent = $botConfig->active_capital_percent ?? 100.0;
            if ($activePercent <= 0 || $activePercent > 100) {
                throw new Exception("Invalid active_capital_percent: {$activePercent}. Must be between 0 and 100.");
            }

            $orderSizeResult = $this->gridCalculator->calculateOrderSize(
                $botConfig->total_capital,
                $activePercent,
                $botConfig->grid_levels,
                $botConfig->symbol ?? 'BTCIRT'
            );

            if (!$orderSizeResult['success'] || !$orderSizeResult['validation']['is_valid']) {
                throw new Exception('Order size validation failed');
            }

            // 5b. بررسی موجودی واقعی ارز quote قبل از ثبت سفارشات خرید
            $balanceCheck = $this->verifySufficientQuoteBalance(
                $botConfig,
                $gridResult['grid_levels'],
                $orderSizeResult['crypto_amount']
            );
            if (!$balanceCheck['success']) {
                throw new Exception($balanceCheck['error']);
            }

            // 6. پاکسازی سفارشات قدیمی
            $this->cleanupExistingOrders($botConfig);

            // 7. ثبت سفارشات جدید
            $placementResult = $this->placeGridOrders(
                $botConfig,
                $gridResult['grid_levels'],
                $orderSizeResult['crypto_amount'],
                $centerPrice
            );

            // 8. ارزیابی سلامت راه‌اندازی و تعیین init_status
            $healthResult = $this->evaluateInitializationHealth($placementResult);

            $isActive = $healthResult['init_status'] === 'running';

            $botConfig->update([
                'center_price' => $centerPrice,
                'is_active' => $isActive,
                'init_status' => $healthResult['init_status'],
                'started_at' => $isActive ? now() : $botConfig->started_at,
                'last_rebalance_at' => now(),
                'stop_reason' => $isActive ? null : $healthResult['reason'],
            ]);

            if ($isActive) {
                Log::info("Grid initialization completed successfully", [
                    'bot_id' => $botConfig->id,
                    'center_price' => $centerPrice,
                    'successful_orders' => $placementResult['successful']
                ]);
            } else {
                Log::warning("Grid initialization did not meet health threshold; bot left inactive", [
                    'bot_id' => $botConfig->id,
                    'init_status' => $healthResult['init_status'],
                    'reason' => $healthResult['reason'],
                ]);
            }

            return [
                'success' => $isActive,
                'message' => $isActive ? 'Grid initialized successfully' : $healthResult['reason'],
                'error' => $isActive ? null : $healthResult['reason'],
                'init_status' => $healthResult['init_status'],
                'data' => [
                    'center_price' => $centerPrice,
                    'total_orders' => $placementResult['total'],
                    'successful_orders' => $placementResult['successful'],
                    'failed_orders' => $placementResult['failed'],
                    'order_size_crypto' => $orderSizeResult['crypto_amount'],
                    'market_analysis' => $marketAnalysis,
                    'init_status' => $healthResult['init_status'],
                ]
            ];

        } catch (Exception $e) {
            // No DB transaction to roll back here: external Nobitex API calls must
            // never be wrapped in a long-running DB transaction (an order can be
            // successfully created on the exchange before a later step fails, and
            // a rollback would erase the local record while the order stays open
            // on the exchange — an orphaned order). Each GridOrder row is persisted
            // immediately after its own exchange call succeeds (see placeGridOrders()
            // and cleanupExistingOrders()), so orders already placed before the
            // failure remain correctly recorded locally.
            Log::error("Grid initialization failed", [
                'bot_id' => $botConfig->id,
                'error' => $e->getMessage()
            ]);

            $botConfig->update([
                'is_active' => false,
                'init_status' => 'failed',
                'last_error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'INITIALIZATION_FAILED'
            ];
        }
    }

    /**
     * بررسی‌های قبل از راه‌اندازی
     */
    private function performPreflightChecks(BotConfig $botConfig): array
    {
        try {
            // بررسی API key
            if (empty(config('services.nobitex.api_key'))) {
                return ['success' => false, 'error' => 'API key not configured'];
            }

            // تست اتصال
            $connectionTest = $this->nobitexService->healthCheck();
            if ($connectionTest['status'] !== 'ok') {
                return ['success' => false, 'error' => 'Connection failed'];
            }

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ثبت لاگ حسابرسی هنگامی که force_start، بررسی تناسب بازار را دور می‌زند.
     *
     * force_start has no UI/CLI trigger today (it's only reachable by
     * passing $options['force_start'] = true directly into initializeGrid()),
     * but whenever it IS used to bypass the market-suitability gate, that
     * override must be loud and traceable: who triggered it, when, on which
     * bot, and exactly which warning was bypassed.
     */
    private function logForceStartOverride(BotConfig $botConfig, array $marketAnalysis): void
    {
        $triggeredBy = auth()->check() ? ('user:' . auth()->id()) : 'console';

        $message = sprintf(
            '⚠️ force_start used to bypass unsuitable market conditions (triggered by %s): %s',
            $triggeredBy,
            $marketAnalysis['reason'] ?? 'unknown reason'
        );

        Log::warning($message, [
            'bot_id' => $botConfig->id,
            'triggered_by' => $triggeredBy,
            'bypassed_market_analysis' => $marketAnalysis,
        ]);

        $this->activityLogger->log(
            $botConfig->id,
            'FORCE_START_OVERRIDE',
            'WARNING',
            $message,
            [
                'triggered_by' => $triggeredBy,
                'bypassed_market_analysis' => $marketAnalysis,
            ]
        );
    }

    /**
     * تعیین init_status بر اساس نسبت موفقیت سفارشات و حداقل پوشش هر طرف
     * (خرید/فروش) که واقعاً در گرید برنامه‌ریزی شده‌اند.
     *
     * Minimum health bar to mark a bot 'running': at least one successful
     * order on every side that was actually planned (buy-only bots need
     * >=1 successful buy, sell-only need >=1 successful sell, buy+sell
     * grids need >=1 of each) AND an overall success ratio >= 80%. Both
     * conditions must hold — being conservative here is intentional: a bot
     * that silently runs with most of its grid missing is worse than one
     * that fails loudly and waits for a retry.
     */
    private function evaluateInitializationHealth(array $placementResult): array
    {
        $total = $placementResult['total'];
        $successful = $placementResult['successful'];
        $plannedBuy = $placementResult['planned_buy'] ?? 0;
        $plannedSell = $placementResult['planned_sell'] ?? 0;
        $successfulBuy = $placementResult['successful_buy'] ?? 0;
        $successfulSell = $placementResult['successful_sell'] ?? 0;

        if ($successful === 0) {
            return [
                'init_status' => 'failed',
                'reason' => "Grid initialization failed: 0/{$total} orders placed successfully",
            ];
        }

        $ratio = $total > 0 ? ($successful / $total) : 0;

        $sideMinimumMet = true;
        if ($plannedBuy > 0 && $plannedSell > 0) {
            $sideMinimumMet = $successfulBuy >= 1 && $successfulSell >= 1;
        } elseif ($plannedBuy > 0) {
            $sideMinimumMet = $successfulBuy >= 1;
        } elseif ($plannedSell > 0) {
            $sideMinimumMet = $successfulSell >= 1;
        }

        if ($sideMinimumMet && $ratio >= 0.8) {
            return ['init_status' => 'running', 'reason' => null];
        }

        return [
            'init_status' => 'partially_initialized',
            'reason' => sprintf(
                'Grid initialization only partially succeeded: %d/%d orders placed (buy %d/%d, sell %d/%d) — below the minimum health threshold to mark the bot running',
                $successful,
                $total,
                $successfulBuy,
                $plannedBuy,
                $successfulSell,
                $plannedSell
            ),
        ];
    }

    /**
     * بررسی موجودی واقعی ارز quote (مثل IRT/RLS) قبل از ثبت سفارشات خرید.
     *
     * Note: this checks this bot's own requirement against the account's
     * current total available balance. It does NOT protect against other
     * bots on the same account concurrently reserving/spending the same
     * balance — there is no cross-bot capital allocation tracking in this
     * codebase today (BotConfig has no "reserved capital" concept), so two
     * bots racing to start at the same time can both pass this check
     * against the same pre-spend balance. That is a separate, larger
     * concern to address later.
     */
    private function verifySufficientQuoteBalance(BotConfig $botConfig, Collection $gridLevels, float $orderSize): array
    {
        if ($botConfig->simulation) {
            return ['success' => true];
        }

        $buyLevels = $gridLevels->where('type', 'buy');
        if ($buyLevels->isEmpty()) {
            return ['success' => true];
        }

        try {
            $symbol = $botConfig->symbol ?? 'BTCIRT';
            [, $quoteCurrency] = GridOrderExecutor::splitSymbol($symbol);

            $balances = $this->nobitexService->getBalances();
            $available = (float)($balances[$quoteCurrency]['available'] ?? 0);

            $requiredNotional = 0.0;
            foreach ($buyLevels as $level) {
                $requiredNotional += $level['price'] * $orderSize;
            }

            $feeBps = (int) ($botConfig->fee_bps ?? config('trading.fee_bps', 35));
            $required = $requiredNotional * (1 + ($feeBps / 10000));

            if ($available < $required) {
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Insufficient %s balance for planned buy orders: required %.0f (incl. %d bps fee buffer), available %.0f',
                        strtoupper($quoteCurrency),
                        $required,
                        $feeBps,
                        $available
                    ),
                ];
            }

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Balance verification failed: ' . $e->getMessage()];
        }
    }

    /**
     * تحلیل بازار برای گرید
     */
    private function analyzeMarketForGrid(BotConfig $botConfig): array
    {
        try {
            $marketAnalysis = $this->gridCalculator->quickMarketAnalysis($botConfig->symbol ?? 'BTCIRT');

            if (!$marketAnalysis['success']) {
                return ['suitable' => false, 'reason' => 'Market analysis failed'];
            }

            $gridAnalysis = $marketAnalysis['grid_trading_analysis'];

            return [
                'suitable' => $gridAnalysis['market_suitable_for_grid'],
                'score' => $gridAnalysis['suitability_score'],
                'reason' => $gridAnalysis['market_suitable_for_grid'] ?
                    'Favorable conditions' : 'Unfavorable conditions'
            ];

        } catch (Exception $e) {
            return ['suitable' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * محاسبه قیمت مرکز بهینه
     */
    private function calculateOptimalCenterPrice(BotConfig $botConfig, array $options): float
    {
        if (isset($options['center_price'])) {
            return $options['center_price'];
        }

        $currentPrice = $this->nobitexService->getCurrentPrice($botConfig->symbol ?? 'BTCIRT');

        if (!$botConfig->center_price) {
            return $currentPrice;
        }

        // میانگین وزنی
        return ($currentPrice * 0.7) + ($botConfig->center_price * 0.3);
    }

    /**
     * پاکسازی سفارشات موجود
     */
    private function cleanupExistingOrders(BotConfig $botConfig): array
    {
        // Intentionally no try/catch swallowing here: if cancelling an existing
        // order fails, the exception must propagate to the caller (initializeGrid)
        // so the grid is NOT initialized on top of stale, still-open orders. Two
        // grids running simultaneously on the same capital is far worse than an
        // initialization failure that gets surfaced and retried.
        $existingOrders = GridOrder::where('bot_config_id', $botConfig->id)
                                 ->whereIn('status', ['placed', 'pending'])
                                 ->get();

        $cancelledCount = 0;

        foreach ($existingOrders as $order) {
            try {
                if ($order->nobitex_order_id) {
                    if ($botConfig->simulation) {
                        // SIMULATION MODE - Log only, don't cancel real order
                        Log::info('SIMULATION: Would cancel order during cleanup', [
                            'bot_id' => $botConfig->id,
                            'order_id' => $order->id,
                            'nobitex_order_id' => $order->nobitex_order_id,
                        ]);
                        $cancelledCount++;
                    } else {
                        // LIVE MODE - Cancel real order
                        $this->nobitexService->cancelOrder($order->nobitex_order_id);
                        $cancelledCount++;
                    }
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now()
                ]);

                sleep(1);
            } catch (Exception $e) {
                Log::error("Cleanup of existing grid order failed", [
                    'bot_id' => $botConfig->id,
                    'symbol' => $botConfig->symbol ?? null,
                    'grid_order_id' => $order->id,
                    'nobitex_order_id' => $order->nobitex_order_id,
                    'error' => $e->getMessage(),
                ]);

                throw new Exception(
                    "Failed to cancel existing order #{$order->id} during cleanup for bot {$botConfig->id}: {$e->getMessage()}",
                    previous: $e
                );
            }
        }

        return [
            'total_orders' => $existingOrders->count(),
            'cancelled' => $cancelledCount
        ];
    }

    /**
     * ثبت سفارشات گرید
     *
     * Initial grid setup now goes through the same GridPlanner -> GridOrderSync
     * -> GridOrderExecutor pipeline used by the rebalance path (AdjustGridJob),
     * treating initial setup as "diff against an empty existing-orders array"
     * (GridOrderExecutor::applyForBot() is void and creates its own GridOrder
     * rows + handles simulation branching internally, so the per-order
     * success/failure counts this method must return are derived by querying
     * the GridOrder rows it created, scoped to this bot and this call's time
     * window — see $callStartedAt below).
     */
    private function placeGridOrders(BotConfig $botConfig, Collection $gridLevels, float $orderSize, float $centerPrice): array
    {
        $plannedBuy = $gridLevels->where('type', 'buy')->count();
        $plannedSell = $gridLevels->where('type', 'sell')->count();

        $results = [
            'total' => 0, 'successful' => 0, 'failed' => 0, 'errors' => [],
            'planned_buy' => $plannedBuy,
            'planned_sell' => $plannedSell,
            'successful_buy' => 0,
            'successful_sell' => 0,
        ];

        $symbol = $botConfig->symbol ?? 'BTCIRT';

        // Get base currency balance once before loop (for SELL orders) — this
        // per-level affordability check (Phase 5) has no equivalent inside
        // GridPlanner/GridOrderSync/GridOrderExecutor, so it stays here as a
        // pre-filter applied to the plan's "to_place" items before executing.
        $baseCurrency = GridOrderExecutor::splitSymbol($symbol)[0];
        $btcBalance = null;
        $needsBalanceCheck = $plannedSell > 0;

        if ($needsBalanceCheck && !$botConfig->simulation) {
            try {
                $balances = $this->nobitexService->getBalances();
                $btcBalance = (float)($balances[$baseCurrency]['available'] ?? 0);

                Log::channel('trading')->info('Base currency balance for SELL orders', [
                    'bot_id' => $botConfig->id,
                    'base_currency' => $baseCurrency,
                    'available' => $btcBalance,
                ]);
            } catch (\Exception $e) {
                Log::channel('trading')->warning('Failed to get base currency balance', [
                    'bot_id' => $botConfig->id,
                    'base_currency' => $baseCurrency,
                    'error' => $e->getMessage(),
                ]);
                $btcBalance = 0;
            }
        }

        // 1) Plan — reuse the already-computed center price (the weighted
        // average of live price + bot's prior center_price from step 3 of
        // initializeGrid()) as lastPrice so GridPlanner does not re-fetch a
        // possibly-different live price at this later point in time. levels/
        // stepPct/budgetIrt map from this bot's legacy fields exactly as
        // AdjustGridJob already does for the rebalance path. fixedQty pins
        // every level to the single order size initializeGrid() already
        // computed via GridCalculatorService::calculateOrderSize(), preserving
        // the existing "one order size for the whole grid" behavior instead of
        // GridPlanner's budget-derived per-level qty.
        $fixedQty = rtrim(rtrim(sprintf('%.8f', $orderSize), '0'), '.');
        if ($fixedQty === '' || $fixedQty === '-0') {
            $fixedQty = '0';
        }

        $plan = $this->gridPlanner->plan(
            $symbol,
            lastPrice: (int) round($centerPrice),
            levels: (int) $botConfig->grid_levels,
            stepPct: (float) $botConfig->grid_spacing,
            mode: 'both',
            budgetIrt: (int) $botConfig->total_capital,
            fixedQty: $fixedQty
        );

        // 2) Diff — initial setup has no existing orders to reconcile against.
        $diff = $this->gridOrderSync->diff($plan, []);

        // Apply the SELL-side balance pre-filter (Phase 5 behavior) to
        // to_place before execution: drop sell items the account cannot
        // currently cover, deducting as we go exactly as the old loop did.
        if ($needsBalanceCheck && !$botConfig->simulation) {
            $filteredToPlace = [];
            foreach ($diff['to_place'] as $item) {
                if (($item['side'] ?? '') === 'sell') {
                    $requiredBtc = $orderSize;

                    if ($btcBalance === null || $btcBalance < $requiredBtc) {
                        Log::channel('trading')->warning('Insufficient base currency for SELL order', [
                            'bot_id' => $botConfig->id,
                            'base_currency' => $baseCurrency,
                            'price' => $item['price'],
                            'required' => $requiredBtc,
                            'available' => $btcBalance ?? 'unknown',
                        ]);
                        continue; // Skip this SELL order
                    }

                    $btcBalance -= $requiredBtc;

                    Log::channel('trading')->info('Base currency sufficient for SELL order', [
                        'bot_id' => $botConfig->id,
                        'base_currency' => $baseCurrency,
                        'price' => $item['price'],
                        'required' => $requiredBtc,
                        'remaining' => $btcBalance,
                    ]);
                }

                $filteredToPlace[] = $item;
            }
            $diff['to_place'] = $filteredToPlace;
        }

        $results['total'] = count($diff['to_place']);
        $simulation = (bool) $botConfig->simulation;

        // 3) Execute — applyForBot() is void, so success/failure counts must be
        // derived after the call rather than from a return value.
        //
        // Both SIMULATION and LIVE bots now create a real GridOrder row per
        // to_place item: live rows go through 'pending' → 'placed' (or
        // 'cancelled'/'submission_unknown' on failure), while simulation rows
        // are created directly as 'placed' with a SIM-* nobitex_order_id and no
        // real exchange call. So counts are recovered the same way for both
        // modes — by reading the rows created during this call's time window
        // back from the database, scoped to this bot.
        $callStartedAt = now();

        try {
            $this->gridOrderExecutor->applyForBot($botConfig->id, $diff, simulation: $simulation, role: 'initial_grid');
        } catch (\Throwable $e) {
            Log::error('GridOrderExecutor::applyForBot threw during initial grid setup', [
                'bot_id' => $botConfig->id,
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = $e->getMessage();
        }

        $createdOrders = GridOrder::where('bot_config_id', $botConfig->id)
            ->where('created_at', '>=', $callStartedAt)
            ->get();

        foreach ($createdOrders as $order) {
            if (in_array($order->status, ['placed', 'filled', 'partially_filled'], true)) {
                $results['successful']++;
                if ($order->type === 'buy') {
                    $results['successful_buy']++;
                } else {
                    $results['successful_sell']++;
                }
            } else {
                // 'cancelled' (build failure before any API call) or
                // 'submission_unknown' (API call attempted, outcome unknown)
                // both count as failed for this bot's init-health purposes —
                // submission_unknown rows require separate reconciliation
                // (out of scope here) before being treated as live orders.
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Order %s (%s @ %s) ended in status "%s"',
                    $order->id,
                    $order->type,
                    $order->price,
                    $order->status
                );
            }
        }

        // Items skipped by GridOrderSync for being below the minimum order
        // value, or by the SELL-side balance pre-filter above, were never
        // attempted at all — they are not "failed" placements, but they do
        // reduce $results['total'] versus the originally planned level count,
        // which evaluateInitializationHealth() already accounts for via its
        // ratio-of(successful/total) and per-side minimum checks.

        return $results;
    }
}
