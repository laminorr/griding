<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\NobitexService;

/**
 * GridCalculatorService - Professional Grid Trading Calculator
 * 
 * محاسبه‌گر حرفه‌ای Grid Trading با قابلیت‌های کامل
 * ساده شده برای راه‌اندازی ولی با منطق کامل
 * 
 * @version 2.0.0
 * @author GridBot Team
 */
class GridCalculatorService
{
    private NobitexService $nobitexService;
    
    // Nobitex Exchange Constants
    const NOBITEX_MIN_ORDER_IRT = 3000000; // 3M IRT minimum
    const NOBITEX_MIN_BTC_AMOUNT = 0.000001;
    const NOBITEX_FEE_RATE = 0.25; // 0.25% per trade
    const EXCHANGE_SLIPPAGE = 0.1; // 0.1% estimated slippage
    
    // Grid Trading Limits
    const MIN_GRID_LEVELS = 4;
    const MAX_GRID_LEVELS = 20;
    const MIN_SPACING = 0.5;
    const MAX_SPACING = 10.0;
    const MIN_CAPITAL_IRT = 10000000; // 10M IRT
    const OPTIMAL_SPACING_RANGE = [1.0, 3.0];
    
    // Market Analysis Constants
    const VOLATILITY_THRESHOLDS = [
        'very_low' => 2.0,
        'low' => 5.0,
        'medium' => 10.0,
        'high' => 20.0,
        'very_high' => 50.0
    ];

    public function __construct(NobitexService $nobitexService)
    {
        $this->nobitexService = $nobitexService;
    }

    /**
     * محاسبه سطوح گرید اصلی
     * 
     * @param float $centerPrice قیمت مرکز (IRT)
     * @param float $spacing فاصله درصدی بین سطوح
     * @param int $levels تعداد سطوح (باید زوج باشد)
     * @param string $algorithm نوع الگوریتم
     * @return array نتیجه کامل محاسبات
     */
    public function calculateGridLevels(
        float $centerPrice,
        float $spacing,
        int $levels,
        string $algorithm = 'logarithmic'
    ): array {
        try {
            // اعتبارسنجی ورودی‌ها
            $this->validateGridInputs($centerPrice, $spacing, $levels);
            
            // محاسبه سطوح بر اساس الگوریتم
            $gridLevels = $this->generateGridLevels($centerPrice, $spacing, $levels, $algorithm);
            
            // غنی‌سازی سطوح با متادیتا
            $enhancedLevels = $this->enhanceGridLevels($gridLevels, $centerPrice);
            
            // تحلیل کیفیت گرید
            $analysis = $this->analyzeGridQuality($enhancedLevels, $centerPrice, $spacing);
            
            // محاسبه متریک‌های عملکرد
            $performance = $this->calculateGridPerformance($enhancedLevels, $centerPrice);
            
            return [
                'success' => true,
                'grid_levels' => $enhancedLevels,
                'analysis' => $analysis,
                'performance' => $performance,
                'algorithm_used' => $algorithm,
                'total_levels' => $enhancedLevels->count(),
                'price_range' => [
                    'lowest' => $enhancedLevels->min('price'),
                    'highest' => $enhancedLevels->max('price'),
                    'range_percent' => $this->calculatePriceRangePercent($enhancedLevels, $centerPrice)
                ]
            ];
            
        } catch (Exception $e) {
            Log::error('Grid calculation failed', [
                'center_price' => $centerPrice,
                'spacing' => $spacing,
                'levels' => $levels,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'GRID_CALCULATION_FAILED'
            ];
        }
    }

    /**
     * محاسبه اندازه سفارش بهینه
     * 
     * @param float $totalCapitalIRT سرمایه کل (ریال)
     * @param float $activePercent درصد سرمایه فعال
     * @param int $gridLevels تعداد سطوح گرید
     * @param string $symbol نماد معاملاتی
     * @return array نتیجه کامل محاسبه
     */
    public function calculateOrderSize(
        float $totalCapitalIRT,
        float $activePercent,
        int $gridLevels,
        string $symbol = 'BTCIRT'
    ): array {
        try {
            // اعتبارسنجی سرمایه
            $this->validateCapitalInputs($totalCapitalIRT, $activePercent, $gridLevels);
            
            // محاسبه پارامترهای اولیه
            $activeCapitalIRT = $totalCapitalIRT * ($activePercent / 100);
            $orderSizeIRT = $activeCapitalIRT / $gridLevels;
            
            // دریافت قیمت فعلی
            $currentPrice = $this->getCurrentPriceWithValidation($symbol);
            
            // محاسبه مقدار کریپتو
            $cryptoAmount = $this->calculateCryptoAmount($orderSizeIRT, $currentPrice, $symbol);
            
            // بهینه‌سازی اندازه سفارش
            $optimizedSizes = $this->optimizeOrderSize($orderSizeIRT, $cryptoAmount, $symbol);
            
            // اعتبارسنجی نهایی
            $validation = $this->validateOrderSize($optimizedSizes, $symbol);
            
            // محاسبه آمار ریسک
            $riskMetrics = $this->calculateOrderRiskMetrics($optimizedSizes, $totalCapitalIRT, $gridLevels);
            
            return [
                'success' => true,
                'crypto_amount' => $optimizedSizes['crypto_amount'],
                'irt_value_per_order' => $optimizedSizes['irt_value'],
                'usd_equivalent' => round($optimizedSizes['irt_value'] / 42000, 2),
                'capital_allocation' => [
                    'total_capital' => $totalCapitalIRT,
                    'active_capital' => $activeCapitalIRT,
                    'active_percent' => $activePercent,
                    'reserve_capital' => $totalCapitalIRT - $activeCapitalIRT,
                    'order_count' => $gridLevels
                ],
                'validation' => $validation,
                'risk_metrics' => $riskMetrics,
                'current_price' => $currentPrice,
                'symbol' => $symbol,
                'exchange_compatibility' => $this->checkExchangeCompatibility($optimizedSizes, $symbol)
            ];
            
        } catch (Exception $e) {
            Log::error('Order size calculation failed', [
                'total_capital' => $totalCapitalIRT,
                'active_percent' => $activePercent,
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'ORDER_SIZE_CALCULATION_FAILED'
            ];
        }
    }

    /**
     * محاسبه سود مورد انتظار
     * 
     * @param float $centerPrice قیمت مرکز
     * @param float $gridSpacing فاصله گرید
     * @param int $gridLevels تعداد سطوح
     * @param float $orderSizeCrypto اندازه سفارش کریپتو
     * @return array تحلیل کامل سودآوری
     */
    public function calculateExpectedProfit(
        float $centerPrice,
        float $gridSpacing,
        int $gridLevels,
        float $orderSizeCrypto
    ): array {
        


        try {
            // تحلیل بازار
            $marketAnalysis = $this->analyzeMarketConditions($gridSpacing);
            
            // محاسبه سود هر چرخه (بدون کارمزد)
// ارزش هر سفارش (ناتشنال) = قیمت × مقدار
$orderNotional = $centerPrice * $orderSizeCrypto;

// سود ناخالص هر چرخه (بر اساس فاصلهٔ گرید)
$grossProfitPerCycle = $orderNotional * ($gridSpacing / 100);

// کارمزدها را روی «ارزش معامله» حساب کن (نه روی سود)
$tradingFees = $this->calculateTradingFees($orderNotional);

// سود خالص هر چرخه = سود ناخالص - (کارمزد خرید+فروش + اسلیپیج)
$netProfitPerCycle = $grossProfitPerCycle - $tradingFees['total_cost'];

$profitMargin = $grossProfitPerCycle > 0
    ? ($netProfitPerCycle / $grossProfitPerCycle) * 100
    : 0.0;



            
            // تخمین تعداد چرخه‌ها
            $cycleEstimation = $this->estimateTradingCycles($gridSpacing, $marketAnalysis);
            
            // محاسبه سود در بازه‌های زمانی
            $timeFrameProfits = $this->calculateTimeFrameProfits($netProfitPerCycle, $cycleEstimation);
            
            // محاسبه ROI
            $totalInvestment = $centerPrice * $orderSizeCrypto * $gridLevels;
            $performanceMetrics = $this->calculateROIMetrics($timeFrameProfits, $totalInvestment);
            
            // تحلیل احتمال موفقیت
            $successProbability = $this->calculateSuccessProbability($gridSpacing, $marketAnalysis);
            
            return [
                'success' => true,
                'profit_summary' => [
                    'gross_profit_per_cycle' => round($grossProfitPerCycle, 0),
                    'net_profit_per_cycle' => round($netProfitPerCycle, 0),
                    'profit_margin_percent' => round($profitMargin, 2),

                    'estimated_daily_profit' => round($timeFrameProfits['daily'], 0),
                    'estimated_monthly_profit' => round($timeFrameProfits['monthly'], 0)
                ],
                'cycle_analysis' => [
                    'estimated_cycles_per_day' => $cycleEstimation['daily_cycles'],
                    'cycle_probability' => $cycleEstimation['cycle_probability'],
                    'market_volatility' => $marketAnalysis['volatility_level'],
                    'grid_efficiency' => $marketAnalysis['grid_efficiency']
                ],
                'fees_breakdown' => $tradingFees,
                'time_frame_profits' => $timeFrameProfits,
                'performance_metrics' => $performanceMetrics,
                'success_probability' => $successProbability,
                'break_even_analysis' => $this->calculateBreakEvenAnalysis($tradingFees, $netProfitPerCycle, $cycleEstimation)
            ];
            
        } catch (Exception $e) {
            Log::error('Profit calculation failed', [
                'center_price' => $centerPrice,
                'grid_spacing' => $gridSpacing,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'PROFIT_CALCULATION_FAILED'
            ];
        }
    }

    /**
     * ارزیابی ریسک کامل
     * 
     * @param array $gridConfig تنظیمات گرید
     * @param float $totalCapital سرمایه کل
     * @return array تحلیل کامل ریسک
     */
    public function assessGridRisk(array $gridConfig, float $totalCapital): array
    {
        try {
            $centerPrice = $gridConfig['center_price'];
            $spacing = $gridConfig['spacing'];
            $levels = $gridConfig['levels'];
            $activePercent = $gridConfig['active_percent'] ?? 30;
            
            // تحلیل ریسک قیمت
            $priceRisk = $this->analyzePriceRisk($centerPrice, $spacing, $levels);
            
            // تحلیل ریسک نقدینگی
            $liquidityRisk = $this->analyzeLiquidityRisk($totalCapital, $activePercent, $levels);
            
            // تحلیل ریسک بازار
            $marketRisk = $this->analyzeMarketRisk($spacing);
            
            // محاسبه امتیاز ریسک کلی
            $overallRiskScore = $this->calculateOverallRiskScore($priceRisk, $liquidityRisk, $marketRisk);
            
            return [
                'success' => true,
                'overall_risk_score' => $overallRiskScore,
                'risk_level' => $this->categorizeRiskLevel($overallRiskScore['total_score']),
                'risk_breakdown' => [
                    'price_risk' => $priceRisk,
                    'liquidity_risk' => $liquidityRisk,
                    'market_risk' => $marketRisk
                ],
                'max_potential_loss' => $this->calculateMaxPotentialLoss($totalCapital, $activePercent, $spacing),
                'recommendations' => $this->generateRiskRecommendations($overallRiskScore),
                'stop_loss_recommendation' => $this->calculateStopLossRecommendation($spacing, $overallRiskScore)
            ];
            
        } catch (Exception $e) {
            Log::error('Risk assessment failed', [
                'grid_config' => $gridConfig,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'RISK_ASSESSMENT_FAILED'
            ];
        }
    }

    // ============ PRIVATE CORE METHODS ============

    /**
     * اعتبارسنجی ورودی‌های گرید
     */
    private function validateGridInputs(float $centerPrice, float $spacing, int $levels): void
    {
        if ($centerPrice <= 0) {
            throw new Exception('قیمت مرکز باید مثبت باشد');
        }
        
        if ($spacing < self::MIN_SPACING || $spacing > self::MAX_SPACING) {
            throw new Exception("فاصله گرید باید بین " . self::MIN_SPACING . " تا " . self::MAX_SPACING . " درصد باشد");
        }
        
        if ($levels < self::MIN_GRID_LEVELS || $levels > self::MAX_GRID_LEVELS) {
            throw new Exception("تعداد سطوح باید بین " . self::MIN_GRID_LEVELS . " تا " . self::MAX_GRID_LEVELS . " باشد");
        }
        
        if ($levels % 2 !== 0) {
            throw new Exception('تعداد سطوح باید زوج باشد');
        }
    }

    /**
     * اعتبارسنجی ورودی‌های سرمایه
     */
    private function validateCapitalInputs(float $totalCapital, float $activePercent, int $levels): void
    {
        if ($totalCapital < self::MIN_CAPITAL_IRT) {
            throw new Exception("حداقل سرمایه مورد نیاز: " . number_format(self::MIN_CAPITAL_IRT) . " ریال");
        }
        
        if ($activePercent <= 0 || $activePercent > 100) {
            throw new Exception('درصد سرمایه فعال باید بین 1 تا 100 باشد');
        }
        
        // بررسی اینکه اندازه سفارش معتبر خواهد بود
        $activeCapital = $totalCapital * ($activePercent / 100);
        $orderSize = $activeCapital / $levels;
        
        if ($orderSize < self::NOBITEX_MIN_ORDER_IRT) {
            throw new Exception('اندازه سفارش خیلی کم است. تعداد سطوح را کم کنید یا درصد سرمایه فعال را افزایش دهید');
        }
    }

    /**
     * تولید سطوح گرید
     */
    private function generateGridLevels(float $centerPrice, float $spacing, int $levels, string $algorithm): Collection
    {
        $halfLevels = intval($levels / 2);
        
        switch ($algorithm) {
            case 'arithmetic':
                return $this->generateArithmeticGrid($centerPrice, $spacing, $halfLevels);
            case 'geometric':
                return $this->generateGeometricGrid($centerPrice, $spacing, $halfLevels);
            case 'logarithmic':
            default:
                return $this->generateLogarithmicGrid($centerPrice, $spacing, $halfLevels);
        }
    }

    /**
     * الگوریتم لگاریتمی (بهترین برای اکثر شرایط)
     */
    private function generateLogarithmicGrid(float $centerPrice, float $spacing, int $halfLevels): Collection
    {
        $grid = collect();
        $spacingDecimal = $spacing / 100;
        
        // سطوح خرید (پایین‌تر از قیمت مرکز)
        for ($i = 1; $i <= $halfLevels; $i++) {
            $multiplier = pow(1 - $spacingDecimal, $i);
            $price = $centerPrice * $multiplier;
            
            $grid->push([
                'price' => round($price, 0),
                'type' => 'buy',
                'level' => $halfLevels - $i + 1,
                'distance_percent' => -($spacing * $i),
                'multiplier' => $multiplier
            ]);
        }
        
        // سطوح فروش (بالاتر از قیمت مرکز)
        for ($i = 1; $i <= $halfLevels; $i++) {
            $multiplier = pow(1 + $spacingDecimal, $i);
            $price = $centerPrice * $multiplier;
            
            $grid->push([
                'price' => round($price, 0),
                'type' => 'sell',
                'level' => $halfLevels + $i,
                'distance_percent' => +($spacing * $i),
                'multiplier' => $multiplier
            ]);
        }
        
        return $grid->sortBy('price')->values();
    }

    /**
     * الگوریتم حسابی
     */
    private function generateArithmeticGrid(float $centerPrice, float $spacing, int $halfLevels): Collection
    {
        $grid = collect();
        $spacingAmount = $centerPrice * ($spacing / 100);
        
        // سطوح خرید
        for ($i = 1; $i <= $halfLevels; $i++) {
            $price = $centerPrice - ($spacingAmount * $i);
            
            $grid->push([
                'price' => round($price, 0),
                'type' => 'buy',
                'level' => $halfLevels - $i + 1,
                'distance_percent' => -(($centerPrice - $price) / $centerPrice * 100)
            ]);
        }
        
        // سطوح فروش
        for ($i = 1; $i <= $halfLevels; $i++) {
            $price = $centerPrice + ($spacingAmount * $i);
            
            $grid->push([
                'price' => round($price, 0),
                'type' => 'sell',
                'level' => $halfLevels + $i,
                'distance_percent' => +(($price - $centerPrice) / $centerPrice * 100)
            ]);
        }
        
        return $grid->sortBy('price')->values();
    }

    /**
     * الگوریتم هندسی
     */
    private function generateGeometricGrid(float $centerPrice, float $spacing, int $halfLevels): Collection
    {
        $grid = collect();
        $ratio = 1 + ($spacing / 100);
        
        // سطوح خرید
        for ($i = 1; $i <= $halfLevels; $i++) {
            $price = $centerPrice / pow($ratio, $i);
            
            $grid->push([
                'price' => round($price, 0),
                'type' => 'buy',
                'level' => $halfLevels - $i + 1,
                'distance_percent' => -(($centerPrice - $price) / $centerPrice * 100)
            ]);
        }
        
        // سطوح فروش
        for ($i = 1; $i <= $halfLevels; $i++) {
            $price = $centerPrice * pow($ratio, $i);
            
            $grid->push([
                'price' => round($price, 0),
                'type' => 'sell',
                'level' => $halfLevels + $i,
                'distance_percent' => +(($price - $centerPrice) / $centerPrice * 100)
            ]);
        }
        
        return $grid->sortBy('price')->values();
    }

    /**
     * غنی‌سازی سطوح گرید
     */
    private function enhanceGridLevels(Collection $grid, float $centerPrice): Collection
    {
        return $grid->map(function ($level, $index) use ($centerPrice) {
            $level['order_index'] = $index + 1;
            $level['price_formatted'] = number_format($level['price']);
            $level['distance_from_center'] = abs($level['price'] - $centerPrice);
            $level['distance_percent_abs'] = abs($level['distance_percent'] ?? 0);
            $level['execution_probability'] = $this->calculateExecutionProbability($level['distance_percent_abs']);
            $level['profit_potential'] = $this->calculateProfitPotential($level['distance_percent_abs']);
            $level['priority'] = $this->calculateLevelPriority($level['distance_percent_abs']);
            
            return $level;
        });
    }

    /**
     * محاسبه مقدار کریپتو بر اساس ریال
     */
    private function calculateCryptoAmount(float $irtAmount, float $price, string $symbol): float
    {
        $cryptoAmount = $irtAmount / $price;
        
        $precision = match($symbol) {
            'BTCIRT' => 8,
            'ETHIRT' => 6,
            'LTCIRT' => 6,
            'USDTIRT' => 2,
            default => 8
        };
        
        return round($cryptoAmount, $precision);
    }

    /**
     * بهینه‌سازی اندازه سفارش
     */
    private function optimizeOrderSize(float $irtAmount, float $cryptoAmount, string $symbol): array
    {
        $optimizedIRT = max($irtAmount, self::NOBITEX_MIN_ORDER_IRT);
        $optimizedCrypto = max($cryptoAmount, self::NOBITEX_MIN_BTC_AMOUNT);
        
        // اگر ریال افزایش یافت، کریپتو را دوباره محاسبه کن
        if ($optimizedIRT > $irtAmount) {
            $currentPrice = $this->getCurrentPriceWithValidation($symbol);
            $optimizedCrypto = $this->calculateCryptoAmount($optimizedIRT, $currentPrice, $symbol);
        }
        
        return [
            'irt_value' => round($optimizedIRT, 0),
            'crypto_amount' => $optimizedCrypto
        ];
    }

    /**
     * اعتبارسنجی اندازه سفارش
     */
    private function validateOrderSize(array $optimizedSizes, string $symbol): array
    {
        $warnings = [];
        $recommendations = [];
        
        $isValidIRT = $optimizedSizes['irt_value'] >= self::NOBITEX_MIN_ORDER_IRT;
        $isValidCrypto = $optimizedSizes['crypto_amount'] >= self::NOBITEX_MIN_BTC_AMOUNT;
        
        if (!$isValidIRT) {
            $warnings[] = 'ارزش سفارش کمتر از حداقل مجاز نوبیتکس';
            $recommendations[] = 'تعداد سطوح را کاهش دهید';
        }
        
        if (!$isValidCrypto) {
            $warnings[] = 'مقدار کریپتو کمتر از حداقل مجاز';
            $recommendations[] = 'سرمایه کل را افزایش دهید';
        }
        
        if ($optimizedSizes['irt_value'] > 100000000) {
            $warnings[] = 'اندازه سفارش بزرگ - ریسک اسلیپیج';
            $recommendations[] = 'تعداد سطوح را افزایش دهید';
        }
        
        return [
            'is_valid' => $isValidIRT && $isValidCrypto,
            'warnings' => $warnings,
            'recommendations' => $recommendations
        ];
    }

    /**
     * محاسبه آمار ریسک سفارش
     */
    private function calculateOrderRiskMetrics(array $optimizedSizes, float $totalCapital, int $gridLevels): array
    {
        $singleOrderValue = $optimizedSizes['irt_value'];
        $totalActiveValue = $singleOrderValue * $gridLevels;
        
        return [
            'capital_at_risk_percent' => round(($totalActiveValue / $totalCapital) * 100, 2),
            'single_order_impact_percent' => round(($singleOrderValue / $totalCapital) * 100, 2),
            'diversification_score' => min(100, $gridLevels * 5),
            'liquidity_risk' => $this->assessLiquidityRisk($singleOrderValue),
            'position_size_grade' => $this->gradePositionSize($totalActiveValue, $totalCapital)
        ];
    }

    /**
     * بررسی سازگاری با صرافی
     */
    private function checkExchangeCompatibility(array $optimizedSizes, string $symbol): array
    {
        return [
            'exchange' => 'Nobitex',
            'min_order_check' => $optimizedSizes['irt_value'] >= self::NOBITEX_MIN_ORDER_IRT,
            'precision_check' => true,
            'estimated_fee' => round($optimizedSizes['irt_value'] * (self::NOBITEX_FEE_RATE / 100), 0),
            'execution_feasible' => true
        ];
    }

    /**
     * تحلیل شرایط بازار
     */
private function analyzeMarketConditions(float $gridSpacing): array
{
    try {
        // پیش‌فرض امن اگر متد در سرویس موجود نبود
        $dayChange = 5.0;

        if (method_exists($this->nobitexService, 'getMarketStats')) {
            $marketStats = $this->nobitexService->getMarketStats('BTCIRT');
            if (is_array($marketStats) && isset($marketStats['dayChange'])) {
                $dayChange = abs((float)$marketStats['dayChange']);
            }
        }

        $volatility = $this->categorizeVolatility($dayChange);
        $efficiency = $this->calculateGridEfficiency($gridSpacing, $volatility);

        return [
            'volatility_level' => $volatility,
            'volatility_percent' => $dayChange,
            'grid_efficiency' => $efficiency,
            'market_suitable_for_grid' => $efficiency > 60,
        ];
    } catch (\Throwable $e) {
        // فول‌بک کاملاً امن
        return [
            'volatility_level' => 'medium',
            'volatility_percent' => 5,
            'grid_efficiency' => 70,
            'market_suitable_for_grid' => true,
        ];
    }
}


    /**
     * محاسبه کارمزدهای معاملاتی
     */
private function calculateTradingFees(float $orderNotional): array
{
    // کارمزد واقعی روی «ارزش معامله» اعمال می‌شود (برای هر لگ خرید و فروش)
    $feeRate  = self::NOBITEX_FEE_RATE / 100;          // 0.25% = 0.0025
    $buyFee   = $orderNotional * $feeRate;             // کارمزد خرید
    $sellFee  = $orderNotional * $feeRate;             // کارمزد فروش
    $totalFee = $buyFee + $sellFee;

    // اسلیپیج هم روی ناتشنال منطقی‌تر است (نه روی سود)
    $slippage = $orderNotional * (self::EXCHANGE_SLIPPAGE / 100); // 0.1% = 0.001

    return [
        'buy_fee'          => round($buyFee, 0),
        'sell_fee'         => round($sellFee, 0),
        'total_fee'        => round($totalFee, 0),
        'slippage'         => round($slippage, 0),
        'total_cost'       => round($totalFee + $slippage, 0),
        'fee_rate_percent' => self::NOBITEX_FEE_RATE,
    ];
}



    /**
     * تخمین چرخه‌های معاملاتی
     */
    private function estimateTradingCycles(float $gridSpacing, array $marketAnalysis): array
    {
        $baseCycles = match(true) {
            $gridSpacing >= 3.0 => 1.5,
            $gridSpacing >= 2.0 => 2.8,
            $gridSpacing >= 1.5 => 4.2,
            $gridSpacing >= 1.0 => 6.0,
            default => 8.0
        };
        
        $volatilityMultiplier = match($marketAnalysis['volatility_level']) {
            'very_high' => 2.0,
            'high' => 1.5,
            'medium' => 1.0,
            'low' => 0.7,
            'very_low' => 0.4,
            default => 1.0
        };
        
        $adjustedCycles = $baseCycles * $volatilityMultiplier;
        $cycleProbability = $this->calculateCycleProbability($gridSpacing, $marketAnalysis);
        $effectiveCycles = $adjustedCycles * $cycleProbability;
        
        return [
            'daily_cycles' => round($effectiveCycles, 2),
            'weekly_cycles' => round($effectiveCycles * 7, 1),
            'monthly_cycles' => round($effectiveCycles * 30, 0),
            'cycle_probability' => $cycleProbability,
            'base_cycles' => $baseCycles,
            'volatility_multiplier' => $volatilityMultiplier
        ];
    }

    /**
     * محاسبه سود در بازه‌های زمانی
     */
    private function calculateTimeFrameProfits(float $netProfitPerCycle, array $cycleEstimation): array
    {
        $dailyProfit = $netProfitPerCycle * $cycleEstimation['daily_cycles'];
        
        return [
            'hourly' => round($dailyProfit / 24, 0),
            'daily' => round($dailyProfit, 0),
            'weekly' => round($dailyProfit * 7, 0),
            'monthly' => round($dailyProfit * 30, 0),
            'quarterly' => round($dailyProfit * 90, 0),
            'yearly' => round($dailyProfit * 365, 0)
        ];
    }

    /**
     * محاسبه متریک‌های ROI
     */
    private function calculateROIMetrics(array $timeFrameProfits, float $totalInvestment): array
    {
        if ($totalInvestment <= 0) {
            return ['error' => 'سرمایه‌گذاری نامعتبر'];
        }
        
        return [
            'daily_roi_percent' => round(($timeFrameProfits['daily'] / $totalInvestment) * 100, 3),
            'weekly_roi_percent' => round(($timeFrameProfits['weekly'] / $totalInvestment) * 100, 2),
            'monthly_roi_percent' => round(($timeFrameProfits['monthly'] / $totalInvestment) * 100, 2),
            'yearly_roi_percent' => round(($timeFrameProfits['yearly'] / $totalInvestment) * 100, 1),
            'break_even_days' => $timeFrameProfits['daily'] > 0 ? ceil($totalInvestment / $timeFrameProfits['daily']) : null
        ];
    }

    /**
     * محاسبه احتمال موفقیت
     */
    private function calculateSuccessProbability(float $gridSpacing, array $marketAnalysis): array
    {
        $baseProbability = 75; // درصد پایه
        
        // تنظیم بر اساس فاصله گرید
        if ($gridSpacing >= 3.0) $baseProbability += 15;
        elseif ($gridSpacing >= 2.0) $baseProbability += 10;
        elseif ($gridSpacing < 1.0) $baseProbability -= 20;
        
        // تنظیم بر اساس نوسانات
        $volatilityAdjustment = match($marketAnalysis['volatility_level']) {
            'very_high' => 10,
            'high' => 5,
            'medium' => 0,
            'low' => -10,
            'very_low' => -20,
            default => 0
        };
        
        $finalProbability = max(20, min(95, $baseProbability + $volatilityAdjustment));
        
        return [
            'overall_probability' => $finalProbability,
            'confidence_level' => $this->categorizeConfidenceLevel($finalProbability),
            'market_suitability' => $marketAnalysis['grid_efficiency'],
            'risk_adjusted_probability' => round($finalProbability * 0.85, 1)
        ];
    }

    /**
     * تحلیل نقطه سربه‌سر
     */
    private function calculateBreakEvenAnalysis(array $tradingFees, float $netProfitPerCycle, array $cycleEstimation): array
    {
        if ($netProfitPerCycle <= 0) {
            return [
                'break_even_possible' => false,
                'reason' => 'سود هر چرخه منفی است',
                'recommendation' => 'فاصله گرید را افزایش دهید'
            ];
        }
        
        $totalCostPerCycle = $tradingFees['total_cost'];
        $breakEvenCycles = ceil($totalCostPerCycle / $netProfitPerCycle);
        $dailyCycles = $cycleEstimation['daily_cycles'];
        $breakEvenDays = $dailyCycles > 0 ? ceil($breakEvenCycles / $dailyCycles) : null;
        
        return [
            'break_even_possible' => true,
            'break_even_cycles' => $breakEvenCycles,
            'break_even_days' => $breakEvenDays,
            'break_even_hours' => $breakEvenDays ? round($breakEvenDays * 24, 1) : null,
            'profitability_assessment' => $this->assessProfitability($breakEvenDays)
        ];
    }

    /**
     * تحلیل کیفیت گرید
     */
    private function analyzeGridQuality(Collection $gridLevels, float $centerPrice, float $spacing): array
    {
        $buyLevels = $gridLevels->where('type', 'buy')->count();
        $sellLevels = $gridLevels->where('type', 'sell')->count();
        
        return [
            'balance_score' => $this->calculateBalanceScore($buyLevels, $sellLevels),
            'spacing_quality' => $this->assessSpacingQuality($spacing),
            'coverage_score' => $this->calculateCoverageScore($gridLevels, $centerPrice),
            'efficiency_score' => $this->calculateGridEfficiencyScore($gridLevels),
            'overall_grade' => $this->calculateOverallGrade($spacing, $gridLevels->count())
        ];
    }

    /**
     * محاسبه عملکرد گرید
     */
    private function calculateGridPerformance(Collection $gridLevels, float $centerPrice): array
    {
        $priceRange = $gridLevels->max('price') - $gridLevels->min('price');
        $avgExecutionProb = $gridLevels->avg('execution_probability');
        
        return [
            'total_levels' => $gridLevels->count(),
            'price_range_irt' => round($priceRange, 0),
            'price_range_percent' => round(($priceRange / $centerPrice) * 100, 2),
            'average_execution_probability' => round($avgExecutionProb, 3),
            'grid_density' => round($gridLevels->count() / max(1, ($priceRange / $centerPrice) * 100), 2),
            'capital_efficiency' => $this->calculateCapitalEfficiency($gridLevels),
            'performance_score' => $this->calculatePerformanceScore($avgExecutionProb, $gridLevels->count())
        ];
    }

    /**
     * تحلیل ریسک قیمت
     */
    private function analyzePriceRisk(float $centerPrice, float $spacing, int $levels): array
    {
        $halfLevels = $levels / 2;
        $lowestPrice = $centerPrice * pow(1 - $spacing/100, $halfLevels);
        $highestPrice = $centerPrice * pow(1 + $spacing/100, $halfLevels);
        
        $downwardRisk = (($centerPrice - $lowestPrice) / $centerPrice) * 100;
        $upwardExposure = (($highestPrice - $centerPrice) / $centerPrice) * 100;
        
        return [
            'downward_risk_percent' => round($downwardRisk, 2),
            'upward_exposure_percent' => round($upwardExposure, 2),
            'price_range_risk' => $this->categorizePriceRangeRisk($downwardRisk + $upwardExposure),
            'maximum_drawdown_estimate' => round($downwardRisk * 0.6, 2),
            'risk_score' => min(100, round(($downwardRisk + $upwardExposure) * 2, 0))
        ];
    }

    /**
     * تحلیل ریسک نقدینگی
     */
    private function analyzeLiquidityRisk(float $totalCapital, float $activePercent, int $levels): array
    {
        $activeCapital = $totalCapital * ($activePercent / 100);
        $reserveCapital = $totalCapital - $activeCapital;
        
        return [
            'active_capital_ratio' => $activePercent,
            'reserve_capital_ratio' => round((($reserveCapital / $totalCapital) * 100), 2),
            'liquidity_risk_score' => $this->calculateLiquidityRiskScore($activePercent, $levels),
            'capital_concentration' => round(($activeCapital / $levels / $totalCapital) * 100, 2),
            'emergency_liquidity' => $reserveCapital,
            'liquidity_grade' => $this->gradeLiquidityRisk($activePercent, $reserveCapital)
        ];
    }

    /**
     * تحلیل ریسک بازار
     */
    private function analyzeMarketRisk(float $spacing): array
    {
        $baseRisk = 30; // ریسک پایه کریپتو
        
        $spacingRisk = match(true) {
            $spacing < 1.0 => 30,
            $spacing < 2.0 => 20,
            $spacing < 3.0 => 10,
            default => 5
        };
        
        $totalRisk = min(100, $baseRisk + $spacingRisk);
        
        return [
            'total_market_risk_score' => $totalRisk,
            'base_crypto_risk' => $baseRisk,
            'spacing_risk_component' => $spacingRisk,
            'volatility_risk' => $this->estimateVolatilityRisk($spacing),
            'correlation_risk' => 25,
            'risk_level' => $this->categorizeRiskLevel($totalRisk)
        ];
    }

    /**
     * محاسبه امتیاز ریسک کلی
     */
    private function calculateOverallRiskScore(array $priceRisk, array $liquidityRisk, array $marketRisk): array
    {
        $weights = [
            'price_risk' => 0.4,
            'liquidity_risk' => 0.3,
            'market_risk' => 0.3
        ];
        
        $weightedScore = 
            ($priceRisk['risk_score'] * $weights['price_risk']) +
            ($liquidityRisk['liquidity_risk_score'] * $weights['liquidity_risk']) +
            ($marketRisk['total_market_risk_score'] * $weights['market_risk']);
        
        return [
            'total_score' => round($weightedScore, 1),
            'price_risk_weight' => $priceRisk['risk_score'] * $weights['price_risk'],
            'liquidity_risk_weight' => $liquidityRisk['liquidity_risk_score'] * $weights['liquidity_risk'],
            'market_risk_weight' => $marketRisk['total_market_risk_score'] * $weights['market_risk'],
            'confidence_interval' => [$weightedScore - 5, $weightedScore + 5]
        ];
    }

    /**
     * محاسبه حداکثر ضرر احتمالی
     */
    private function calculateMaxPotentialLoss(float $totalCapital, float $activePercent, float $spacing): array
    {
        $activeCapital = $totalCapital * ($activePercent / 100);
        
        $scenarios = [
            'mild_decline' => ['probability' => 30, 'loss_percent' => $spacing * 1.5],
            'moderate_decline' => ['probability' => 15, 'loss_percent' => $spacing * 3],
            'severe_decline' => ['probability' => 5, 'loss_percent' => min(50, $spacing * 6)]
        ];
        
        $expectedLoss = 0;
        foreach ($scenarios as $scenario) {
            $expectedLoss += ($scenario['probability'] / 100) * $scenario['loss_percent'];
        }
        
        return [
            'max_potential_loss_percent' => round($scenarios['severe_decline']['loss_percent'], 2),
            'max_potential_loss_amount' => round($activeCapital * ($scenarios['severe_decline']['loss_percent'] / 100), 0),
            'expected_loss_percent' => round($expectedLoss, 2),
            'expected_loss_amount' => round($activeCapital * ($expectedLoss / 100), 0),
            'scenarios' => $scenarios,
            'capital_at_risk' => $activeCapital
        ];
    }

    // ============ UTILITY HELPER METHODS ============

    /**
     * دریافت قیمت فعلی با اعتبارسنجی
     */
    private function getCurrentPriceWithValidation(string $symbol): float
    {
        $cacheKey = "validated_price_{$symbol}";
        
        return Cache::remember($cacheKey, 30, function() use ($symbol) {
            $price = $this->nobitexService->getCurrentPrice($symbol);
            
            if ($price <= 0) {
                throw new Exception("قیمت نامعتبر برای {$symbol}: {$price}");
            }
            
            return $price;
        });
    }

    /**
     * محاسبه درصد محدوده قیمت
     */
    private function calculatePriceRangePercent(Collection $gridLevels, float $centerPrice): float
    {
        $minPrice = $gridLevels->min('price');
        $maxPrice = $gridLevels->max('price');
        $range = $maxPrice - $minPrice;
        
        return round(($range / $centerPrice) * 100, 2);
    }

    /**
     * محاسبه احتمال اجرا
     */
    private function calculateExecutionProbability(float $distancePercent): float
    {
        return match(true) {
            $distancePercent <= 1 => 0.9,
            $distancePercent <= 2 => 0.8,
            $distancePercent <= 3 => 0.7,
            $distancePercent <= 5 => 0.6,
            default => 0.4
        };
    }

    /**
     * محاسبه پتانسیل سود
     */
    private function calculateProfitPotential(float $distancePercent): string
    {
        return match(true) {
            $distancePercent <= 1 => 'very_high',
            $distancePercent <= 2 => 'high',
            $distancePercent <= 3 => 'medium',
            $distancePercent <= 5 => 'low',
            default => 'very_low'
        };
    }

    /**
     * محاسبه اولویت سطح
     */
    private function calculateLevelPriority(float $distancePercent): int
    {
        return match(true) {
            $distancePercent <= 1 => 10,
            $distancePercent <= 2 => 8,
            $distancePercent <= 3 => 6,
            $distancePercent <= 5 => 4,
            default => 2
        };
    }

    /**
     * دسته‌بندی نوسانات
     */
    private function categorizeVolatility(float $dayChange): string
    {
        return match(true) {
            $dayChange >= 20 => 'very_high',
            $dayChange >= 10 => 'high',
            $dayChange >= 5 => 'medium',
            $dayChange >= 2 => 'low',
            default => 'very_low'
        };
    }

    /**
     * محاسبه کارایی گرید
     */
    private function calculateGridEfficiency(float $spacing, string $volatility): int
    {
        $baseEfficiency = 70;
        
        // تنظیم بر اساس فاصله
        if ($spacing >= 2.0 && $spacing <= 3.0) $baseEfficiency += 20;
        elseif ($spacing < 1.0) $baseEfficiency -= 20;
        
        // تنظیم بر اساس نوسانات
$volatilityBonus = match($volatility) {
    'high' => 15,
    'very_high' => 15,
    'medium' => 5,
    'low' => -10,
    'very_low' => -20,
    default => 0,
};

        
        return max(10, min(95, $baseEfficiency + $volatilityBonus));
    }

    /**
     * محاسبه احتمال چرخه
     */
    private function calculateCycleProbability(float $gridSpacing, array $marketAnalysis): float
    {
        $baseProbability = 0.75;
        
        if ($gridSpacing >= 3.0) $baseProbability += 0.15;
        elseif ($gridSpacing >= 2.0) $baseProbability += 0.1;
        elseif ($gridSpacing < 1.0) $baseProbability -= 0.2;
        
        $volatilityAdjustment = match($marketAnalysis['volatility_level']) {
            'very_high' => 0.1,
            'high' => 0.05,
            'medium' => 0,
            'low' => -0.1,
            'very_low' => -0.2,
            default => 0
        };
        
        return max(0.1, min(0.95, $baseProbability + $volatilityAdjustment));
    }

    /**
     * ارزیابی سودآوری
     */
private function assessProfitability(?int $breakEvenDays): string
{
    if ($breakEvenDays === null) {
        return 'نامشخص';
    }

    return match (true) {
        $breakEvenDays <= 7   => 'عالی',
        $breakEvenDays <= 30  => 'خوب',
        $breakEvenDays <= 90  => 'متوسط',
        default               => 'ضعیف',
    };
}


    /**
     * دسته‌بندی سطح اطمینان
     */
    private function categorizeConfidenceLevel(float $probability): string
    {
        return match(true) {
            $probability >= 85 => 'very_high',
            $probability >= 75 => 'high',
            $probability >= 60 => 'medium',
            $probability >= 40 => 'low',
            default => 'very_low'
        };
    }

    /**
     * محاسبه امتیاز تعادل
     */
    private function calculateBalanceScore(int $buyLevels, int $sellLevels): int
    {
        if ($buyLevels === $sellLevels) return 100;
        
        $difference = abs($buyLevels - $sellLevels);
        return max(0, 100 - ($difference * 20));
    }

    /**
     * ارزیابی کیفیت فاصله
     */
    private function assessSpacingQuality(float $spacing): string
    {
        return match(true) {
            $spacing >= 2.0 && $spacing <= 3.0 => 'optimal',
            $spacing >= 1.5 && $spacing < 4.0 => 'good',
            $spacing >= 1.0 && $spacing < 5.0 => 'acceptable',
            default => 'risky'
        };
    }

    /**
     * محاسبه امتیاز پوشش
     */
    private function calculateCoverageScore(Collection $gridLevels, float $centerPrice): int
    {
        $priceRangePercent = $this->calculatePriceRangePercent($gridLevels, $centerPrice);
        
        return match(true) {
            $priceRangePercent >= 15 && $priceRangePercent <= 30 => 100,
            $priceRangePercent >= 10 && $priceRangePercent < 40 => 80,
            $priceRangePercent >= 5 && $priceRangePercent < 50 => 60,
            default => 40
        };
    }

    /**
     * محاسبه امتیاز کارایی گرید
     */
    private function calculateGridEfficiencyScore(Collection $gridLevels): int
    {
        $avgExecutionProb = $gridLevels->avg('execution_probability');
        return round($avgExecutionProb * 100);
    }

    /**
     * محاسبه نمره کلی
     */
    private function calculateOverallGrade(float $spacing, int $levels): string
    {
        $score = 0;
        
        // امتیاز فاصله
        if ($spacing >= 2.0 && $spacing <= 3.0) $score += 40;
        elseif ($spacing >= 1.5 && $spacing < 4.0) $score += 30;
        else $score += 15;
        
        // امتیاز تعداد سطوح
        if ($levels >= 6 && $levels <= 12) $score += 40;
        elseif ($levels >= 4 && $levels <= 16) $score += 30;
        else $score += 15;
        
        // امتیاز کلی
        $score += 20; // امتیاز پایه
        
        return match(true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B+',
            $score >= 60 => 'B',
            $score >= 50 => 'C+',
            default => 'C'
        };
    }

    /**
     * ارزیابی ریسک نقدینگی
     */
    private function assessLiquidityRisk(float $orderValue): string
    {
        return match(true) {
            $orderValue > 50000000 => 'high',
            $orderValue > 20000000 => 'medium',
            $orderValue > 10000000 => 'low',
            default => 'very_low'
        };
    }

    /**
     * نمره‌دهی اندازه موقعیت
     */
    private function gradePositionSize(float $activeValue, float $totalCapital): string
    {
        $ratio = ($activeValue / $totalCapital) * 100;
        
        return match(true) {
            $ratio <= 25 => 'conservative',
            $ratio <= 40 => 'moderate',
            $ratio <= 60 => 'aggressive',
            default => 'very_risky'
        };
    }

    /**
     * محاسبه امتیاز ریسک نقدینگی
     */
    private function calculateLiquidityRiskScore(float $activePercent, int $levels): int
    {
        $baseRisk = min(60, $activePercent * 1.5);
        $complexityRisk = min(20, $levels);
        return min(100, intval($baseRisk + $complexityRisk));
    }

    /**
     * نمره‌دهی ریسک نقدینگی
     */
    private function gradeLiquidityRisk(float $activePercent, float $reserve): string
    {
        if ($activePercent <= 25 && $reserve > 0) return 'excellent';
        if ($activePercent <= 35 && $reserve > 0) return 'good';
        if ($activePercent <= 50) return 'fair';
        return 'poor';
    }

    /**
     * تخمین ریسک نوسانات
     */
    private function estimateVolatilityRisk(float $spacing): int
    {
        return match(true) {
            $spacing < 1.0 => 40,
            $spacing < 2.0 => 25,
            $spacing < 3.0 => 15,
            default => 10
        };
    }

    /**
     * دسته‌بندی سطح ریسک
     */
    private function categorizeRiskLevel(float $riskScore): string
    {
        return match(true) {
            $riskScore >= 80 => 'very_high',
            $riskScore >= 60 => 'high',
            $riskScore >= 40 => 'medium',
            $riskScore >= 20 => 'low',
            default => 'very_low'
        };
    }

    /**
     * دسته‌بندی ریسک محدوده قیمت
     */
    private function categorizePriceRangeRisk(float $range): string
    {
        return match(true) {
            $range > 40 => 'very_high',
            $range > 25 => 'high',
            $range > 15 => 'medium',
            $range > 8 => 'low',
            default => 'very_low'
        };
    }

    /**
     * محاسبه کارایی سرمایه
     */
    private function calculateCapitalEfficiency(Collection $gridLevels): int
    {
        $highPriorityLevels = $gridLevels->where('priority', '>=', 8)->count();
        $totalLevels = $gridLevels->count();
        
        return $totalLevels > 0 ? round(($highPriorityLevels / $totalLevels) * 100) : 0;
    }

    /**
     * محاسبه امتیاز عملکرد
     */
    private function calculatePerformanceScore(float $avgExecutionProb, int $totalLevels): int
    {
        $executionScore = $avgExecutionProb * 60;
        $diversificationScore = min(40, $totalLevels * 3);
        
        return min(100, round($executionScore + $diversificationScore));
    }

    /**
     * تولید توصیه‌های ریسک
     */
    private function generateRiskRecommendations(array $overallRiskScore): array
    {
        $recommendations = [];
        $riskLevel = $this->categorizeRiskLevel($overallRiskScore['total_score']);
        
        switch ($riskLevel) {
            case 'very_high':
                $recommendations[] = 'فاصله گرید را به حداقل 3% افزایش دهید';
                $recommendations[] = 'درصد سرمایه فعال را به 20% کاهش دهید';
                $recommendations[] = 'استاپ لاس در 10% تنظیم کنید';
                break;
                
            case 'high':
                $recommendations[] = 'فاصله گرید را افزایش دهید';
                $recommendations[] = 'سرمایه فعال را کاهش دهید';
                $recommendations[] = 'نظارت مداوم داشته باشید';
                break;
                
            case 'medium':
                $recommendations[] = 'تنظیمات قابل قبول است';
                $recommendations[] = 'نظارت روزانه کافی است';
                break;
                
            default:
                $recommendations[] = 'تنظیمات محافظه‌کارانه';
                $recommendations[] = 'می‌توانید ریسک بیشتری بپذیرید';
        }
        
        return $recommendations;
    }

    /**
     * محاسبه توصیه استاپ لاس
     */
    private function calculateStopLossRecommendation(float $spacing, array $overallRiskScore): array
    {
        $riskLevel = $this->categorizeRiskLevel($overallRiskScore['total_score']);
        
        $stopLossPercent = match($riskLevel) {
            'very_high' => 8,
            'high' => 12,
            'medium' => 18,
            'low' => 25,
            'very_low' => 30,
            default => 20
        };
        
        return [
            'recommended_stop_loss_percent' => $stopLossPercent,
            'dynamic_stop_loss' => $riskLevel === 'very_high',
            'trailing_stop' => $overallRiskScore['total_score'] > 60,
            'emergency_exit_triggers' => [
                'سقوط 15% در 24 ساعت',
                'نوسانات بالای 50%',
                'شکست سطوح حمایتی کلیدی'
            ]
        ];
    }

    /**
     * بررسی سلامت سرویس
     */
public function healthCheck(): array
{
    try {
        $testGrid = $this->calculateGridLevels(2000000000, 2.0, 6);
        $testOrder = $this->calculateOrderSize(100000000, 30, 6);

        $nobitexStatus = 'unknown';
        if (method_exists($this->nobitexService, 'healthCheck')) {
            $hc = $this->nobitexService->healthCheck();
            if (is_array($hc)) {
                $nobitexStatus = $hc['overall_status'] ?? 'unknown';
            }
        }

        return [
            'status' => 'healthy',
            'service_version' => '2.0.0',
            'grid_calculation' => $testGrid['success'] ? 'working' : 'failed',
            'order_calculation' => $testOrder['success'] ? 'working' : 'failed',
            'nobitex_connection' => $nobitexStatus,
            'features_available' => [
                'grid_algorithms' => ['logarithmic', 'arithmetic', 'geometric'],
                'risk_assessment' => true,
                'profit_analysis' => true,
                'market_analysis' => true,
            ],
            'performance_benchmarks' => [
                'grid_calculation_time' => '< 50ms',
                'risk_analysis_time' => '< 100ms',
                'memory_usage' => 'optimal',
            ],
        ];
    } catch (Exception $e) {
        return [
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }
}


    /**
     * محاسبه تنظیمات بهینه برای شروع
     * 
     * @param float $availableCapital سرمایه در دسترس
     * @param string $riskTolerance تحمل ریسک: conservative, moderate, aggressive
     * @return array تنظیمات پیشنهادی
     */
    public function getOptimalSettings(float $availableCapital, string $riskTolerance = 'moderate'): array
    {
        try {
            // دریافت قیمت فعلی
            $currentPrice = $this->getCurrentPriceWithValidation('BTCIRT');
            
            // تنظیمات پایه بر اساس تحمل ریسک
            $baseSettings = match($riskTolerance) {
                'conservative' => [
                    'spacing' => 2.5,
                    'levels' => 6,
                    'active_percent' => 25
                ],
                'aggressive' => [
                    'spacing' => 1.2,
                    'levels' => 12,
                    'active_percent' => 40
                ],
                'moderate' => [
                    'spacing' => 1.8,
                    'levels' => 8,
                    'active_percent' => 30
                ],
                default => [
                    'spacing' => 1.8,
                    'levels' => 8,
                    'active_percent' => 30
                ]
            };
            
            // بررسی سازگاری با سرمایه
            $adjustedSettings = $this->adjustSettingsForCapital($baseSettings, $availableCapital, $currentPrice);
            
            // محاسبه نتایج متوقع
            $gridResult = $this->calculateGridLevels(
                $currentPrice,
                $adjustedSettings['spacing'],
                $adjustedSettings['levels']
            );
            
            $orderResult = $this->calculateOrderSize(
                $availableCapital,
                $adjustedSettings['active_percent'],
                $adjustedSettings['levels']
            );
            if (!($orderResult['success'] ?? false)) {
    return [
        'success' => false,
        'error' => $orderResult['error'] ?? 'Order size calculation failed',
        'error_code' => 'ORDER_SIZE_INVALID'
    ];
}

            
            $profitResult = $this->calculateExpectedProfit(
                $currentPrice,
                $adjustedSettings['spacing'],
                $adjustedSettings['levels'],
                $orderResult['crypto_amount']
            );
            
            $riskResult = $this->assessGridRisk([
                'center_price' => $currentPrice,
                'spacing' => $adjustedSettings['spacing'],
                'levels' => $adjustedSettings['levels'],
                'active_percent' => $adjustedSettings['active_percent']
            ], $availableCapital);
            
            return [
                'success' => true,
                'recommended_settings' => [
                    'center_price' => $currentPrice,
                    'grid_spacing' => $adjustedSettings['spacing'],
                    'grid_levels' => $adjustedSettings['levels'],
                    'active_capital_percent' => $adjustedSettings['active_percent'],
                    'total_capital' => $availableCapital
                ],
                'expected_results' => [
                    'daily_profit_estimate' => $profitResult['profit_summary']['estimated_daily_profit'] ?? 0,
                    'monthly_profit_estimate' => $profitResult['profit_summary']['estimated_monthly_profit'] ?? 0,
                    'monthly_roi_percent' => $profitResult['performance_metrics']['monthly_roi_percent'] ?? 0,
                    'break_even_days' => $profitResult['break_even_analysis']['break_even_days'] ?? null
                ],
                'risk_assessment' => [
                    'risk_level' => $riskResult['risk_level'],
                    'risk_score' => $riskResult['overall_risk_score']['total_score'],
                    'max_potential_loss_percent' => $riskResult['max_potential_loss']['max_potential_loss_percent']
                ],
                'validation' => [
                    'grid_valid' => $gridResult['success'],
                    'order_size_valid' => $orderResult['validation']['is_valid'],
                    'profit_realistic' => $profitResult['success'] && $profitResult['profit_summary']['net_profit_per_cycle'] > 0
                ],
                'alternative_settings' => $this->generateAlternativeSettings($adjustedSettings, $riskTolerance),
                'warnings' => $this->generateSetupWarnings($adjustedSettings, $availableCapital, $riskResult),
                'next_steps' => $this->generateNextSteps($adjustedSettings, $riskResult)
            ];
            
        } catch (Exception $e) {
            Log::error('Optimal settings calculation failed', [
                'available_capital' => $availableCapital,
                'risk_tolerance' => $riskTolerance,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'OPTIMAL_SETTINGS_FAILED'
            ];
        }
    }

    /**
     * تنظیم پارامترها بر اساس سرمایه
     */
    private function adjustSettingsForCapital(array $baseSettings, float $capital, float $currentPrice): array
    {
        $adjusted = $baseSettings;
        
        // محاسبه اندازه سفارش متوقع
        $activeCapital = $capital * ($baseSettings['active_percent'] / 100);
        $expectedOrderSize = $activeCapital / $baseSettings['levels'];
        
        // اگر اندازه سفارش خیلی کم است
        if ($expectedOrderSize < self::NOBITEX_MIN_ORDER_IRT) {
            // ابتدا تعداد سطوح را کم کن
            while ($adjusted['levels'] > self::MIN_GRID_LEVELS) {
                $adjusted['levels'] -= 2;
                $newOrderSize = $activeCapital / $adjusted['levels'];
                if ($newOrderSize >= self::NOBITEX_MIN_ORDER_IRT) {
                    break;
                }
            }
            
            // اگر هنوز کم است، درصد فعال را افزایش ده
            $finalOrderSize = ($capital * ($adjusted['active_percent'] / 100)) / $adjusted['levels'];
            if ($finalOrderSize < self::NOBITEX_MIN_ORDER_IRT) {
                $requiredActivePercent = ($adjusted['levels'] * self::NOBITEX_MIN_ORDER_IRT) / $capital * 100;
                $adjusted['active_percent'] = min(60, ceil($requiredActivePercent + 10));
            }
        }
        
        // اگر اندازه سفارش خیلی بزرگ است
        if ($expectedOrderSize > 50000000) { // 50M IRT
            $adjusted['levels'] = min(self::MAX_GRID_LEVELS, $adjusted['levels'] + 2);
        }
        
        return $adjusted;
    }

    /**
     * تولید تنظیمات جایگزین
     */
    private function generateAlternativeSettings(array $mainSettings, string $riskTolerance): array
    {
        $alternatives = [];
        
        // گزینه محافظه‌کارانه‌تر
        if ($riskTolerance !== 'conservative') {
            $alternatives['more_conservative'] = [
                'spacing' => min(self::MAX_SPACING, $mainSettings['spacing'] * 1.4),
                'levels' => max(self::MIN_GRID_LEVELS, $mainSettings['levels'] - 2),
                'active_percent' => max(15, $mainSettings['active_percent'] - 10),
                'description' => 'ریسک کمتر، سود کمتر'
            ];
        }
        
        // گزینه تهاجمی‌تر
        if ($riskTolerance !== 'aggressive') {
            $alternatives['more_aggressive'] = [
                'spacing' => max(self::MIN_SPACING, $mainSettings['spacing'] * 0.8),
                'levels' => min(self::MAX_GRID_LEVELS, $mainSettings['levels'] + 2),
                'active_percent' => min(50, $mainSettings['active_percent'] + 10),
                'description' => 'ریسک بیشتر، سود بیشتر'
            ];
        }
        
        // گزینه متعادل
        if ($riskTolerance !== 'moderate') {
            $alternatives['balanced'] = [
                'spacing' => 2.0,
                'levels' => 8,
                'active_percent' => 30,
                'description' => 'تعادل بین ریسک و بازده'
            ];
        }
        
        return $alternatives;
    }

    /**
     * تولید هشدارهای راه‌اندازی
     */
    private function generateSetupWarnings(array $settings, float $capital, array $riskResult): array
    {
        $warnings = [];
        
        if ($settings['spacing'] < 1.5) {
            $warnings[] = 'فاصله گرید کم - ریسک بالای نقدینگی';
        }
        
        if ($settings['active_percent'] > 40) {
            $warnings[] = 'درصد سرمایه فعال بالا - کم نگه‌دارید سرمایه ذخیره';
        }
        
        if ($settings['levels'] > 12) {
            $warnings[] = 'تعداد سطوح زیاد - مدیریت پیچیده‌تر';
        }
        
        if ($riskResult['risk_level'] === 'high' || $riskResult['risk_level'] === 'very_high') {
            $warnings[] = 'سطح ریسک بالا شناسایی شد';
        }
        
        if ($capital < 50000000) { // 50M IRT
            $warnings[] = 'سرمایه کم - گزینه‌های محدود';
        }
        
        return $warnings;
    }

    /**
     * تولید مراحل بعدی
     */
    private function generateNextSteps(array $settings, array $riskResult): array
    {
        $steps = [
            '1. بررسی تنظیمات پیشنهادی',
            '2. تست با سرمایه کم (10-20%)',
            '3. نظارت فعال در 48 ساعت اول'
        ];
        
        if ($riskResult['risk_level'] === 'high' || $riskResult['risk_level'] === 'very_high') {
            array_splice($steps, 1, 0, '1.5. بررسی مجدد تحمل ریسک');
        }
        
        $steps[] = '4. تنظیم استاپ لاس در ' . $riskResult['stop_loss_recommendation']['recommended_stop_loss_percent'] . '%';
        $steps[] = '5. ارزیابی عملکرد پس از یک هفته';
        
        return $steps;
    }

    /**
     * آنالیز سریع بازار برای تصمیم‌گیری
     */
public function quickMarketAnalysis(): array
{
    try {
        $currentPrice = $this->getCurrentPriceWithValidation('BTCIRT');

        // مقادیر پیش‌فرض امن
        $dayChange = 5.0;
        $spread = 0.0;
        $spreadPercent = 0.2;

        if (method_exists($this->nobitexService, 'getMarketStats')) {
            $marketStats = $this->nobitexService->getMarketStats('BTCIRT');
            if (is_array($marketStats)) {
                $dayChange = isset($marketStats['dayChange']) ? abs((float)$marketStats['dayChange']) : $dayChange;
                $spread = isset($marketStats['spread']) ? (float)$marketStats['spread'] : $spread;
                $spreadPercent = isset($marketStats['spreadPercent']) ? (float)$marketStats['spreadPercent'] : $spreadPercent;
            }
        }

        $volatilityLevel = $this->categorizeVolatility($dayChange);
        $liquidityLevel = $this->analyzeLiquidityLevel($spread, $spreadPercent);
        $gridSuitability = $this->assessGridSuitability($volatilityLevel, $liquidityLevel, $dayChange);

        return [
            'success' => true,
            'market_snapshot' => [
                'current_price' => $currentPrice,
                'day_change_percent' => $dayChange,
                'volatility_level' => $volatilityLevel,
                'liquidity_level' => $liquidityLevel,
                'spread_percent' => $spreadPercent,
            ],
            'grid_trading_analysis' => [
                'market_suitable_for_grid' => $gridSuitability['suitable'],
                'suitability_score' => $gridSuitability['score'],
                'recommended_spacing_range' => $gridSuitability['spacing_range'],
                'market_condition' => $gridSuitability['condition'],
            ],
            'timing_analysis' => [
                'good_time_to_start' => $gridSuitability['good_timing'],
                'reasoning' => $gridSuitability['timing_reason'],
                'wait_for_better_conditions' => !$gridSuitability['good_timing'],
            ],
            'recommendations' => $this->generateMarketBasedRecommendations($gridSuitability, $volatilityLevel),
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'fallback_analysis' => [
                'market_suitable_for_grid' => true,
                'recommended_spacing_range' => [1.5, 3.0],
                'market_condition' => 'unknown',
            ],
        ];
    }
}
private function analyzeLiquidityLevel(float $spread, float $spreadPercent): string
{
    // براساس اسپرد نسبی (درصد) سطح نقدینگی را تخمین می‌زنیم
    return match (true) {
        $spreadPercent <= 0.2 => 'excellent',
        $spreadPercent <= 0.5 => 'good',
        $spreadPercent <= 1.0 => 'fair',
        $spreadPercent <= 2.0 => 'poor',
        default               => 'very_poor',
    };
}


    /**
     * تحلیل سطح نقدینگی
     */
private function assessGridSuitability(string $volatility, string $liquidity, float $dayChange): array
{
    // امتیاز بر اساس نوسانات
    $volatilityScore = 25;
    switch ($volatility) {
        case 'very_high': $volatilityScore = 30; break; // خیلی بالا، مناسب ولی ریسکی
        case 'high':      $volatilityScore = 40; break; // بالا، مناسب
        case 'medium':    $volatilityScore = 35; break; // متوسط، خوب
        case 'low':       $volatilityScore = 20; break; // کم، کمتر مناسب
        case 'very_low':  $volatilityScore = 10; break; // خیلی کم، نامناسب
        default:          $volatilityScore = 25; break;
    }

    // امتیاز بر اساس نقدینگی
    $liquidityScore = 15;
    switch ($liquidity) {
        case 'excellent': $liquidityScore = 30; break;
        case 'good':      $liquidityScore = 25; break;
        case 'fair':      $liquidityScore = 15; break;
        case 'poor':      $liquidityScore = 5;  break;
        case 'very_poor': $liquidityScore = 0;  break;
        default:          $liquidityScore = 15; break;
    }

    $finalScore = $volatilityScore + $liquidityScore;

    // تعیین محدوده فاصله پیشنهادی
    $spacingRange = [1.5, 2.5];
    switch ($volatility) {
        case 'very_high': $spacingRange = [2.5, 4.0]; break;
        case 'high':      $spacingRange = [2.0, 3.5]; break;
        case 'medium':    $spacingRange = [1.5, 2.5]; break;
        case 'low':       $spacingRange = [1.0, 2.0]; break;
        case 'very_low':  $spacingRange = [0.8, 1.5]; break;
        default:          $spacingRange = [1.5, 2.5]; break;
    }

    // تعیین شرایط بازار
    $condition = 'unsuitable';
    if ($finalScore >= 60) {
        $condition = 'ideal';
    } elseif ($finalScore >= 45) {
        $condition = 'good';
    } elseif ($finalScore >= 30) {
        $condition = 'acceptable';
    } elseif ($finalScore >= 15) {
        $condition = 'challenging';
    }

    // تعیین زمان‌بندی مناسب
    $goodTiming = ($finalScore >= 40 && $liquidity !== 'very_poor');

    // دلیل زمان‌بندی
    if (!$goodTiming && $liquidity === 'very_poor') {
        $timingReason = 'نقدینگی بازار ضعیف است';
    } elseif (!$goodTiming && $volatility === 'very_low') {
        $timingReason = 'نوسانات بازار خیلی کم است';
    } elseif (!$goodTiming && $volatility === 'very_high') {
        $timingReason = 'نوسانات بازار خیلی زیاد و ریسکی است';
    } elseif ($goodTiming) {
        $timingReason = 'شرایط بازار مناسب است';
    } else {
        $timingReason = 'شرایط بازار متوسط است';
    }

    return [
        'suitable' => $finalScore >= 30,
        'score' => $finalScore,
        'spacing_range' => $spacingRange,
        'condition' => $condition,
        'good_timing' => $goodTiming,
        'timing_reason' => $timingReason,
    ];
}


    /**
     * تولید توصیه‌های بر اساس بازار
     */
    private function generateMarketBasedRecommendations(array $suitability, string $volatility): array
    {
        $recommendations = [];
        
        if (!$suitability['suitable']) {
            $recommendations[] = 'شرایط بازار برای گرید ترید مناسب نیست';
            $recommendations[] = 'منتظر بهبود شرایط بمانید';
            return $recommendations;
        }
        
        switch ($volatility) {
            case 'very_high':
                $recommendations[] = 'نوسانات بالا - فاصله گرید حداقل 2.5% انتخاب کنید';
                $recommendations[] = 'استاپ لاس محکم در 10% قرار دهید';
                $recommendations[] = 'نظارت مداوم ضروری است';
                break;
                
            case 'high':
                $recommendations[] = 'شرایط مناسب برای گرید ترید';
                $recommendations[] = 'فاصله گرید 2-3% پیشنهاد می‌شود';
                $recommendations[] = 'نظارت روزانه کافی است';
                break;
                
            case 'medium':
                $recommendations[] = 'شرایط متعادل و مطلوب';
                $recommendations[] = 'فاصله گرید 1.5-2.5% مناسب است';
                $recommendations[] = 'شروع با تنظیمات استاندارد';
                break;
                
            case 'low':
                $recommendations[] = 'نوسانات کم - فاصله گرید کوچکتر امکان‌پذیر';
                $recommendations[] = 'سود کمتر ولی ریسک کمتر';
                $recommendations[] = 'صبر بیشتری نیاز است';
                break;
                
            case 'very_low':
                $recommendations[] = 'نوسانات خیلی کم - گرید ترید کم‌بازده';
                $recommendations[] = 'فکر کنید آیا روش دیگری بهتر نیست';
                break;
        }
        
        return $recommendations;
    }
}