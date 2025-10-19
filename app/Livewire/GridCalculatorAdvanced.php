<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Services\GridCalculatorService;
use App\Services\NobitexService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class GridCalculatorAdvanced extends Component
{
    // ========== Form Properties ==========
    public float $current_price = 0;
    public float $total_capital = 100000000; // 100M IRR default
    public float $active_capital_percent = 30;
    public float $grid_spacing = 1.8;
    public int $grid_levels = 8;
    public string $strategy_type = 'balanced';
    public bool $use_dynamic_spacing = false;
    public bool $enable_stop_loss = true;
    public float $stop_loss_percent = 15;
    public float $take_profit_percent = 0;
    public float $rebalance_threshold = 10;
    public float $fee_rate = 0.35;
    public int $simulation_days = 30;
    public string $grid_distribution = 'logarithmic';
    public float $custom_multiplier = 1.0;
    public string $time_filter = 'none';
    public ?string $custom_notes = null;

    // ========== Result Properties ==========
    public ?Collection $gridLevels = null;
    public ?array $calculationResults = null;
    public ?array $expectedProfit = null;
    public ?array $riskAnalysis = null;
    public ?array $marketAnalysis = null;
    public ?array $historicalBacktest = null;
    public ?array $performanceMetrics = null;
    public ?array $optimizationSuggestions = null;
    public bool $isCalculated = false;

    // ========== UI State Properties ==========
    public float $realTimePrice = 0;
    public ?array $marketTrend = null;
    public bool $comparisonMode = false;
    public ?array $simulationResults = null;
    public array $savedPresets = [];
    public bool $showAdvancedOptions = false;
    public string $lastCalculationTime = '';
    public array $calculationHistory = [];

    // ========== Chart Properties ==========
    public string $chartType = 'line';
    public bool $showGridLines = true;

    // ========== Services ==========
    private GridCalculatorService $gridCalculator;
    private NobitexService $nobitexService;

    // ========== Lifecycle Methods ==========
    
    public function mount(): void
    {
        $this->gridCalculator = app(GridCalculatorService::class);
        $this->nobitexService = app(NobitexService::class);
        
        $this->loadRealTimeData();
        $this->loadSavedPresets();
        $this->applyIntelligentDefaults();
        
        Log::info('GridCalculatorAdvanced component mounted', [
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    public function hydrate(): void
    {
        $this->gridCalculator = app(GridCalculatorService::class);
        $this->nobitexService = app(NobitexService::class);
    }

    // ========== Computed Properties ==========
    
    #[Computed]
    public function activeCapital(): float
    {
        return ($this->total_capital * $this->active_capital_percent) / 100;
    }

    #[Computed]
    public function orderSize(): float
    {
        if ($this->grid_levels <= 0) return 0;
        return $this->activeCapital / $this->grid_levels;
    }

    #[Computed]
    public function priceRange(): array
    {
        if (!$this->gridLevels || $this->gridLevels->isEmpty()) {
            return ['min' => 0, 'max' => 0, 'spread' => 0, 'spread_percent' => 0];
        }

        $prices = $this->gridLevels->pluck('price');
        $min = $prices->min();
        $max = $prices->max();
        $spread = $max - $min;
        $spreadPercent = $this->current_price > 0 ? ($spread / $this->current_price) * 100 : 0;

        return [
            'min' => $min,
            'max' => $max,
            'spread' => $spread,
            'spread_percent' => $spreadPercent
        ];
    }

    #[Computed]
    public function buyLevelsCount(): int
    {
        if (!$this->gridLevels) return 0;
        return $this->gridLevels->where('type', 'buy')->count();
    }

    #[Computed]
    public function sellLevelsCount(): int
    {
        if (!$this->gridLevels) return 0;
        return $this->gridLevels->where('type', 'sell')->count();
    }

    #[Computed]
    public function lowestBuyPrice(): float
    {
        if (!$this->gridLevels) return 0;
        $buyLevels = $this->gridLevels->where('type', 'buy');
        return $buyLevels->isEmpty() ? 0 : $buyLevels->min('price');
    }

    #[Computed]
    public function highestSellPrice(): float
    {
        if (!$this->gridLevels) return 0;
        $sellLevels = $this->gridLevels->where('type', 'sell');
        return $sellLevels->isEmpty() ? 0 : $sellLevels->max('price');
    }

    // ========== Main Calculation Methods ==========
    
    public function calculateGrid(): void
    {
        try {
            $this->validateInputs();
            
            $startTime = microtime(true);
            
            // Calculate grid levels
            $this->gridLevels = $this->gridCalculator->calculateGridLevels(
                $this->current_price,
                $this->grid_spacing,
                $this->grid_levels,
                $this->grid_distribution,
                $this->getMarketDataForCalculation()
            );

            // Calculate order size
            $orderSizeResult = $this->gridCalculator->calculateOrderSize(
                $this->total_capital,
                $this->active_capital_percent,
                $this->grid_levels,
                'BTCIRT',
                $this->getCalculationOptions()
            );

            if (!$orderSizeResult['is_valid']) {
                throw new \Exception('تنظیمات نامعتبر: ' . implode(', ', $orderSizeResult['warnings'] ?? []));
            }

            // Calculate expected profit
            $this->expectedProfit = $this->gridCalculator->calculateExpectedProfit(
                $this->current_price,
                $this->grid_spacing,
                $this->grid_levels,
                $orderSizeResult['crypto_amount'],
                $this->getMarketConditionsForProfit()
            );

            // Risk analysis
            $this->riskAnalysis = $this->gridCalculator->analyzeGridRisk(
                $this->current_price,
                $this->grid_spacing,
                $this->grid_levels,
                $this->total_capital,
                $this->active_capital_percent,
                $this->getRiskParameters()
            );

            // Market analysis
            $this->marketAnalysis = $this->performMarketAnalysis();

            // Performance metrics
            $this->performanceMetrics = $this->calculatePerformanceMetrics();

            // Optimization suggestions
            $this->optimizationSuggestions = $this->generateOptimizationSuggestions();

            // Calculation results summary
            $this->calculationResults = [
                'active_capital' => $this->activeCapital,
                'order_size_irt' => $orderSizeResult['irt_value_per_order'],
                'order_size_btc' => $orderSizeResult['crypto_amount'],
                'total_orders' => $this->grid_levels,
                'price_range' => $this->priceRange,
                'strategy_type' => $this->strategy_type,
                'estimated_daily_trades' => $this->estimateDailyTrades(),
                'break_even_days' => $this->calculateBreakEvenDays(),
                'risk_score' => $this->calculateRiskScore(),
                'efficiency_score' => $this->calculateEfficiencyScore(),
                'calculation_time' => round((microtime(true) - $startTime) * 1000, 2)
            ];

            $this->isCalculated = true;
            $this->lastCalculationTime = now()->format('H:i:s');
            
            // Save to history
            $this->addToCalculationHistory();
            
            // Dispatch events
            $this->dispatch('grid-calculated', [
                'gridLevels' => $this->gridLevels->toArray(),
                'currentPrice' => $this->current_price,
                'results' => $this->calculationResults
            ]);
            
            $this->dispatch('chart-updated');
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '✅ محاسبه کامل شد',
                'message' => "گرید {$this->grid_levels} سطحی با موفقیت محاسبه شد"
            ]);

            Log::info('Grid calculation completed successfully', [
                'user_id' => auth()->id(),
                'calculation_time' => $this->calculationResults['calculation_time'],
                'grid_levels' => $this->grid_levels,
                'risk_score' => $this->calculationResults['risk_score']
            ]);

        } catch (\Exception $e) {
            Log::error('Grid calculation failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->dispatch('notification', [
                'type' => 'error',
                'title' => '❌ خطا در محاسبه',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function runHistoricalSimulation(): void
    {
        if (!$this->isCalculated) {
            $this->dispatch('notification', [
                'type' => 'warning',
                'title' => '⚠️ ابتدا محاسبه کنید',
                'message' => 'ابتدا گرید را محاسبه کنید'
            ]);
            return;
        }

        try {
            $this->historicalBacktest = $this->gridCalculator->runBacktest(
                $this->getGridSettingsForBacktest(),
                'BTCIRT',
                $this->simulation_days,
                $this->getBacktestOptions()
            );

            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '📊 شبیه‌سازی کامل شد',
                'message' => "نتایج {$this->simulation_days} روز گذشته محاسبه شد"
            ]);

        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در شبیه‌سازی',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function optimizeParameters(): void
    {
        try {
            $optimized = $this->gridCalculator->optimizeGridSettings(
                $this->total_capital,
                'BTCIRT',
                $this->strategy_type,
                $this->getOptimizationConstraints(),
                $this->getOptimizationPreferences()
            );

            if (isset($optimized['optimized_settings'])) {
                $settings = $optimized['optimized_settings'];
                
                $this->grid_spacing = $settings['spacing'] ?? $this->grid_spacing;
                $this->grid_levels = $settings['levels'] ?? $this->grid_levels;
                $this->active_capital_percent = $settings['active_percent'] ?? $this->active_capital_percent;
                
                $this->dispatch('notification', [
                    'type' => 'success',
                    'title' => '🎯 بهینه‌سازی انجام شد',
                    'message' => 'پارامترها بر اساس شرایط فعلی بازار بهینه شدند'
                ]);
                
                // Auto-calculate with optimized parameters
                $this->calculateGrid();
            }

        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در بهینه‌سازی',
                'message' => $e->getMessage()
            ]);
        }
    }

    // ========== Strategy & Presets Methods ==========
    
    public function applyStrategyPreset(string $strategy): void
    {
        $presets = [
            'conservative' => [
                'grid_spacing' => 2.5,
                'grid_levels' => 6,
                'active_capital_percent' => 20,
                'stop_loss_percent' => 10,
                'rebalance_threshold' => 15,
                'use_dynamic_spacing' => false
            ],
            'balanced' => [
                'grid_spacing' => 1.8,
                'grid_levels' => 8,
                'active_capital_percent' => 30,
                'stop_loss_percent' => 15,
                'rebalance_threshold' => 10,
                'use_dynamic_spacing' => true
            ],
            'aggressive' => [
                'grid_spacing' => 1.2,
                'grid_levels' => 12,
                'active_capital_percent' => 50,
                'stop_loss_percent' => 20,
                'rebalance_threshold' => 8,
                'use_dynamic_spacing' => true
            ],
            'adaptive' => [
                'grid_spacing' => $this->getAdaptiveSpacing(),
                'grid_levels' => $this->getAdaptiveLevels(),
                'active_capital_percent' => 35,
                'stop_loss_percent' => 15,
                'rebalance_threshold' => 10,
                'use_dynamic_spacing' => true
            ],
            'scalping' => [
                'grid_spacing' => 0.8,
                'grid_levels' => 16,
                'active_capital_percent' => 40,
                'stop_loss_percent' => 12,
                'rebalance_threshold' => 5,
                'use_dynamic_spacing' => true
            ]
        ];

        if (isset($presets[$strategy])) {
            foreach ($presets[$strategy] as $property => $value) {
                $this->{$property} = $value;
            }
            
            $this->strategy_type = $strategy;
            
            $this->dispatch('notification', [
                'type' => 'info',
                'title' => '⚙️ استراتژی تنظیم شد',
                'message' => "استراتژی '{$strategy}' اعمال شد"
            ]);
        }
    }

    public function savePreset(): void
    {
        try {
            $presetName = 'preset_' . Carbon::now()->format('Y_m_d_H_i_s');
            $displayName = $this->strategy_type . '_' . $this->grid_spacing . '%_' . $this->grid_levels . 'levels';
            
            $presetData = [
                'name' => $displayName,
                'created_at' => now(),
                'parameters' => $this->getFormState(),
                'results' => $this->calculationResults,
                'market_conditions' => $this->marketTrend
            ];
            
            Cache::put('grid_preset_' . $presetName, $presetData, 86400 * 365);
            
            $this->savedPresets[$presetName] = $presetData;
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '💾 پیش‌تنظیم ذخیره شد',
                'message' => "پیش‌تنظیم '{$displayName}' با موفقیت ذخیره شد"
            ]);

        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در ذخیره',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function loadPreset(string $presetKey): void
    {
        try {
            if (isset($this->savedPresets[$presetKey])) {
                $preset = $this->savedPresets[$presetKey];
                $this->loadFormState($preset['parameters']);
                
                $this->dispatch('notification', [
                    'type' => 'success',
                    'title' => '📂 پیش‌تنظیم بارگذاری شد',
                    'message' => "پیش‌تنظیم '{$preset['name']}' بارگذاری شد"
                ]);
            }
        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در بارگذاری',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deletePreset(string $presetKey): void
    {
        try {
            Cache::forget('grid_preset_' . $presetKey);
            unset($this->savedPresets[$presetKey]);
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '🗑️ پیش‌تنظیم حذف شد',
                'message' => 'پیش‌تنظیم با موفقیت حذف شد'
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در حذف',
                'message' => $e->getMessage()
            ]);
        }
    }

    // ========== Export Methods ==========
    
    public function exportResults(): void
    {
        if (!$this->isCalculated) {
            $this->dispatch('notification', [
                'type' => 'warning',
                'title' => '⚠️ ابتدا محاسبه کنید',
                'message' => 'ابتدا گرید را محاسبه کنید'
            ]);
            return;
        }

        try {
            $exportData = [
                'export_info' => [
                    'generated_at' => now()->format('Y-m-d H:i:s'),
                    'version' => '2.0.0',
                    'calculator' => 'Advanced Grid Calculator',
                    'user_id' => auth()->id()
                ],
                'market_data' => [
                    'current_price' => $this->realTimePrice,
                    'market_trend' => $this->marketTrend,
                    'timestamp' => now()
                ],
                'input_parameters' => $this->getFormState(),
                'calculation_results' => $this->calculationResults,
                'grid_levels' => $this->gridLevels?->toArray(),
                'expected_profit' => $this->expectedProfit,
                'risk_analysis' => $this->riskAnalysis,
                'market_analysis' => $this->marketAnalysis,
                'performance_metrics' => $this->performanceMetrics,
                'optimization_suggestions' => $this->optimizationSuggestions,
                'historical_backtest' => $this->historicalBacktest
            ];
            
            $exportKey = 'grid_export_' . now()->format('Y_m_d_H_i_s');
            Cache::put($exportKey, $exportData, 3600);
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '📄 آماده صادرات',
                'message' => 'گزارش کامل آماده دانلود است'
            ]);
            
            // In a real app, you would create a download route
            $this->dispatch('download-ready', ['key' => $exportKey]);

        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در صادرات',
                'message' => $e->getMessage()
            ]);
        }
    }

    // ========== UI Control Methods ==========
    
    public function toggleChartType(): void
    {
        $this->chartType = $this->chartType === 'line' ? 'bar' : 'line';
        $this->dispatch('chart-updated');
    }

    public function toggleGridLines(): void
    {
        $this->showGridLines = !$this->showGridLines;
        $this->dispatch('chart-updated');
    }

    public function toggleAdvancedOptions(): void
    {
        $this->showAdvancedOptions = !$this->showAdvancedOptions;
    }

    public function resetForm(): void
    {
        $this->reset([
            'gridLevels', 'calculationResults', 'expectedProfit', 'riskAnalysis',
            'marketAnalysis', 'historicalBacktest', 'performanceMetrics',
            'optimizationSuggestions', 'isCalculated', 'comparisonMode',
            'simulationResults'
        ]);
        
        $this->applyIntelligentDefaults();
        
        $this->dispatch('notification', [
            'type' => 'info',
            'title' => '🔄 فرم بازنشانی شد',
            'message' => 'تمام مقادیر به حالت پیش‌فرض برگشت'
        ]);
    }

    // ========== Event Listeners ==========
    
    #[On('refresh-real-time-data')]
    public function refreshRealTimeData(): void
    {
        $this->loadRealTimeData();
    }

    #[On('parameter-changed')]
    public function onParameterChanged(): void
    {
        $this->isCalculated = false;
    }

    // ========== Helper Methods ==========
    
    private function loadRealTimeData(): void
    {
        try {
            $this->realTimePrice = $this->nobitexService->getCurrentPrice('BTCIRT');
            $this->current_price = $this->realTimePrice;
            
            $marketStats = $this->nobitexService->getMarketStats('BTCIRT');
            $this->marketTrend = $this->analyzeTrend($marketStats);
            
        } catch (\Exception $e) {
            Log::warning('Error loading real-time data: ' . $e->getMessage());
            $this->realTimePrice = Cache::get('btc_current_price', 6000000000);
            $this->current_price = $this->realTimePrice;
        }
    }

    private function analyzeTrend(array $marketStats): array
    {
        $dayChange = floatval($marketStats['dayChange'] ?? 0);
        
        return [
            'direction' => $dayChange > 2 ? 'bullish' : ($dayChange < -2 ? 'bearish' : 'sideways'),
            'volatility' => abs($dayChange) > 5 ? 'high' : (abs($dayChange) > 2 ? 'medium' : 'low'),
            'change_percent' => $dayChange,
            'recommendation' => $this->getTrendRecommendation($dayChange)
        ];
    }

    private function getTrendRecommendation(float $dayChange): string
    {
        if ($dayChange > 5) return 'بازار صعودی قوی - فاصله گرید بیشتر توصیه می‌شود';
        if ($dayChange > 2) return 'بازار صعودی - تنظیمات متعادل مناسب است';
        if ($dayChange < -5) return 'بازار نزولی قوی - احتیاط کنید';
        if ($dayChange < -2) return 'بازار نزولی - فاصله کمتر توصیه می‌شود';
        return 'بازار خنثی - شرایط ایده‌آل برای گرید ترادینگ';
    }

    private function applyIntelligentDefaults(): void
    {
        $trend = $this->marketTrend['direction'] ?? 'sideways';
        $volatility = $this->marketTrend['volatility'] ?? 'medium';
        
        $this->grid_spacing = match($volatility) {
            'high' => 2.5,
            'medium' => 1.8,
            'low' => 1.2,
            default => 1.5
        };
        
        $this->grid_levels = match($trend) {
            'bullish' => 8,
            'bearish' => 12,
            'sideways' => 10,
            default => 10
        };
    }

    private function loadSavedPresets(): void
    {
        // Load presets from cache - simplified for this example
        $this->savedPresets = [];
    }

    private function validateInputs(): void
    {
        if ($this->current_price <= 0) {
            throw new \Exception('قیمت فعلی نامعتبر است');
        }
        
        if ($this->total_capital < 10000000) {
            throw new \Exception('حداقل سرمایه 10 میلیون ریال است');
        }
        
        if ($this->grid_levels < 4 || $this->grid_levels > 20) {
            throw new \Exception('تعداد سطوح باید بین 4 تا 20 باشد');
        }
        
        if ($this->grid_levels % 2 !== 0) {
            throw new \Exception('تعداد سطوح باید زوج باشد');
        }
        
        if ($this->grid_spacing < 0.5 || $this->grid_spacing > 10) {
            throw new \Exception('فاصله گرید باید بین 0.5% تا 10% باشد');
        }
    }

    private function getFormState(): array
    {
        return [
            'current_price' => $this->current_price,
            'total_capital' => $this->total_capital,
            'active_capital_percent' => $this->active_capital_percent,
            'grid_spacing' => $this->grid_spacing,
            'grid_levels' => $this->grid_levels,
            'strategy_type' => $this->strategy_type,
            'use_dynamic_spacing' => $this->use_dynamic_spacing,
            'enable_stop_loss' => $this->enable_stop_loss,
            'stop_loss_percent' => $this->stop_loss_percent,
            'take_profit_percent' => $this->take_profit_percent,
            'rebalance_threshold' => $this->rebalance_threshold,
            'fee_rate' => $this->fee_rate,
            'simulation_days' => $this->simulation_days,
            'grid_distribution' => $this->grid_distribution,
            'custom_multiplier' => $this->custom_multiplier,
            'time_filter' => $this->time_filter,
            'custom_notes' => $this->custom_notes
        ];
    }

    private function loadFormState(array $state): void
    {
        foreach ($state as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
    }

    // Additional helper methods for calculations
    private function getMarketDataForCalculation(): array
    {
        return [
            'volatility' => $this->marketTrend['volatility'] ?? 'medium',
            'trend' => $this->marketTrend['direction'] ?? 'sideways'
        ];
    }

    private function getCalculationOptions(): array
    {
        return [
            'strategy_type' => $this->strategy_type,
            'custom_multiplier' => $this->custom_multiplier
        ];
    }

    private function getMarketConditionsForProfit(): array
    {
        return [
            'fee_rate' => $this->fee_rate,
            'volatility' => $this->marketTrend['volatility'] ?? 'medium',
            'trend' => $this->marketTrend['direction'] ?? 'sideways'
        ];
    }

    private function getRiskParameters(): array
    {
        return [
            'stop_loss_percent' => $this->stop_loss_percent,
            'max_drawdown_percent' => 25,
            'enable_stop_loss' => $this->enable_stop_loss
        ];
    }

    private function performMarketAnalysis(): array
    {
        return [
            'trend_strength' => rand(60, 95),
            'support_levels' => [
                $this->current_price * 0.95,
                $this->current_price * 0.90,
                $this->current_price * 0.85
            ],
            'resistance_levels' => [
                $this->current_price * 1.05,
                $this->current_price * 1.10,
                $this->current_price * 1.15
            ],
            'volume_analysis' => 'متوسط',
            'sentiment' => $this->marketTrend['direction'] === 'bullish' ? 'مثبت' : 'خنثی'
        ];
    }

    private function calculatePerformanceMetrics(): array
    {
        $spacing = $this->grid_spacing / 100;
        $levels = $this->grid_levels;
        
        return [
            'sharpe_ratio' => round(1.2 + (1 / $spacing), 2),
            'max_drawdown' => round($spacing * $levels * 2, 1),
            'win_rate' => min(85, 60 + (20 / $spacing)),
            'profit_factor' => round(1.4 + (1 / $spacing), 2),
            'avg_trade_duration' => round(24 / ($spacing * 10), 1) . ' ساعت',
            'recovery_time' => round($levels * $spacing, 1) . ' روز'
        ];
    }

    private function generateOptimizationSuggestions(): array
    {
        $suggestions = [];
        
        // Spacing analysis
        if ($this->grid_spacing > 3) {
            $suggestions[] = [
                'type' => 'warning',
                'title' => 'فاصله زیاد',
                'message' => 'فاصله گرید زیاد است، معاملات کمتری خواهید داشت',
                'suggestion' => 'فاصله را به کمتر از 2.5% کاهش دهید'
            ];
        }

        if ($this->grid_spacing < 0.8) {
            $suggestions[] = [
                'type' => 'info',
                'title' => 'فاصله کم',
                'message' => 'فاصله کم منجر به معاملات زیاد و کارمزد بالا می‌شود',
                'suggestion' => 'فاصله را افزایش دهید یا کارمزدها را در نظر بگیرید'
            ];
        }

        // Capital management
        if ($this->active_capital_percent > 60) {
            $suggestions[] = [
                'type' => 'danger',
                'title' => 'ریسک بالا',
                'message' => 'درصد بالای سرمایه فعال ریسک زیادی دارد',
                'suggestion' => 'حداکثر 50% سرمایه را فعال کنید'
            ];
        }

        // Level count
        if ($this->grid_levels > 16) {
            $suggestions[] = [
                'type' => 'info',
                'title' => 'سطوح زیاد',
                'message' => 'تعداد سطوح زیاد مدیریت را پیچیده می‌کند',
                'suggestion' => 'برای شروع 8-12 سطح کافی است'
            ];
        }

        // Market condition based suggestions
        if (($this->marketTrend['volatility'] ?? 'medium') === 'high') {
            $suggestions[] = [
                'type' => 'warning',
                'title' => 'نوسان بالا',
                'message' => 'بازار نوسان بالایی دارد',
                'suggestion' => 'فاصله گرید را افزایش دهید و استاپ لاس تنظیم کنید'
            ];
        }

        return $suggestions;
    }

    private function estimateDailyTrades(): float
    {
        $spacing = $this->grid_spacing / 100;
        $volatility = $this->marketTrend['volatility'] ?? 'medium';
        
        $baseTradeRate = match($volatility) {
            'high' => 8,
            'medium' => 4,
            'low' => 2,
            default => 4
        };
        
        return round($baseTradeRate / $spacing, 1);
    }

    private function calculateBreakEvenDays(): int
    {
        $spacing = $this->grid_spacing / 100;
        $levels = $this->grid_levels;
        $feeRate = $this->fee_rate / 100;
        
        return max(1, round($feeRate * $levels / $spacing));
    }

    private function calculateRiskScore(): int
    {
        $score = 50; // Base score
        
        // Adjust based on parameters
        $score += ($this->active_capital_percent - 30) * 0.5;
        $score += (3 - $this->grid_spacing) * 10;
        $score += ($this->grid_levels - 10) * 0.5;
        
        // Market condition adjustment
        if (($this->marketTrend['volatility'] ?? 'medium') === 'high') {
            $score += 15;
        }
        
        return max(0, min(100, round($score)));
    }

    private function calculateEfficiencyScore(): int
    {
        $score = 70; // Base score
        
        // Spacing efficiency
        $optimalSpacing = 1.5;
        $spacingDeviation = abs($this->grid_spacing - $optimalSpacing);
        $score -= $spacingDeviation * 5;
        
        // Level efficiency
        $optimalLevels = 10;
        $levelDeviation = abs($this->grid_levels - $optimalLevels) / 2;
        $score -= $levelDeviation;
        
        // Capital efficiency
        if ($this->active_capital_percent < 20) {
            $score -= 10;
        } elseif ($this->active_capital_percent > 40) {
            $score -= 5;
        }
        
        return max(0, min(100, round($score)));
    }

    private function addToCalculationHistory(): void
    {
        $historyItem = [
            'timestamp' => now()->toISOString(),
            'parameters' => [
                'spacing' => $this->grid_spacing,
                'levels' => $this->grid_levels,
                'capital' => $this->total_capital,
                'strategy' => $this->strategy_type
            ],
            'results' => [
                'risk_score' => $this->calculationResults['risk_score'],
                'efficiency_score' => $this->calculationResults['efficiency_score'],
                'calculation_time' => $this->calculationResults['calculation_time']
            ]
        ];
        
        // Keep only last 10 calculations
        array_unshift($this->calculationHistory, $historyItem);
        $this->calculationHistory = array_slice($this->calculationHistory, 0, 10);
    }

    private function getAdaptiveSpacing(): float
    {
        $volatility = $this->marketTrend['volatility'] ?? 'medium';
        return match($volatility) {
            'high' => 2.0,
            'medium' => 1.5,
            'low' => 1.0,
            default => 1.5
        };
    }

    private function getAdaptiveLevels(): int
    {
        $direction = $this->marketTrend['direction'] ?? 'sideways';
        return match($direction) {
            'bullish' => 8,
            'bearish' => 12,
            'sideways' => 10,
            default => 10
        };
    }

    private function getGridSettingsForBacktest(): array
    {
        return [
            'current_price' => $this->current_price,
            'grid_spacing' => $this->grid_spacing,
            'grid_levels' => $this->grid_levels,
            'total_capital' => $this->total_capital,
            'active_capital_percent' => $this->active_capital_percent,
            'strategy_type' => $this->strategy_type
        ];
    }

    private function getBacktestOptions(): array
    {
        return [
            'include_details' => false,
            'include_slippage' => true,
            'market_condition' => $this->marketTrend['direction'] ?? 'sideways'
        ];
    }

    private function getOptimizationConstraints(): array
    {
        return [
            'max_active_percent' => 60,
            'min_spacing' => 0.5,
            'max_levels' => 20,
            'min_capital' => 10000000
        ];
    }

    private function getOptimizationPreferences(): array
    {
        return [
            'risk_tolerance' => $this->strategy_type === 'conservative' ? 'low' : 
                              ($this->strategy_type === 'aggressive' ? 'high' : 'medium'),
            'preferred_levels' => $this->grid_levels,
            'max_drawdown_tolerance' => 25
        ];
    }

    // ========== Render Method ==========
    
    public function render()
    {
        return view('livewire.grid-calculator-advanced', [
            'hasCalculatedData' => $this->isCalculated,
            'activeCapital' => $this->activeCapital,
            'orderSize' => $this->orderSize,
            'priceRange' => $this->priceRange,
            'buyLevelsCount' => $this->buyLevelsCount,
            'sellLevelsCount' => $this->sellLevelsCount,
            'lowestBuyPrice' => $this->lowestBuyPrice,
            'highestSellPrice' => $this->highestSellPrice
        ]);
    }

    // ========== Format Helper Methods ==========
    
    public function formatCurrency(float $amount): string
    {
        if ($amount >= 1000000000) {
            return number_format($amount / 1000000000, 1) . ' میلیارد ریال';
        } elseif ($amount >= 1000000) {
            return number_format($amount / 1000000, 1) . ' میلیون ریال';
        } else {
            return number_format($amount, 0) . ' ریال';
        }
    }

    public function formatPrice(float $price): string
    {
        return number_format($price, 0) . ' ریال';
    }

    public function formatAmount(float $amount): string
    {
        return number_format($amount, 8) . ' BTC';
    }

    public function formatPercent(float $percent): string
    {
        return number_format($percent, 2) . '%';
    }

    // ========== UI Helper Methods ==========
    
    public function getMarketTrendDisplay(): string
    {
        if (!$this->marketTrend) return 'در حال بارگذاری...';
        
        $icons = [
            'bullish' => '🐂 صعودی',
            'bearish' => '🐻 نزولی',
            'sideways' => '➡️ خنثی'
        ];
        
        $direction = $this->marketTrend['direction'];
        $change = $this->marketTrend['change_percent'];
        
        return ($icons[$direction] ?? '❓') . " ({$change}%)";
    }

    public function getVolatilityDisplay(): string
    {
        if (!$this->marketTrend) return 'در حال بارگذاری...';
        
        $icons = [
            'high' => '🔥 بالا',
            'medium' => '📊 متوسط',
            'low' => '😴 کم'
        ];
        
        return $icons[$this->marketTrend['volatility']] ?? '❓ نامشخص';
    }

    public function getRiskScoreColor(): string
    {
        if (!$this->calculationResults) return 'text-gray-500';
        
        $score = $this->calculationResults['risk_score'];
        
        if ($score < 30) return 'text-green-600 dark:text-green-400';
        if ($score < 60) return 'text-yellow-600 dark:text-yellow-400';
        return 'text-red-600 dark:text-red-400';
    }

    public function getRiskScoreBarColor(): string
    {
        if (!$this->calculationResults) return 'bg-gray-400';
        
        $score = $this->calculationResults['risk_score'];
        
        if ($score < 30) return 'bg-green-500';
        if ($score < 60) return 'bg-yellow-500';
        return 'bg-red-500';
    }

    public function getRiskLevel(): string
    {
        if (!$this->calculationResults) return 'نامحاسبه';
        
        $score = $this->calculationResults['risk_score'];
        
        if ($score < 30) return 'کم';
        if ($score < 60) return 'متوسط';
        return 'بالا';
    }

    public function getRiskEmoji(): string
    {
        if (!$this->calculationResults) return '❓';
        
        $score = $this->calculationResults['risk_score'];
        
        if ($score < 30) return '🟢';
        if ($score < 60) return '🟡';
        return '🔴';
    }

    public function getLevelDensity(): string
    {
        if (!$this->gridLevels || $this->gridLevels->isEmpty()) return 'نامحاسبه';
        
        $density = $this->grid_levels / $this->priceRange['spread_percent'];
        
        if ($density > 2) return 'متراکم';
        if ($density > 1) return 'متوسط';
        return 'پراکنده';
    }

    public function getSpacingDescription(): string
    {
        if ($this->grid_spacing >= 3) return 'محافظه‌کارانه';
        if ($this->grid_spacing >= 2) return 'متعادل';
        if ($this->grid_spacing >= 1.5) return 'فعال';
        if ($this->grid_spacing >= 1) return 'تهاجمی';
        return 'اسکالپینگ';
    }

    public function getSharpeRatioColor(): string
    {
        if (!$this->performanceMetrics) return 'text-gray-500';
        
        $ratio = $this->performanceMetrics['sharpe_ratio'] ?? 0;
        
        if ($ratio >= 2) return 'text-green-600';
        if ($ratio >= 1) return 'text-yellow-600';
        return 'text-red-600';
    }

    public function getSharpeRatioLabel(): string
    {
        if (!$this->performanceMetrics) return 'نامحاسبه';
        
        $ratio = $this->performanceMetrics['sharpe_ratio'] ?? 0;
        
        if ($ratio >= 2) return 'عالی';
        if ($ratio >= 1) return 'خوب';
        return 'ضعیف';
    }

    public function getWinRateColor(): string
    {
        if (!$this->performanceMetrics) return 'text-gray-500';
        
        $rate = $this->performanceMetrics['win_rate'] ?? 0;
        
        if ($rate >= 80) return 'text-green-600';
        if ($rate >= 60) return 'text-yellow-600';
        return 'text-red-600';
    }

    public function getWinRateLabel(): string
    {
        if (!$this->performanceMetrics) return 'نامحاسبه';
        
        $rate = $this->performanceMetrics['win_rate'] ?? 0;
        
        if ($rate >= 80) return 'عالی';
        if ($rate >= 60) return 'خوب';
        return 'نیاز به بهبود';
    }

    public function getDrawdownColor(): string
    {
        if (!$this->performanceMetrics) return 'text-gray-500';
        
        $drawdown = $this->performanceMetrics['max_drawdown'] ?? 0;
        
        if ($drawdown <= 10) return 'text-green-600';
        if ($drawdown <= 20) return 'text-yellow-600';
        return 'text-red-600';
    }

    public function getDrawdownLabel(): string
    {
        if (!$this->performanceMetrics) return 'نامحاسبه';
        
        $drawdown = $this->performanceMetrics['max_drawdown'] ?? 0;
        
        if ($drawdown <= 10) return 'کم';
        if ($drawdown <= 20) return 'متوسط';
        return 'بالا';
    }
}