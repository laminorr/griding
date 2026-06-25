<?php

namespace App\Services;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
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

    public function __construct(NobitexService $nobitexService, GridCalculatorService $gridCalculator)
    {
        $this->nobitexService = $nobitexService;
        $this->gridCalculator = $gridCalculator;
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
            if (!$marketAnalysis['suitable'] && !($options['force_start'] ?? false)) {
                throw new Exception('Market conditions not suitable: ' . $marketAnalysis['reason']);
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

            // 6. پاکسازی سفارشات قدیمی
            $this->cleanupExistingOrders($botConfig);

            // 7. ثبت سفارشات جدید
            $placementResult = $this->placeGridOrders(
                $botConfig,
                $gridResult['grid_levels'],
                $orderSizeResult['crypto_amount']
            );

            // 8. به‌روزرسانی تنظیمات ربات
            $botConfig->update([
                'center_price' => $centerPrice,
                'is_active' => true,
                'status' => 'running',
                'started_at' => now(),
                'last_rebalance_at' => now()
            ]);

            Log::info("Grid initialization completed successfully", [
                'bot_id' => $botConfig->id,
                'center_price' => $centerPrice,
                'successful_orders' => $placementResult['successful']
            ]);

            return [
                'success' => true,
                'message' => 'Grid initialized successfully',
                'data' => [
                    'center_price' => $centerPrice,
                    'total_orders' => $placementResult['total'],
                    'successful_orders' => $placementResult['successful'],
                    'failed_orders' => $placementResult['failed'],
                    'order_size_crypto' => $orderSizeResult['crypto_amount'],
                    'market_analysis' => $marketAnalysis
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
                'status' => 'failed',
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
     * استخراج ارز پایه از نماد (مثل ETHIRT -> eth)
     * منطق مشابه GridOrderExecutor::splitSymbol برای حفظ یکنواختی.
     */
    private function baseCurrency(string $symbol): string
    {
        $s = strtolower(str_replace('-', '', trim($symbol)));

        if ($s === '' || strlen($s) < 6) {
            throw new Exception("Bad symbol: {$symbol}");
        }
        if (str_ends_with($s, 'irt')) {
            return substr($s, 0, -3);
        }
        if (str_ends_with($s, 'usdt')) {
            return substr($s, 0, -4);
        }
        if (strlen($s) === 6) {
            return substr($s, 0, 3);
        }

        throw new Exception("Unsupported symbol: {$symbol}");
    }

    /**
     * ثبت سفارشات گرید
     */
    private function placeGridOrders(BotConfig $botConfig, Collection $gridLevels, float $orderSize): array
    {
        $results = ['total' => 0, 'successful' => 0, 'failed' => 0, 'errors' => []];

        // Get base currency balance once before loop (for SELL orders)
        $baseCurrency = $this->baseCurrency($botConfig->symbol ?? 'BTCIRT');
        $btcBalance = null;
        $needsBalanceCheck = $gridLevels->contains('type', 'sell');

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

        foreach ($gridLevels as $level) {
            // Check BTC balance for SELL orders
            if ($level['type'] === 'sell' && !$botConfig->simulation) {
                $requiredBtc = $orderSize;

                if ($btcBalance === null || $btcBalance < $requiredBtc) {
                    Log::channel('trading')->warning('Insufficient base currency for SELL order', [
                        'bot_id' => $botConfig->id,
                        'base_currency' => $baseCurrency,
                        'price' => $level['price'],
                        'required' => $requiredBtc,
                        'available' => $btcBalance ?? 'unknown',
                    ]);
                    continue; // Skip this SELL order
                }

                // Deduct from available balance for next iteration
                $btcBalance -= $requiredBtc;

                Log::channel('trading')->info('Base currency sufficient for SELL order', [
                    'bot_id' => $botConfig->id,
                    'base_currency' => $baseCurrency,
                    'price' => $level['price'],
                    'required' => $requiredBtc,
                    'remaining' => $btcBalance,
                ]);
            }

            $results['total']++;

            try {
                $initSymbol      = $botConfig->symbol ?? 'BTCIRT';
                $initClientId    = GridOrder::buildClientOrderId(
                    $botConfig->id,
                    $initSymbol,
                    $level['type'],
                    (int) round($level['price']),
                    (int) ($level['level'] ?? 0)
                );

                // ایجاد رکورد محلی
                $gridOrder = GridOrder::create([
                    'bot_config_id'   => $botConfig->id,
                    'price'           => $level['price'],
                    'amount'          => $orderSize,
                    'type'            => $level['type'],
                    'status'          => 'pending',
                    'client_order_id' => $initClientId,
                ]);

                // ثبت در نوبیتکس
                if ($botConfig->simulation) {
                    // SIMULATION MODE - Log only, don't create real order
                    Log::info('SIMULATION: Would create grid order', [
                        'bot_id' => $botConfig->id,
                        'type' => $level['type'],
                        'price' => $level['price'],
                        'amount' => $orderSize,
                        'symbol' => $botConfig->symbol ?? 'BTCIRT',
                    ]);

                    // Create simulated successful order result
                    $orderResult = [
                        'success' => true,
                        'order_id' => 'SIM-' . uniqid() . '-' . time(),
                        'status' => 'Active',
                        'message' => 'Simulated order created'
                    ];
                } else {
                    // LIVE MODE - Create real order
                    $symbol = $botConfig->symbol ?? 'BTCIRT';
                    // Parse symbol to get src/dst currencies (e.g., 'BTCIRT' -> 'btc', 'irt')
                    $srcCurrency = strtolower(substr($symbol, 0, -3)); // Remove last 3 chars (IRT)
                    $dstCurrency = strtolower(substr($symbol, -3));    // Get last 3 chars (IRT)

                    // Get precision for this symbol from config (default 8 for BTC)
                    $precision = config("trading.exchange.precision.{$symbol}.qty_decimals") ?? 8;

                    // Convert to string early, before any float operations damage precision
                    $amountStr = rtrim(rtrim(sprintf('%.8f', $orderSize), '0'), '.');
                    $priceStr = (string) (int) round($level['price']);

                    // Create proper DTO
                    $dto = new CreateOrderDto(
                        side: $level['type'] === 'buy' ? OrderSide::BUY : OrderSide::SELL,
                        execution: ExecutionType::LIMIT,
                        srcCurrency: $srcCurrency,
                        dstCurrency: $dstCurrency,
                        amountBase: $amountStr,  // String: "0.0001678"
                        priceIRT: (int) round($level['price'])  // Pass as int, will be converted to clean string in DTO
                    );

                    // Log attempt before calling Nobitex
                    Log::info('Attempting to create Nobitex order', [
                        'bot_id' => $botConfig->id,
                        'type' => $level['type'],
                        'price' => $level['price'],
                        'amount' => $orderSize,
                        'symbol' => $symbol
                    ]);

                    try {
                        $orderResponse = $this->nobitexService->createOrder($dto);

                        Log::info('Nobitex order response received', [
                            'bot_id' => $botConfig->id,
                            'ok' => $orderResponse->ok,
                            'orderId' => $orderResponse->orderId ?? 'NULL',
                            'message' => $orderResponse->message ?? 'N/A'
                        ]);

                        // Convert response to array format expected by the rest of the code
                        $orderResult = [
                            'success' => $orderResponse->ok,
                            'order_id' => $orderResponse->orderId,
                            'status' => $orderResponse->ok ? 'Active' : 'Failed',
                            'error' => $orderResponse->message ?? 'Order creation failed'
                        ];

                    } catch (\Throwable $e) {
                        Log::error('Exception in createOrder', [
                            'bot_id' => $botConfig->id,
                            'exception' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        $orderResult = [
                            'success' => false,
                            'error' => $e->getMessage()
                        ];
                    }
                }

                if ($orderResult['success']) {
                    $gridOrder->update([
                        'status' => 'placed',
                        'nobitex_order_id' => $orderResult['order_id'],
                        'placed_at' => now()
                    ]);

                    $results['successful']++;
                } else {
                    Log::error('Failed to create grid order', [
                        'bot_id' => $botConfig->id,
                        'grid_order_id' => $gridOrder->id,
                        'error' => $orderResult['error'] ?? 'Unknown error'
                    ]);

                    $gridOrder->update([
                        'status' => 'failed',
                        'error_message' => $orderResult['error']
                    ]);

                    $results['failed']++;
                    $results['errors'][] = $orderResult['error'] ?? 'Unknown error';
                }

                sleep(2); // Rate limiting

            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Exception at {$level['price']}: {$e->getMessage()}";
            }
        }

        return $results;
    }
}
