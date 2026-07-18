<?php

namespace App\Services;

use App\Models\GridOrder;
use App\Models\BotConfig;
use App\Support\Money;
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
    private KillSwitchService $killSwitch;

    public function __construct(
        NobitexService $nobitexService,
        GridCalculatorService $gridCalculator,
        BotActivityLogger $activityLogger,
        GridPlanner $gridPlanner,
        GridOrderSync $gridOrderSync,
        GridOrderExecutor $gridOrderExecutor,
        KillSwitchService $killSwitch
    ) {
        $this->nobitexService = $nobitexService;
        $this->gridCalculator = $gridCalculator;
        $this->activityLogger = $activityLogger;
        $this->gridPlanner = $gridPlanner;
        $this->gridOrderSync = $gridOrderSync;
        $this->gridOrderExecutor = $gridOrderExecutor;
        $this->killSwitch = $killSwitch;
    }

    /**
     * راه‌اندازی کامل گرید
     */
    public function initializeGrid(BotConfig $botConfig, array $options = []): array
    {
        try {
            Log::info("Starting grid initialization", ['bot_id' => $botConfig->id]);

            // 0. Kill Switch gate (Phase 11 Step 3). Evaluated FIRST, before any
            // order-placing work: if a risk threshold (stop_loss / max_drawdown)
            // is already breached, refuse to (re)initialize the grid. This also
            // covers the "re-run on an already-killed bot" case — the switch
            // returns triggered=true and we abort without touching the exchange.
            $kill = $this->killSwitch->checkAndTrigger($botConfig);
            if ($kill['triggered']) {
                Log::warning('Grid initialization aborted by Kill Switch', [
                    'bot_id'  => $botConfig->id,
                    'reason'  => $kill['reason'],
                    'details' => $kill['details'],
                ]);

                return [
                    'success'    => false,
                    'error'      => 'Kill switch active (' . $kill['reason'] . '): grid initialization refused.',
                    'error_code' => 'KILL_SWITCH_ACTIVE',
                    'reason'     => $kill['reason'],
                    'details'    => $kill['details'],
                ];
            }

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
            // Pass the bot's mode (Phase 11 Step 6) so directional bots generate
            // their levels on a single side, keeping planned_buy/planned_sell
            // consistent with what GridPlanner places in placeGridOrders().
            $gridResult = $this->gridCalculator->calculateGridLevels(
                $centerPrice,
                $botConfig->grid_spacing,
                $botConfig->grid_levels,
                mode: $this->resolveBotMode($botConfig)
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
                // Stable Kill Switch stop-loss anchor: the mid price used for
                // planning, captured once here at (re)initialization. Distinct
                // from center_price, which drifts on later rebalances. (Phase 11
                // Step 3.)
                'grid_center_price' => $centerPrice,
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
            // حالت شبیه‌سازی نباید به وضعیت زندهٔ صرافی وابسته باشد (اصل فاز ۱۱ گام ۵).
            // Simulation must be independent of the live exchange: skip the API-key
            // check and the authenticated healthCheck() entirely, so an expired/absent
            // token can never block simulation-mode grid initialization.
            if ($botConfig->simulation) {
                Log::info('Preflight checks skipped (simulation mode)', ['bot_id' => $botConfig->id]);
                return ['success' => true];
            }

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

        // Success ratio via bcmath so 4/5 etc. is an exact decimal string
        // ("0.8") compared against the unchanged 80% threshold, rather than a
        // drifting IEEE-754 double.
        $ratio = $total > 0 ? Money::div((string) $successful, (string) $total) : '0';

        $sideMinimumMet = true;
        if ($plannedBuy > 0 && $plannedSell > 0) {
            $sideMinimumMet = $successfulBuy >= 1 && $successfulSell >= 1;
        } elseif ($plannedBuy > 0) {
            $sideMinimumMet = $successfulBuy >= 1;
        } elseif ($plannedSell > 0) {
            $sideMinimumMet = $successfulSell >= 1;
        }

        if ($sideMinimumMet && Money::compare($ratio, '0.8') >= 0) {
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
     * تعیین حالت معاملاتی ربات برای برنامه‌ریزی گرید (Phase 11 Step 6).
     *
     * Resolve a bot's trading mode for planning. Legacy bots may carry a null or
     * unexpected mode value; rather than fail hard we default to 'both' and log a
     * warning, so directional bots ('buy'/'sell') are honored end-to-end while
     * old rows keep working exactly as before.
     */
    private function resolveBotMode(BotConfig $botConfig): string
    {
        $mode = strtolower(trim((string) ($botConfig->mode ?? '')));
        if (!in_array($mode, ['both', 'buy', 'sell'], true)) {
            Log::channel('trading')->warning('Bot mode invalid or unset; defaulting to both', [
                'bot_id' => $botConfig->id,
                'mode'   => $botConfig->mode,
            ]);
            return 'both';
        }
        return $mode;
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

        // Sell-only grids place no buy orders, so no quote currency (IRT) is
        // needed — skip the quote balance check explicitly (Phase 11 Step 6).
        // The buyLevels->isEmpty() guard below would also short-circuit once
        // calculateGridLevels honors mode, but make the intent unambiguous.
        if ($this->resolveBotMode($botConfig) === 'sell') {
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
            // Balance comes back as a decimal string from the exchange; keep it
            // a string (normalize handles the ?? 0 int fallback) so the compare
            // below is exact.
            $available = Money::normalize($balances[$quoteCurrency]['available'] ?? 0);

            // $orderSize arrives as a float (signature preserved), so normalize
            // it once to a bcmath-safe string before the per-level multiply.
            $orderSizeStr = Money::normalize($orderSize);

            $requiredNotional = '0';
            foreach ($buyLevels as $level) {
                $requiredNotional = Money::add(
                    $requiredNotional,
                    Money::mul(Money::normalize($level['price']), $orderSizeStr)
                );
            }

            $feeBps = (int) ($botConfig->fee_bps ?? config('trading.fee_bps', 35));
            // fee_rate = fee_bps / 10000 (e.g. 35 bps -> "0.0035"); the buffer is
            // notional * fee_rate, added on top of the raw notional requirement.
            $feeRate = Money::div((string) $feeBps, '10000');
            $required = Money::add($requiredNotional, Money::mul($requiredNotional, $feeRate));

            if (Money::compare($available, $required) < 0) {
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Insufficient %s balance for planned buy orders: required %s (incl. %d bps fee buffer), available %s',
                        strtoupper($quoteCurrency),
                        Money::round($required, 0),
                        $feeBps,
                        Money::round($available, 0)
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
        // Phase 11 Step 6 — honor the bot's directional mode. In 'buy' mode there
        // is no sell side, so the SELL-side base-balance pre-filter is skipped
        // explicitly (plannedSell is already 0 once calculateGridLevels honors
        // mode, but the extra guard makes the intent unambiguous).
        $mode = $this->resolveBotMode($botConfig);
        $needsBalanceCheck = $plannedSell > 0 && $mode !== 'buy';

        if ($needsBalanceCheck && !$botConfig->simulation) {
            try {
                $balances = $this->nobitexService->getBalances();
                // Keep the base-currency balance as a decimal string; the
                // per-SELL affordability check below deducts from it via bcmath.
                $btcBalance = Money::normalize($balances[$baseCurrency]['available'] ?? 0);

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
                $btcBalance = '0';
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

        // Balance-aware SELL sizing (Phase 11 Step 5) — INITIAL PLACEMENT ONLY.
        // If the account already holds enough base currency (BTC for BTCIRT) to
        // meaningfully back the sell side, redirect the sell levels to use that
        // existing inventory instead of requiring fresh IRT to acquire it. The
        // buy side is untouched. presetBaseQty stays null (naive plan, identical
        // to before this step) for simulation bots, when balance is unavailable,
        // when holdings are below threshold, or when holdings are so large they
        // exceed the whole budget. Rebalance (AdjustGridJob) is intentionally
        // NOT covered here — see method note; that path has open-cycle
        // accounting that makes preset sizing more involved (follow-up).
        $presetBaseQty = $this->computePresetBaseQty($botConfig, $btcBalance, $centerPrice);

        $plan = $this->gridPlanner->plan(
            $symbol,
            lastPrice: (int) round($centerPrice),
            levels: (int) $botConfig->grid_levels,
            stepPct: (float) $botConfig->grid_spacing,
            mode: $mode,
            budgetIrt: (int) $botConfig->total_capital,
            fixedQty: $fixedQty,
            presetBaseQty: $presetBaseQty
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
                    // Use the SELL item's actual planned quantity, not the
                    // uniform $orderSize: with balance-aware sizing (Phase 11
                    // Step 5) sell quantities come from presetBaseQty and differ
                    // from $orderSize. When preset is not engaged the plan sizes
                    // every level at fixedQty == $orderSize, so this is
                    // numerically identical to the previous behavior.
                    $requiredBtc = Money::normalize($item['quantity'] ?? $orderSize);

                    if ($btcBalance === null || Money::compare($btcBalance, $requiredBtc) < 0) {
                        Log::channel('trading')->warning('Insufficient base currency for SELL order', [
                            'bot_id' => $botConfig->id,
                            'base_currency' => $baseCurrency,
                            'price' => $item['price'],
                            'required' => $requiredBtc,
                            'available' => $btcBalance ?? 'unknown',
                        ]);
                        continue; // Skip this SELL order
                    }

                    $btcBalance = Money::sub($btcBalance, $requiredBtc);

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

    /**
     * محاسبهٔ presetBaseQty برای مقداردهی فروش‌ها بر پایهٔ موجودی موجود ارز پایه.
     *
     * Balance-aware SELL sizing decision (Phase 11 Step 5). Given the base
     * currency the account currently has available and the planning mid price,
     * decide whether the grid's sell side should be backed by that existing
     * inventory rather than by fresh IRT. Returns the base quantity to hand to
     * GridPlanner as its presetBaseQty (the full available base), or null to
     * keep the naive, pre-Step-5 plan.
     *
     * Returns null (naive plan) when ANY of the following hold — each logged:
     *   - $baseAvailable is null (balance API was unavailable / not fetched, e.g.
     *     simulation bots never fetch it) → fail safe to naive.
     *   - the bot's mode is 'buy' only → there is no sell side to back.
     *   - budget or mid is non-positive → cannot form a meaningful threshold.
     *   - available base value < half a sell side's notional (below threshold)
     *     → not worth restructuring the grid.
     *   - available base value exceeds the WHOLE budget → the account is
     *     effectively all crypto with no room for a normal grid; use naive and
     *     let the operator decide.
     *
     * @param string|null $baseAvailable  موجودی در دسترس ارز پایه (decimal string) یا null
     */
    private function computePresetBaseQty(BotConfig $botConfig, ?string $baseAvailable, float $centerPrice): ?string
    {
        // Simulation bots never fetch a real balance ($baseAvailable is null),
        // so this returns null and the simulation branch is left unchanged.
        if ($baseAvailable === null) {
            return null;
        }

        // mode='buy' only → no sells to back with inventory. (mode='both' and
        // mode='sell' both have a sell side and are handled by the logic below.)
        $mode = strtolower(trim((string) ($botConfig->mode ?? 'both')));
        if ($mode === 'buy') {
            Log::channel('trading')->info('Balance-aware sizing skipped: buy-only mode', [
                'bot_id' => $botConfig->id,
            ]);
            return null;
        }

        $baseAvailable = Money::normalize($baseAvailable);
        if (!Money::isPositive($baseAvailable)) {
            return null; // nothing on hand → naive plan (also the common case)
        }

        $mid = (int) round($centerPrice);
        $budgetIrt = (int) $botConfig->total_capital;
        if ($mid <= 0 || $budgetIrt <= 0) {
            Log::channel('trading')->warning('Balance-aware sizing skipped: non-positive mid/budget', [
                'bot_id' => $botConfig->id,
                'mid'    => $mid,
                'budget' => $budgetIrt,
            ]);
            return null;
        }

        // Notional value of the base we hold, at the planning mid price.
        $baseAvailableNotional = Money::mul($baseAvailable, (string) $mid);

        // In 'both' mode GridPlanner splits the budget evenly across the two
        // sides, so a full naive sell side is ~half the budget. In 'sell'-only
        // mode the whole budget is the sell side. threshold = half of that.
        $naiveSellNotional = ($mode === 'sell')
            ? (string) $budgetIrt
            : Money::div((string) $budgetIrt, '2');
        $threshold = Money::mul($naiveSellNotional, '0.5');

        // Safety: holdings worth more than the entire budget mean the account is
        // essentially all crypto — there is no room for a normal buy+sell grid.
        // Fall back to naive rather than building a lopsided, all-sell grid.
        if (Money::compare($baseAvailableNotional, (string) $budgetIrt) > 0) {
            Log::channel('trading')->warning('Balance-aware sizing skipped: holdings exceed full budget', [
                'bot_id'                  => $botConfig->id,
                'base_available'          => $baseAvailable,
                'base_available_notional' => $baseAvailableNotional,
                'budget_irt'              => (string) $budgetIrt,
            ]);
            return null;
        }

        // Below threshold → not enough existing inventory to bother; naive plan.
        if (Money::compare($baseAvailableNotional, $threshold) < 0) {
            Log::channel('trading')->info('Balance-aware sizing not engaged: holdings below threshold', [
                'bot_id'                  => $botConfig->id,
                'base_available'          => $baseAvailable,
                'base_available_notional' => $baseAvailableNotional,
                'threshold'               => $threshold,
            ]);
            return null;
        }

        // Engage: dedicate the full available base to the sell side. GridPlanner
        // splits it across the sell levels. This deploys LESS IRT (the sell
        // inventory is already owned) for the SAME grid coverage.
        Log::channel('trading')->info('Balance-aware sizing engaged: backing sells with existing holdings', [
            'bot_id'                  => $botConfig->id,
            'mode'                    => $mode,
            'preset_base_qty'         => $baseAvailable,
            'base_available_notional' => $baseAvailableNotional,
            'naive_sell_notional'     => $naiveSellNotional,
            'threshold'               => $threshold,
        ]);

        return $baseAvailable;
    }
}
