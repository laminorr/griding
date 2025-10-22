<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GridStatsCards extends Component
{
    // ========== Core Properties ==========
    public ?Collection $gridLevels = null;
    public ?array $calculationResults = null;
    public ?array $expectedProfit = null;
    public ?array $riskAnalysis = null;
    public ?array $performanceMetrics = null;
    public ?array $marketTrend = null;
    
    // ========== Configuration Properties ==========
    public float $totalCapital = 0;
    public float $activeCapitalPercent = 0;
    public float $gridSpacing = 0;
    public int $gridLevels = 0;
    public float $currentPrice = 0;
    public string $strategyType = 'balanced';
    
    // ========== UI State Properties ==========
    public bool $compactView = false;
    public bool $realTimeUpdates = true;
    public string $displayCurrency = 'irt';
    public bool $showAdvancedMetrics = false;
    public string $lastUpdateTime = '';
    
    // ========== Statistics Cache ==========
    private array $statsCache = [];
    private int $cacheValidFor = 300; // 5 minutes
    
    // ========== Event Listeners ==========
    protected $listeners = [
        'grid-calculated' => 'handleGridCalculated',
        'stats-refresh-requested' => 'refreshStats',
        'market-data-updated' => 'handleMarketDataUpdate'
    ];

    // ========== Lifecycle Methods ==========
    
    public function mount(array $options = []): void
    {
        $this->compactView = $options['compact'] ?? false;
        $this->realTimeUpdates = $options['realTime'] ?? true;
        $this->displayCurrency = $options['currency'] ?? 'irt';
        $this->showAdvancedMetrics = $options['advanced'] ?? false;
        
        $this->loadCachedStats();
        $this->lastUpdateTime = now()->format('H:i:s');
        
        Log::info('GridStatsCards component mounted', [
            'compact' => $this->compactView,
            'real_time' => $this->realTimeUpdates
        ]);
    }

    // ========== Event Handlers ==========
    
    #[On('grid-calculated')]
    public function handleGridCalculated(array $data): void
    {
        try {
            $this->gridLevels = collect($data['gridLevels'] ?? []);
            $this->calculationResults = $data['results'] ?? null;
            $this->currentPrice = $data['currentPrice'] ?? 0;
            
            $this->refreshStats();
            $this->lastUpdateTime = now()->format('H:i:s');
            
            Log::info('Grid stats updated from calculation', [
                'levels_count' => $this->gridLevels?->count() ?? 0,
                'current_price' => $this->currentPrice
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling grid calculation: ' . $e->getMessage());
        }
    }

    #[On('market-data-updated')]
    public function handleMarketDataUpdate(array $marketData): void
    {
        $this->marketTrend = $marketData;
        $this->currentPrice = $marketData['current_price'] ?? $this->currentPrice;
        
        if ($this->realTimeUpdates) {
            $this->refreshStats();
        }
    }

    // ========== Computed Properties ==========
    
    #[Computed]
    public function hasCalculatedData(): bool
    {
        return $this->gridLevels !== null && 
               $this->gridLevels->isNotEmpty() && 
               $this->calculationResults !== null;
    }

    #[Computed]
    public function activeCapital(): float
    {
        return ($this->totalCapital * $this->activeCapitalPercent) / 100;
    }

    #[Computed]
    public function totalLevels(): int
    {
        return $this->gridLevels?->count() ?? 0;
    }

    #[Computed]
    public function buyLevels(): int
    {
        return $this->gridLevels?->where('type', 'buy')->count() ?? 0;
    }

    #[Computed]
    public function sellLevels(): int
    {
        return $this->gridLevels?->where('type', 'sell')->count() ?? 0;
    }

    #[Computed]
    public function minPrice(): float
    {
        return $this->gridLevels?->min('price') ?? 0;
    }

    #[Computed]
    public function maxPrice(): float
    {
        return $this->gridLevels?->max('price') ?? 0;
    }

    #[Computed]
    public function priceRangePercent(): float
    {
        if (!$this->currentPrice || !$this->gridLevels || $this->gridLevels->isEmpty()) {
            return 0;
        }
        
        $range = $this->maxPrice - $this->minPrice;
        return ($range / $this->currentPrice) * 100;
    }

    #[Computed]
    public function riskScore(): int
    {
        return $this->calculationResults['risk_score'] ?? 50;
    }

    #[Computed]
    public function expectedDailyProfit(): float
    {
        return $this->expectedProfit['time_frame_profits']['daily'] ?? 0;
    }

    #[Computed]
    public function expectedDailyProfitPercent(): float
    {
        if (!$this->activeCapital || $this->expectedDailyProfit <= 0) {
            return 0;
        }
        
        return ($this->expectedDailyProfit / $this->activeCapital) * 100;
    }

    #[Computed]
    public function orderSizeIRT(): float
    {
        return $this->calculationResults['order_size_irt'] ?? 0;
    }

    #[Computed]
    public function orderSizeBTC(): float
    {
        return $this->calculationResults['order_size_btc'] ?? 0;
    }

    // ========== Action Methods ==========
    
    public function refreshStats(): void
    {
        try {
            $this->clearStatsCache();
            $this->lastUpdateTime = now()->format('H:i:s');
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '🔄 آمار بروزرسانی شد',
                'message' => 'آمار در ' . $this->lastUpdateTime . ' بروزرسانی شد'
            ]);
            
        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در بروزرسانی',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function toggleCompactView(): void
    {
        $this->compactView = !$this->compactView;
        
        $this->dispatch('notification', [
            'type' => 'info',
            'title' => $this->compactView ? '📱 نمایش فشرده' : '🖥️ نمایش کامل',
            'message' => 'حالت نمایش تغییر کرد'
        ]);
    }

    public function toggleRealTimeUpdates(): void
    {
        $this->realTimeUpdates = !$this->realTimeUpdates;
        
        $this->dispatch('notification', [
            'type' => 'info',
            'title' => $this->realTimeUpdates ? '⚡ بروزرسانی خودکار فعال' : '⏸️ بروزرسانی خودکار غیرفعال',
            'message' => 'تنظیمات بروزرسانی تغییر کرد'
        ]);
    }

    public function toggleAdvancedMetrics(): void
    {
        $this->showAdvancedMetrics = !$this->showAdvancedMetrics;
    }

    public function exportStats(): void
    {
        try {
            $exportData = [
                'export_info' => [
                    'generated_at' => now()->toISOString(),
                    'component' => 'GridStatsCards',
                    'version' => '1.0.0'
                ],
                'basic_stats' => [
                    'active_capital' => $this->activeCapital,
                    'total_levels' => $this->totalLevels,
                    'buy_levels' => $this->buyLevels,
                    'sell_levels' => $this->sellLevels,
                    'price_range_percent' => $this->priceRangePercent,
                    'risk_score' => $this->riskScore
                ],
                'financial_metrics' => [
                    'expected_daily_profit' => $this->expectedDailyProfit,
                    'expected_daily_profit_percent' => $this->expectedDailyProfitPercent,
                    'order_size_irt' => $this->orderSizeIRT,
                    'order_size_btc' => $this->orderSizeBTC
                ],
                'configuration' => [
                    'total_capital' => $this->totalCapital,
                    'active_capital_percent' => $this->activeCapitalPercent,
                    'grid_spacing' => $this->gridSpacing,
                    'strategy_type' => $this->strategyType
                ],
                'performance_metrics' => $this->performanceMetrics,
                'market_trend' => $this->marketTrend
            ];
            
            $exportKey = 'stats_export_' . now()->format('Y_m_d_H_i_s');
            Cache::put($exportKey, $exportData, 3600);
            
            $this->dispatch('stats-exported', ['key' => $exportKey]);
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '📤 آمار صادر شد',
                'message' => 'فایل آمار آماده دانلود است'
            ]);
            
        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در صادرات',
                'message' => $e->getMessage()
            ]);
        }
    }

    // ========== Formatting Methods ==========
    
    public function formatCurrency(float $amount): string
    {
        if ($this->displayCurrency === 'usd') {
            // For display purposes only - update manually when needed to reflect current USD/IRT rate
            $usdAmount = $amount / 70000; // Approximate USD/IRT rate
            return '$' . number_format($usdAmount, 2);
        }
        
        // Default to IRT
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

    // ========== Color & Style Methods ==========
    
    public function getRiskScoreColor(): string
    {
        $score = $this->riskScore;
        
        if ($score < 30) return 'text-green-600 dark:text-green-400';
        if ($score < 60) return 'text-yellow-600 dark:text-yellow-400';
        return 'text-red-600 dark:text-red-400';
    }

    public function getRiskScoreBarColor(): string
    {
        $score = $this->riskScore;
        
        if ($score < 30) return 'bg-green-500';
        if ($score < 60) return 'bg-yellow-500';
        return 'bg-red-500';
    }

    public function getRiskLevel(): string
    {
        $score = $this->riskScore;
        
        if ($score < 30) return 'کم';
        if ($score < 60) return 'متوسط';
        return 'بالا';
    }

    public function getRiskEmoji(): string
    {
        $score = $this->riskScore;
        
        if ($score < 30) return '🟢';
        if ($score < 60) return '🟡';
        return '🔴';
    }

    public function getSpacingDescription(): string
    {
        if ($this->gridSpacing >= 3) return 'محافظه‌کارانه';
        if ($this->gridSpacing >= 2) return 'متعادل';
        if ($this->gridSpacing >= 1.5) return 'فعال';
        if ($this->gridSpacing >= 1) return 'تهاجمی';
        return 'اسکالپینگ';
    }

    // ========== Performance Metrics Methods ==========
    
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

    // ========== Cache Management ==========
    
    private function loadCachedStats(): void
    {
        $cacheKey = 'grid_stats_' . auth()->id();
        $this->statsCache = Cache::get($cacheKey, []);
    }

    private function saveStatsToCache(): void
    {
        $cacheKey = 'grid_stats_' . auth()->id();
        $this->statsCache['last_updated'] = now()->timestamp;
        
        Cache::put($cacheKey, $this->statsCache, $this->cacheValidFor);
    }

    private function clearStatsCache(): void
    {
        $cacheKey = 'grid_stats_' . auth()->id();
        Cache::forget($cacheKey);
        $this->statsCache = [];
    }

    private function isCacheValid(): bool
    {
        if (empty($this->statsCache) || !isset($this->statsCache['last_updated'])) {
            return false;
        }
        
        $lastUpdated = $this->statsCache['last_updated'];
        return (now()->timestamp - $lastUpdated) < $this->cacheValidFor;
    }

    // ========== Helper Methods ==========
    
    public function updateConfiguration(array $config): void
    {
        $this->totalCapital = $config['total_capital'] ?? $this->totalCapital;
        $this->activeCapitalPercent = $config['active_capital_percent'] ?? $this->activeCapitalPercent;
        $this->gridSpacing = $config['grid_spacing'] ?? $this->gridSpacing;
        $this->gridLevels = $config['grid_levels'] ?? $this->gridLevels;
        $this->strategyType = $config['strategy_type'] ?? $this->strategyType;
        
        $this->refreshStats();
    }

    public function updateResults(array $results): void
    {
        $this->calculationResults = $results['calculation_results'] ?? $this->calculationResults;
        $this->expectedProfit = $results['expected_profit'] ?? $this->expectedProfit;
        $this->riskAnalysis = $results['risk_analysis'] ?? $this->riskAnalysis;
        $this->performanceMetrics = $results['performance_metrics'] ?? $this->performanceMetrics;
        
        $this->lastUpdateTime = now()->format('H:i:s');
    }

    public function getHealthScore(): int
    {
        if (!$this->hasCalculatedData) {
            return 0;
        }
        
        $score = 100;
        
        // Risk factor
        $riskPenalty = max(0, ($this->riskScore - 50) * 0.5);
        $score -= $riskPenalty;
        
        // Performance factor
        if ($this->performanceMetrics) {
            $winRate = $this->performanceMetrics['win_rate'] ?? 50;
            if ($winRate < 60) {
                $score -= (60 - $winRate) * 0.3;
            } elseif ($winRate > 80) {
                $score += min(10, ($winRate - 80) * 0.2);
            }
        }
        
        // Spacing factor
        if ($this->gridSpacing < 0.8 || $this->gridSpacing > 4) {
            $score -= 10;
        }
        
        return max(0, min(100, round($score)));
    }

    public function getRecommendations(): array
    {
        $recommendations = [];
        
        if ($this->riskScore > 70) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'ریسک بالا - کاهش درصد سرمایه فعال پیشنهاد می‌شود'
            ];
        }
        
        if ($this->gridSpacing < 1) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'فاصله کم - ممکن است کارمزد بالایی داشته باشید'
            ];
        }
        
        if ($this->totalLevels > 16) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'تعداد سطوح زیاد - مدیریت پیچیده‌تر خواهد بود'
            ];
        }
        
        if ($this->expectedDailyProfitPercent > 0 && $this->expectedDailyProfitPercent < 0.1) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'سود روزانه کم - بررسی مجدد تنظیمات پیشنهاد می‌شود'
            ];
        }
        
        return $recommendations;
    }

    // ========== Render Method ==========
    
    public function render()
    {
        $this->saveStatsToCache();
        
        return view('livewire.grid-stats-cards', [
            'hasCalculatedData' => $this->hasCalculatedData,
            'activeCapital' => $this->activeCapital,
            'totalLevels' => $this->totalLevels,
            'buyLevels' => $this->buyLevels,
            'sellLevels' => $this->sellLevels,
            'minPrice' => $this->minPrice,
            'maxPrice' => $this->maxPrice,
            'priceRangePercent' => $this->priceRangePercent,
            'riskScore' => $this->riskScore,
            'expectedDailyProfit' => $this->expectedDailyProfit,
            'expectedDailyProfitPercent' => $this->expectedDailyProfitPercent,
            'orderSizeIRT' => $this->orderSizeIRT,
            'orderSizeBTC' => $this->orderSizeBTC,
            'healthScore' => $this->getHealthScore(),
            'recommendations' => $this->getRecommendations()
        ]);
    }
}