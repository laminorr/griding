<?php

namespace App\Services;

use App\Models\GridOrder;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Exception;

/**
 * TradingEngineService - Professional Grid Trading Engine
 * 
 * موتور معاملاتی حرفه‌ای Grid Trading
 * مدیریت کامل چرخه حیات گرید
 * 
 * @version 3.0.0
 * @author GridBot Team
 */
class TradingEngineService
{
    private NobitexService $nobitexService;
    private GridCalculatorService $gridCalculator;
    
    // Configuration Constants
    const MAX_CONCURRENT_ORDERS = 20;
    const ORDER_RETRY_LIMIT = 3;
    const GRID_REBALANCE_THRESHOLD = 5.0; // درصد انحراف از مرکز
    const EMERGENCY_STOP_THRESHOLD = 15.0; // درصد ضرر اضطراری
    const ORDER_CHECK_DELAY = 2; // ثانیه بین بررسی سفارشات
    const REBALANCE_COOLDOWN = 1800; // 30 دقیقه cooldown
    const MAX_FAILED_ORDERS = 5;
    const MIN_PROFIT_THRESHOLD = 1000; // ریال

    public function __construct(NobitexService $nobitexService, GridCalculatorService $gridCalculator)
    {
        $this->nobitexService = $nobitexService;
        $this->gridCalculator = $gridCalculator;
    }

    // ============ MAIN PUBLIC METHODS ============

    /**
     * راه‌اندازی کامل گرید
     */
    public function initializeGrid(BotConfig $botConfig, array $options = []): array
    {
        try {
            DB::beginTransaction();
            
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
            $orderSizeResult = $this->gridCalculator->calculateOrderSize(
                $botConfig->total_capital,
                $botConfig->active_capital_percent,
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
            
            DB::commit();
            
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
            DB::rollBack();
            
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
     * مدیریت معاملات گرید (Main Loop)
     */
    public function manageGridTrading(BotConfig $botConfig): array
    {
        if (!$botConfig->is_active) {
            return [
                'success' => false,
                'message' => 'Bot is not active'
            ];
        }

        try {
            $results = [
                'bot_id' => $botConfig->id,
                'timestamp' => now()->toISOString(),
                'orders_checked' => 0,
                'orders_filled' => 0,
                'trades_completed' => 0,
                'rebalance_performed' => false,
                'risk_alerts' => [],
                'errors' => []
            ];
            
            Log::info("Starting grid management cycle", ['bot_id' => $botConfig->id]);
            
            // 1. بررسی سلامت
            $healthCheck = $this->performHealthCheck($botConfig);
            if (!$healthCheck['healthy'] && $healthCheck['critical']) {
                return $this->handleCriticalError($botConfig, $healthCheck['issue']);
            }
            
            // 2. بررسی و پردازش سفارشات
            $orderResults = $this->checkAndProcessOrders($botConfig);
            $results['orders_checked'] = $orderResults['checked'];
            $results['orders_filled'] = $orderResults['filled'];
            $results['trades_completed'] = $orderResults['trades_completed'];
            $results['errors'] = $orderResults['errors'];
            
            // 3. بررسی مدیریت ریسک
            $riskResults = $this->performRiskManagementChecks($botConfig);
            $results['risk_alerts'] = $riskResults['alerts'];
            
            if ($riskResults['emergency_stop']) {
                return $this->executeEmergencyStop($botConfig, $riskResults['reason']);
            }
            
            // 4. بررسی نیاز به rebalance
            $rebalanceCheck = $this->checkRebalanceNeeds($botConfig);
            if ($rebalanceCheck['needed']) {
                $rebalanceResult = $this->executeRebalance($botConfig, $rebalanceCheck);
                $results['rebalance_performed'] = $rebalanceResult['success'];
            }
            
            // 5. به‌روزرسانی متریک‌های عملکرد
            $this->updatePerformanceMetrics($botConfig);
            
            // 6. به‌روزرسانی وضعیت ربات
            $botConfig->update([
                'last_check_at' => now(),
                'status' => 'running'
            ]);
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            Log::error("Grid management failed", [
                'bot_id' => $botConfig->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * توقف ایمن گرید
     */
    public function stopGrid(BotConfig $botConfig, array $options = []): array
    {
        try {
            DB::beginTransaction();
            
            Log::info("Starting grid shutdown", ['bot_id' => $botConfig->id]);
            
            // 1. لغو همه سفارشات فعال
            $cancelResult = $this->cancelAllActiveOrders($botConfig);
            
            // 2. محاسبه خلاصه عملکرد
            $performanceSummary = $this->generatePerformanceSummary($botConfig);
            
            // 3. به‌روزرسانی وضعیت ربات
            $botConfig->update([
                'is_active' => false,
                'status' => $options['status'] ?? 'stopped',
                'stopped_at' => now(),
                'stop_reason' => $options['reason'] ?? 'Manual stop'
            ]);
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Grid stopped successfully',
                'results' => [
                    'cancelled_orders' => $cancelResult['cancelled'],
                    'performance_summary' => $performanceSummary
                ]
            ];
            
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error("Grid shutdown failed", [
                'bot_id' => $botConfig->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // ============ ORDER MANAGEMENT ============

    /**
     * بررسی و پردازش سفارشات
     */
    private function checkAndProcessOrders(BotConfig $botConfig): array
    {
        $results = [
            'checked' => 0,
            'filled' => 0,
            'trades_completed' => 0,
            'errors' => []
        ];
        
        try {
            $activeOrders = GridOrder::where('bot_config_id', $botConfig->id)
                                   ->whereIn('status', ['placed', 'partially_filled'])
                                   ->get();
            
            foreach ($activeOrders as $order) {
                $results['checked']++;
                
                try {
                    sleep(self::ORDER_CHECK_DELAY);
                    
                    // بررسی وضعیت در نوبیتکس
                    $statusResult = $this->nobitexService->getOrderStatus($order->nobitex_order_id);
                    
                    if (!$statusResult['success']) {
                        $results['errors'][] = "Failed to check order {$order->id}";
                        continue;
                    }
                    
                    // پردازش بر اساس وضعیت
                    $processResult = $this->processOrderStatusUpdate($order, $statusResult);
                    
                    if ($processResult['filled']) {
                        $results['filled']++;
                        
                        // بررسی تشکیل معامله کامل
                        $tradeResult = $this->checkForCompleteTrade($order);
                        if ($tradeResult['completed']) {
                            $results['trades_completed']++;
                        }
                        
                        // ایجاد سفارش جایگزین
                        $this->createReplacementOrder($order, $botConfig);
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Error processing order {$order->id}: {$e->getMessage()}";
                }
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Order check failed: {$e->getMessage()}";
        }
        
        return $results;
    }

    /**
     * پردازش به‌روزرسانی وضعیت سفارش
     */
    private function processOrderStatusUpdate(GridOrder $order, array $statusResult): array
    {
        $result = ['filled' => false, 'updated' => false];
        
        $newStatus = $statusResult['status'];
        $filledAmount = $statusResult['filled_amount'] ?? 0;
        $avgPrice = $statusResult['price'] ?? $order->price;
        
        switch ($newStatus) {
            case NobitexService::ORDER_STATUS_FILLED:
                $order->update([
                    'status' => 'filled',
                    'filled_amount' => $filledAmount,
                    'average_price' => $avgPrice,
                    'filled_at' => now()
                ]);
                
                $result['filled'] = true;
                $result['updated'] = true;
                
                Log::info("Order filled", [
                    'order_id' => $order->id,
                    'price' => $avgPrice,
                    'amount' => $filledAmount
                ]);
                break;
                
            case NobitexService::ORDER_STATUS_PARTIALLY_FILLED:
                $order->update([
                    'status' => 'partially_filled',
                    'filled_amount' => $filledAmount,
                    'average_price' => $avgPrice
                ]);
                
                $result['updated'] = true;
                break;
                
            case NobitexService::ORDER_STATUS_CANCELED:
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now()
                ]);
                
                $result['updated'] = true;
                break;
        }
        
        return $result;
    }

    /**
     * بررسی تشکیل معامله کامل
     */
    private function checkForCompleteTrade(GridOrder $filledOrder): array
    {
        try {
            $pairOrder = $this->findPairOrder($filledOrder);
            
            if ($pairOrder && $pairOrder->status === 'filled') {
                $trade = $this->createCompletedTrade($filledOrder, $pairOrder);
                
                return [
                    'completed' => true,
                    'trade_id' => $trade->id,
                    'profit' => $trade->net_profit
                ];
            }
            
            return ['completed' => false];
            
        } catch (Exception $e) {
            Log::error("Trade completion check failed", [
                'order_id' => $filledOrder->id,
                'error' => $e->getMessage()
            ]);
            
            return ['completed' => false];
        }
    }

    /**
     * یافتن سفارش جفت
     */
    private function findPairOrder(GridOrder $order): ?GridOrder
    {
        if ($order->type === 'buy') {
            return GridOrder::where('bot_config_id', $order->bot_config_id)
                           ->where('type', 'sell')
                           ->where('price', '>', $order->price)
                           ->where('status', 'filled')
                           ->orderBy('price', 'asc')
                           ->first();
        } else {
            return GridOrder::where('bot_config_id', $order->bot_config_id)
                           ->where('type', 'buy')
                           ->where('price', '<', $order->price)
                           ->where('status', 'filled')
                           ->orderBy('price', 'desc')
                           ->first();
        }
    }

    /**
     * ایجاد معامله تکمیل شده
     */
    private function createCompletedTrade(GridOrder $buyOrder, GridOrder $sellOrder): CompletedTrade
    {
        // مرتب‌سازی صحیح
        if ($buyOrder->type === 'sell') {
            [$buyOrder, $sellOrder] = [$sellOrder, $buyOrder];
        }
        
        $amount = min($buyOrder->filled_amount, $sellOrder->filled_amount);
        $buyPrice = $buyOrder->average_price ?? $buyOrder->price;
        $sellPrice = $sellOrder->average_price ?? $sellOrder->price;
        
        $grossProfit = ($sellPrice - $buyPrice) * $amount;
        $fee = $this->calculateTradeFee($buyPrice * $amount) + $this->calculateTradeFee($sellPrice * $amount);
        $netProfit = $grossProfit - $fee;
        
        $trade = CompletedTrade::create([
            'bot_config_id' => $buyOrder->bot_config_id,
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buy_price' => $buyPrice,
            'sell_price' => $sellPrice,
            'amount' => $amount,
            'gross_profit' => $grossProfit,
            'fee' => $fee,
            'net_profit' => $netProfit,
            'profit_percent' => ($netProfit / ($buyPrice * $amount)) * 100,
            'completed_at' => now()
        ]);
        
        Log::info("Completed trade created", [
            'trade_id' => $trade->id,
            'profit' => $netProfit
        ]);
        
        return $trade;
    }

    /**
     * ایجاد سفارش جایگزین
     */
    private function createReplacementOrder(GridOrder $filledOrder, BotConfig $botConfig): array
    {
        try {
            $currentPrice = $this->nobitexService->getCurrentPrice($botConfig->symbol ?? 'BTCIRT');
            $newPrice = $this->calculateReplacementPrice($filledOrder, $currentPrice, $botConfig);
            
            if (!$newPrice) {
                return ['success' => false, 'reason' => 'No suitable replacement price'];
            }

            if ($botConfig->simulation) {
                // SIMULATION MODE - Log only, don't create real replacement order
                Log::info('SIMULATION: Would create replacement order', [
                    'bot_id' => $botConfig->id,
                    'filled_order_id' => $filledOrder->id,
                    'type' => $filledOrder->type,
                    'price' => $newPrice,
                    'amount' => $filledOrder->amount,
                    'symbol' => $botConfig->symbol ?? 'BTCIRT',
                ]);

                // Create simulated successful order result
                $orderResult = [
                    'success' => true,
                    'order_id' => 'SIM-' . uniqid() . '-' . time(),
                    'status' => 'Active',
                    'message' => 'Simulated replacement order created'
                ];
            } else {
                // LIVE MODE - Create real replacement order
                $orderResult = $this->nobitexService->createOrder(
                    $filledOrder->type,
                    $newPrice,
                    $filledOrder->amount,
                    $botConfig->symbol ?? 'BTCIRT'
                );
            }

            if ($orderResult['success']) {
                GridOrder::create([
                    'bot_config_id' => $botConfig->id,
                    'price' => $newPrice,
                    'amount' => $filledOrder->amount,
                    'type' => $filledOrder->type,
                    'status' => 'placed',
                    'nobitex_order_id' => $orderResult['order_id'],
                    'priority' => $filledOrder->priority,
                    'level' => $filledOrder->level,
                    'parent_order_id' => $filledOrder->id
                ]);
                
                return ['success' => true, 'order_id' => $orderResult['order_id']];
            }
            
            return ['success' => false, 'error' => $orderResult['error']];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============ RISK MANAGEMENT ============

    /**
     * بررسی‌های مدیریت ریسک
     */
    private function performRiskManagementChecks(BotConfig $botConfig): array
    {
        $alerts = [];
        $emergencyStop = false;
        $reason = '';
        
        try {
            // بررسی drawdown
            $currentDrawdown = $this->calculateCurrentDrawdown($botConfig);
            if ($currentDrawdown > self::EMERGENCY_STOP_THRESHOLD) {
                $emergencyStop = true;
                $reason = "Emergency drawdown threshold exceeded: {$currentDrawdown}%";
            } elseif ($currentDrawdown > ($botConfig->stop_loss_percent ?? 10)) {
                $alerts[] = "Drawdown warning: {$currentDrawdown}%";
            }
            
            // بررسی انحراف قیمت
            $priceDeviation = $this->calculatePriceDeviation($botConfig);
            if ($priceDeviation > self::GRID_REBALANCE_THRESHOLD * 2) {
                $alerts[] = "High price deviation: {$priceDeviation}%";
            }
            
        } catch (Exception $e) {
            $alerts[] = "Risk check error: {$e->getMessage()}";
        }
        
        return [
            'alerts' => $alerts,
            'emergency_stop' => $emergencyStop,
            'reason' => $reason
        ];
    }

    /**
     * اجرای توقف اضطراری
     */
    private function executeEmergencyStop(BotConfig $botConfig, string $reason): array
    {
        Log::critical("Emergency stop triggered", [
            'bot_id' => $botConfig->id,
            'reason' => $reason
        ]);
        
        $stopResult = $this->stopGrid($botConfig, [
            'status' => 'emergency_stopped',
            'reason' => $reason
        ]);
        
        return [
            'success' => true,
            'emergency_stop' => true,
            'reason' => $reason,
            'stop_result' => $stopResult
        ];
    }

    // ============ REBALANCING ============

    /**
     * بررسی نیاز به تعادل مجدد
     */
    private function checkRebalanceNeeds(BotConfig $botConfig): array
    {
        try {
            // بررسی cooldown
            if ($botConfig->last_rebalance_at && 
                $botConfig->last_rebalance_at->diffInSeconds(now()) < self::REBALANCE_COOLDOWN) {
                return ['needed' => false, 'reason' => 'Cooldown period active'];
            }
            
            // بررسی انحراف قیمت
            $priceDeviation = $this->calculatePriceDeviation($botConfig);
            
            if ($priceDeviation > self::GRID_REBALANCE_THRESHOLD) {
                return [
                    'needed' => true,
                    'reason' => 'Price deviation exceeded threshold',
                    'deviation' => $priceDeviation,
                    'type' => 'price_based'
                ];
            }
            
            return ['needed' => false, 'reason' => 'Grid is balanced'];
            
        } catch (Exception $e) {
            return ['needed' => false, 'reason' => 'Check failed'];
        }
    }

    /**
     * اجرای تعادل مجدد
     */
    private function executeRebalance(BotConfig $botConfig, array $rebalanceCheck): array
    {
        try {
            Log::info("Starting rebalance", ['bot_id' => $botConfig->id]);
            
            $currentPrice = $this->nobitexService->getCurrentPrice($botConfig->symbol ?? 'BTCIRT');
            
            // لغو سفارشات فعال
            $this->cancelAllActiveOrders($botConfig);
            
            // راه‌اندازی مجدد با قیمت جدید
            $initResult = $this->initializeGrid($botConfig, [
                'center_price' => $currentPrice,
                'force_start' => true
            ]);
            
            if ($initResult['success']) {
                $botConfig->update([
                    'last_rebalance_at' => now(),
                    'rebalance_count' => ($botConfig->rebalance_count ?? 0) + 1
                ]);
                
                return ['success' => true];
            }
            
            return ['success' => false, 'error' => $initResult['error']];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============ HELPER METHODS ============

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
            $connectionTest = $this->nobitexService->testConnection();
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
            $marketAnalysis = $this->gridCalculator->quickMarketAnalysis();
            
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
        try {
            $existingOrders = GridOrder::where('bot_config_id', $botConfig->id)
                                     ->whereIn('status', ['placed', 'pending'])
                                     ->get();
            
            $cancelledCount = 0;
            
            foreach ($existingOrders as $order) {
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
            }
            
            return [
                'total_orders' => $existingOrders->count(),
                'cancelled' => $cancelledCount
            ];
            
        } catch (Exception $e) {
            Log::error("Cleanup failed", ['error' => $e->getMessage()]);
            return ['total_orders' => 0, 'cancelled' => 0];
        }
    }

    /**
     * ثبت سفارشات گرید
     */
    private function placeGridOrders(BotConfig $botConfig, Collection $gridLevels, float $orderSize): array
    {
        $results = ['total' => 0, 'successful' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($gridLevels as $level) {
            $results['total']++;
            
            try {
                // ایجاد رکورد محلی
                $gridOrder = GridOrder::create([
                    'bot_config_id' => $botConfig->id,
                    'price' => $level['price'],
                    'amount' => $orderSize,
                    'type' => $level['type'],
                    'status' => 'pending',
                    'level' => $level['level'] ?? null,
                    'priority' => $level['priority'] ?? 5
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
                    $orderResult = $this->nobitexService->createOrder(
                        $level['type'],
                        $level['price'],
                        $orderSize,
                        $botConfig->symbol ?? 'BTCIRT'
                    );
                }

                if ($orderResult['success']) {
                    $gridOrder->update([
                        'status' => 'placed',
                        'nobitex_order_id' => $orderResult['order_id'],
                        'placed_at' => now()
                    ]);
                    
                    $results['successful']++;
                } else {
                    $gridOrder->update([
                        'status' => 'failed',
                        'error_message' => $orderResult['error']
                    ]);
                    
                    $results['failed']++;
                    $results['errors'][] = "Order at {$level['price']}: {$orderResult['error']}";
                }
                
                sleep(2); // Rate limiting
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Exception at {$level['price']}: {$e->getMessage()}";
            }
        }
        
        return $results;
    }

    /**
     * لغو همه سفارشات فعال
     */
    private function cancelAllActiveOrders(BotConfig $botConfig): array
    {
        try {
            $activeOrders = GridOrder::where('bot_config_id', $botConfig->id)
                                   ->whereIn('status', ['placed', 'pending'])
                                   ->get();
            
            $cancelled = 0;
            $failed = 0;
            
            foreach ($activeOrders as $order) {
                try {
                    if ($order->nobitex_order_id) {
                        if ($botConfig->simulation) {
                            // SIMULATION MODE - Log only, don't cancel real order
                            Log::info('SIMULATION: Would cancel active order', [
                                'bot_id' => $botConfig->id,
                                'order_id' => $order->id,
                                'nobitex_order_id' => $order->nobitex_order_id,
                            ]);

                            // Simulate successful cancellation
                            $cancelResult = ['success' => true];
                            $cancelled++;
                        } else {
                            // LIVE MODE - Cancel real order
                            $cancelResult = $this->nobitexService->cancelOrder($order->nobitex_order_id);

                            if ($cancelResult['success']) {
                                $cancelled++;
                            } else {
                                $failed++;
                            }
                        }
                    }
                    
                    $order->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now()
                    ]);
                    
                    sleep(1);
                    
                } catch (Exception $e) {
                    $failed++;
                }
            }
            
            return [
                'total' => $activeOrders->count(),
                'cancelled' => $cancelled,
                'failed' => $failed
            ];
            
        } catch (Exception $e) {
            return ['total' => 0, 'cancelled' => 0, 'failed' => 0];
        }
    }

    /**
     * محاسبه کارمزد معامله
     */
    private function calculateTradeFee(float $tradeValue): float
    {
        return $tradeValue * NobitexService::NOBITEX_FEE_RATE;
    }

    /**
     * محاسبه قیمت جایگزین
     */
    private function calculateReplacementPrice(GridOrder $filledOrder, float $currentPrice, BotConfig $botConfig): ?float
    {
        try {
            $spacing = $botConfig->grid_spacing / 100;
            
            if ($filledOrder->type === 'buy') {
                $newPrice = $filledOrder->price * (1 + $spacing);
            } else {
                $newPrice = $filledOrder->price * (1 - $spacing);
            }
            
            // بررسی منطقی بودن قیمت
            $deviation = abs($newPrice - $currentPrice) / $currentPrice;
            if ($deviation > 0.1) {
                return null;
            }
            
            return round($newPrice, 0);
            
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * محاسبه درصد drawdown فعلی
     */
    private function calculateCurrentDrawdown(BotConfig $botConfig): float
    {
        try {
            $initialCapital = $botConfig->total_capital;
            $currentProfit = $botConfig->total_profit ?? 0;
            $currentValue = $initialCapital + $currentProfit;
            
            $maxValue = max($initialCapital, $currentValue);
            $drawdown = (($maxValue - $currentValue) / $maxValue) * 100;
            
            return max(0, $drawdown);
            
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * محاسبه انحراف قیمت از مرکز
     */
    private function calculatePriceDeviation(BotConfig $botConfig): float
    {
        try {
            if (!$botConfig->center_price) {
                return 0;
            }
            
            $currentPrice = $this->nobitexService->getCurrentPrice($botConfig->symbol ?? 'BTCIRT');
            $centerPrice = $botConfig->center_price;
            
            return abs(($currentPrice - $centerPrice) / $centerPrice) * 100;
            
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * بررسی سلامت ربات
     */
    private function performHealthCheck(BotConfig $botConfig): array
    {
        try {
            $issues = [];
            $critical = false;
            
            // بررسی اتصال API
            $connectionTest = $this->nobitexService->testConnection();
            if ($connectionTest['status'] !== 'ok') {
                $issues[] = 'API connection failed';
                $critical = true;
            }
            
            // بررسی سفارشات معلق
            $staleOrders = GridOrder::where('bot_config_id', $botConfig->id)
                                  ->where('status', 'placed')
                                  ->where('updated_at', '<', now()->subHours(2))
                                  ->count();
            
            if ($staleOrders > 5) {
                $issues[] = "Many stale orders: {$staleOrders}";
            }
            
            return [
                'healthy' => empty($issues),
                'critical' => $critical,
                'issues' => $issues,
                'issue' => empty($issues) ? null : implode(', ', $issues)
            ];
            
        } catch (Exception $e) {
            return [
                'healthy' => false,
                'critical' => true,
                'issue' => 'Health check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * مدیریت خطای بحرانی
     */
    private function handleCriticalError(BotConfig $botConfig, string $issue): array
    {
        Log::critical("Critical error detected", [
            'bot_id' => $botConfig->id,
            'issue' => $issue
        ]);
        
        $stopResult = $this->stopGrid($botConfig, [
            'status' => 'critical_error',
            'reason' => $issue
        ]);
        
        return [
            'success' => false,
            'critical_error' => true,
            'issue' => $issue,
            'stop_result' => $stopResult
        ];
    }

    /**
     * به‌روزرسانی متریک‌های عملکرد
     */
    private function updatePerformanceMetrics(BotConfig $botConfig): array
    {
        try {
            $windowStart = now()->subHours(24);
            
            $trades = CompletedTrade::where('bot_config_id', $botConfig->id)
                                  ->where('completed_at', '>=', $windowStart)
                                  ->get();
            
            $metrics = [
                'total_trades' => $trades->count(),
                'total_profit' => $trades->sum('net_profit'),
                'average_profit_per_trade' => $trades->count() > 0 ? $trades->avg('net_profit') : 0,
                'win_rate' => $this->calculateWinRate($trades),
                'updated_at' => now()
            ];
            
            // ذخیره در cache
            Cache::put("bot_performance_{$botConfig->id}", $metrics, 300);
            
            // به‌روزرسانی جدول
            $botConfig->update([
                'total_profit' => $metrics['total_profit'],
                'win_rate' => $metrics['win_rate']
            ]);
            
            return $metrics;
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * تولید خلاصه عملکرد
     */
    private function generatePerformanceSummary(BotConfig $botConfig): array
    {
        try {
            $allTrades = CompletedTrade::where('bot_config_id', $botConfig->id)->get();
            
            return [
                'total_trades' => $allTrades->count(),
                'total_profit' => $allTrades->sum('net_profit'),
                'average_profit_per_trade' => $allTrades->avg('net_profit'),
                'win_rate' => $this->calculateWinRate($allTrades),
                'best_trade' => $allTrades->max('net_profit'),
                'worst_trade' => $allTrades->min('net_profit'),
                'runtime_hours' => $botConfig->started_at ? $botConfig->started_at->diffInHours(now()) : 0
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * محاسبه نرخ برد
     */
    private function calculateWinRate(Collection $trades): float
    {
        if ($trades->isEmpty()) {
            return 0;
        }
        
        $winningTrades = $trades->where('net_profit', '>', 0)->count();
        return round(($winningTrades / $trades->count()) * 100, 2);
    }

    // ============ PUBLIC API METHODS ============

    /**
     * دریافت وضعیت کامل ربات
     */
    public function getBotStatus(BotConfig $botConfig): array
    {
        try {
            $activeOrders = GridOrder::where('bot_config_id', $botConfig->id)
                                   ->whereIn('status', ['placed', 'pending'])
                                   ->count();
            
            $completedTrades = CompletedTrade::where('bot_config_id', $botConfig->id)->count();
            
            $performance = Cache::get("bot_performance_{$botConfig->id}", []);
            
            return [
                'bot_id' => $botConfig->id,
                'is_active' => $botConfig->is_active,
                'status' => $botConfig->status,
                'runtime' => $botConfig->started_at ? $botConfig->started_at->diffForHumans() : null,
                'active_orders' => $activeOrders,
                'completed_trades' => $completedTrades,
                'total_profit' => $botConfig->total_profit ?? 0,
                'center_price' => $botConfig->center_price,
                'last_check' => $botConfig->last_check_at?->diffForHumans(),
                'performance' => $performance,
                'last_error' => $botConfig->last_error_message
            ];
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'bot_id' => $botConfig->id
            ];
        }
    }

    /**
     * دریافت گزارش عملکرد
     */
    public function getPerformanceReport(BotConfig $botConfig, int $days = 7): array
    {
        try {
            $startDate = now()->subDays($days);
            
            $trades = CompletedTrade::where('bot_config_id', $botConfig->id)
                                  ->where('completed_at', '>=', $startDate)
                                  ->orderBy('completed_at')
                                  ->get();
            
            if ($trades->isEmpty()) {
                return [
                    'period_days' => $days,
                    'no_data' => true,
                    'message' => 'No trades found in the specified period'
                ];
            }
            
            // تحلیل روزانه
            $dailyStats = $trades->groupBy(function($trade) {
                return $trade->completed_at->format('Y-m-d');
            })->map(function($dayTrades) {
                return [
                    'trades_count' => $dayTrades->count(),
                    'total_profit' => $dayTrades->sum('net_profit'),
                    'win_rate' => ($dayTrades->where('net_profit', '>', 0)->count() / $dayTrades->count()) * 100
                ];
            });
            
            return [
                'period_days' => $days,
                'summary' => [
                    'total_trades' => $trades->count(),
                    'total_profit' => $trades->sum('net_profit'),
                    'average_profit_per_trade' => $trades->avg('net_profit'),
                    'win_rate' => $this->calculateWinRate($trades),
                    'best_day_profit' => $dailyStats->max('total_profit'),
                    'worst_day_profit' => $dailyStats->min('total_profit'),
                    'profitable_days' => $dailyStats->where('total_profit', '>', 0)->count()
                ],
                'daily_breakdown' => $dailyStats,
                'recent_trades' => $trades->take(10)->map(function($trade) {
                    return [
                        'completed_at' => $trade->completed_at->format('Y-m-d H:i:s'),
                        'buy_price' => $trade->buy_price,
                        'sell_price' => $trade->sell_price,
                        'profit' => $trade->net_profit,
                        'profit_percent' => round($trade->profit_percent, 2)
                    ];
                })
            ];
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'period_days' => $days
            ];
        }
    }

    /**
     * اجرای rebalance دستی
     */
    public function forceRebalance(BotConfig $botConfig, array $options = []): array
    {
        try {
            Log::info("Manual rebalance requested", ['bot_id' => $botConfig->id]);
            
            $rebalanceCheck = [
                'needed' => true,
                'reason' => 'Manual rebalance requested',
                'type' => 'manual'
            ];
            
            return $this->executeRebalance($botConfig, $rebalanceCheck);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * بررسی سلامت عمومی سیستم
     */
    public function systemHealthCheck(): array
    {
        try {
            // بررسی اتصال نوبیتکس
            $nobitexHealth = $this->nobitexService->healthCheck();
            
            // بررسی ربات‌های فعال
            $activeBots = BotConfig::where('is_active', true)->count();
            $totalBots = BotConfig::count();
            
            // بررسی عملکرد کلی
            $recentTrades = CompletedTrade::where('completed_at', '>=', now()->subHour())->count();
            
            return [
                'overall_status' => $nobitexHealth['overall_status'],
                'nobitex_connection' => $nobitexHealth['overall_status'],
                'active_bots' => $activeBots,
                'total_bots' => $totalBots,
                'recent_trades_count' => $recentTrades,
                'system_load' => [
                    'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                    'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
                ],
                'timestamp' => now()->toISOString()
            ];
            
        } catch (Exception $e) {
            return [
                'overall_status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }
}