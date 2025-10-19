<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\NobitexService;
use Carbon\Carbon;

class GridCalculatorChart extends Component
{
    // ========== Core Properties ==========
    public ?Collection $gridLevels = null;
    public float $currentPrice = 0;
    public float $centerPrice = 0;
    public ?array $calculationResults = null;
    public ?array $riskAnalysis = null;
    public ?array $marketTrend = null;
    
    // ========== Chart Configuration ==========
    public string $chartType = 'line';
    public bool $showGridLines = true;
    public bool $showVolumeIndicator = false;
    public bool $showRiskZones = true;
    public bool $showPriceHistory = false;
    public string $timeframe = '1h';
    public int $historyPoints = 24;
    
    // ========== UI State ==========
    public bool $isLoading = false;
    public bool $autoRefresh = true;
    public int $refreshInterval = 30; // seconds
    public string $chartTheme = 'auto';
    public bool $showTooltips = true;
    public bool $enableZoom = true;
    public bool $enablePan = false;
    
    // ========== Data Properties ==========
    public array $priceHistory = [];
    public array $chartData = [];
    public array $chartOptions = [];
    public string $lastUpdateTime = '';
    public int $dataPoints = 0;
    
    // ========== Services ==========
    private NobitexService $nobitexService;
    
    // ========== Event Listeners ==========
    protected $listeners = [
        'grid-calculated' => 'handleGridCalculated',
        'chart-config-changed' => 'updateChartConfig',
        'market-data-updated' => 'handleMarketUpdate',
        'price-history-updated' => 'updatePriceHistory'
    ];

    // ========== Lifecycle Methods ==========
    
    public function mount(array $options = []): void
    {
        $this->nobitexService = app(NobitexService::class);
        
        // Apply options
        $this->chartType = $options['type'] ?? 'line';
        $this->showGridLines = $options['gridLines'] ?? true;
        $this->showRiskZones = $options['riskZones'] ?? true;
        $this->autoRefresh = $options['autoRefresh'] ?? true;
        $this->chartTheme = $options['theme'] ?? 'auto';
        
        $this->initializeChart();
        $this->loadPriceHistory();
        
        Log::info('GridCalculatorChart component mounted', [
            'chart_type' => $this->chartType,
            'auto_refresh' => $this->autoRefresh
        ]);
    }

    public function hydrate(): void
    {
        $this->nobitexService = app(NobitexService::class);
    }

    // ========== Event Handlers ==========
    
    #[On('grid-calculated')]
    public function handleGridCalculated(array $data): void
    {
        try {
            $this->gridLevels = collect($data['gridLevels'] ?? []);
            $this->currentPrice = $data['currentPrice'] ?? 0;
            $this->centerPrice = $data['centerPrice'] ?? $this->currentPrice;
            $this->calculationResults = $data['results'] ?? null;
            
            $this->updateChartData();
            $this->lastUpdateTime = now()->format('H:i:s');
            $this->dataPoints = $this->gridLevels?->count() ?? 0;
            
            $this->dispatch('chart-data-updated', [
                'chartData' => $this->chartData,
                'options' => $this->chartOptions
            ]);
            
            Log::info('Chart updated from grid calculation', [
                'levels_count' => $this->dataPoints,
                'current_price' => $this->currentPrice
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error updating chart from grid calculation: ' . $e->getMessage());
            
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در بروزرسانی نمودار',
                'message' => $e->getMessage()
            ]);
        }
    }

    #[On('chart-config-changed')]
    public function updateChartConfig(array $config): void
    {
        $this->chartType = $config['type'] ?? $this->chartType;
        $this->showGridLines = $config['gridLines'] ?? $this->showGridLines;
        $this->showRiskZones = $config['riskZones'] ?? $this->showRiskZones;
        $this->showVolumeIndicator = $config['volume'] ?? $this->showVolumeIndicator;
        
        $this->updateChartData();
        $this->dispatch('chart-updated');
    }

    #[On('market-data-updated')]
    public function handleMarketUpdate(array $marketData): void
    {
        $this->currentPrice = $marketData['current_price'] ?? $this->currentPrice;
        $this->marketTrend = $marketData;
        
        if ($this->autoRefresh) {
            $this->updateCurrentPriceLine();
        }
    }

    // ========== Computed Properties ==========
    
    #[Computed]
    public function hasChartData(): bool
    {
        return $this->gridLevels !== null && 
               $this->gridLevels->isNotEmpty() && 
               !empty($this->chartData);
    }

    #[Computed]
    public function buyLevelsCount(): int
    {
        return $this->gridLevels?->where('type', 'buy')->count() ?? 0;
    }

    #[Computed]
    public function sellLevelsCount(): int
    {
        return $this->gridLevels?->where('type', 'sell')->count() ?? 0;
    }

    #[Computed]
    public function priceRange(): array
    {
        if (!$this->gridLevels || $this->gridLevels->isEmpty()) {
            return ['min' => 0, 'max' => 0, 'spread' => 0];
        }

        $prices = $this->gridLevels->pluck('price');
        $min = $prices->min();
        $max = $prices->max();
        
        return [
            'min' => $min,
            'max' => $max,
            'spread' => $max - $min,
            'spread_percent' => $this->currentPrice > 0 ? (($max - $min) / $this->currentPrice) * 100 : 0
        ];
    }

    // ========== Chart Action Methods ==========
    
    public function toggleChartType(): void
    {
        $this->chartType = $this->chartType === 'line' ? 'bar' : 'line';
        $this->updateChartData();
        $this->dispatch('chart-updated');
        
        $this->dispatch('notification', [
            'type' => 'info',
            'title' => '📊 نوع نمودار تغییر کرد',
            'message' => $this->chartType === 'line' ? 'نمودار خطی' : 'نمودار میله‌ای'
        ]);
    }

    public function toggleGridLines(): void
    {
        $this->showGridLines = !$this->showGridLines;
        $this->updateChartOptions();
        $this->dispatch('chart-updated');
    }

    public function toggleRiskZones(): void
    {
        $this->showRiskZones = !$this->showRiskZones;
        $this->updateChartData();
        $this->dispatch('chart-updated');
    }

    public function toggleVolumeIndicator(): void
    {
        $this->showVolumeIndicator = !$this->showVolumeIndicator;
        $this->updateChartData();
        $this->dispatch('chart-updated');
    }

    public function togglePriceHistory(): void
    {
        $this->showPriceHistory = !$this->showPriceHistory;
        
        if ($this->showPriceHistory && empty($this->priceHistory)) {
            $this->loadPriceHistory();
        }
        
        $this->updateChartData();
        $this->dispatch('chart-updated');
    }

    public function exportChart(): void
    {
        try {
            $exportData = [
                'chart_info' => [
                    'type' => $this->chartType,
                    'generated_at' => now()->toISOString(),
                    'data_points' => $this->dataPoints,
                    'price_range' => $this->priceRange
                ],
                'market_data' => [
                    'current_price' => $this->currentPrice,
                    'center_price' => $this->centerPrice,
                    'market_trend' => $this->marketTrend
                ],
                'grid_levels' => $this->gridLevels?->toArray() ?? [],
                'chart_data' => $this->chartData,
                'chart_options' => $this->chartOptions,
                'price_history' => $this->priceHistory
            ];
            
            $exportKey = 'chart_export_' . now()->format('Y_m_d_H_i_s');
            Cache::put($exportKey, $exportData, 3600);
            
            // Trigger JavaScript export
            $this->dispatch('export-chart', [
                'filename' => 'grid-chart-' . now()->format('Y-m-d-H-i-s') . '.png'
            ]);
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '📥 نمودار صادر شد',
                'message' => 'فایل تصویر نمودار دانلود شد'
            ]);
            
        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در صادرات',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function refreshChart(): void
    {
        $this->isLoading = true;
        
        try {
            $this->loadCurrentPrice();
            $this->updateChartData();
            $this->lastUpdateTime = now()->format('H:i:s');
            
            $this->dispatch('chart-updated');
            
            $this->dispatch('notification', [
                'type' => 'success',
                'title' => '🔄 نمودار بروزرسانی شد',
                'message' => 'آخرین بروزرسانی: ' . $this->lastUpdateTime
            ]);
            
        } catch (\Exception $e) {
            $this->dispatch('notification', [
                'type' => 'error',
                'title' => 'خطا در بروزرسانی',
                'message' => $e->getMessage()
            ]);
        } finally {
            $this->isLoading = false;
        }
    }

    public function changeTimeframe(string $timeframe): void
    {
        $this->timeframe = $timeframe;
        $this->historyPoints = match($timeframe) {
            '5m' => 288, // 24 hours
            '15m' => 96,
            '1h' => 24,
            '4h' => 42, // 1 week
            '1d' => 30,
            default => 24
        };
        
        $this->loadPriceHistory();
        $this->updateChartData();
        $this->dispatch('chart-updated');
    }

    // ========== Chart Data Management ==========
    
    private function initializeChart(): void
    {
        $this->chartData = [
            'labels' => [],
            'datasets' => []
        ];
        
        $this->chartOptions = $this->getDefaultChartOptions();
    }

    private function updateChartData(): void
    {
        if (!$this->gridLevels || $this->gridLevels->isEmpty()) {
            $this->initializeChart();
            return;
        }

        $allLevels = $this->gridLevels->sortBy('price')->values();
        $labels = $allLevels->map(fn($level, $index) => 'سطح ' . ($index + 1))->toArray();
        
        $datasets = [];
        
        // Buy levels dataset
        $buyLevels = $allLevels->where('type', 'buy');
        if ($buyLevels->isNotEmpty()) {
            $datasets[] = [
                'label' => 'سطوح خرید (' . $buyLevels->count() . ')',
                'data' => $allLevels->map(fn($level) => $level['type'] === 'buy' ? $level['price'] : null)->toArray(),
                'borderColor' => 'rgb(34, 197, 94)',
                'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                'pointBackgroundColor' => 'rgb(34, 197, 94)',
                'pointBorderColor' => 'rgb(255, 255, 255)',
                'pointBorderWidth' => 2,
                'pointRadius' => 6,
                'pointHoverRadius' => 8,
                'spanGaps' => false,
                'tension' => 0.1,
                'borderWidth' => 3,
                'metadata' => $this->generateMetadata($allLevels)
            ];
        }

        // Sell levels dataset
        $sellLevels = $allLevels->where('type', 'sell');
        if ($sellLevels->isNotEmpty()) {
            $datasets[] = [
                'label' => 'سطوح فروش (' . $sellLevels->count() . ')',
                'data' => $allLevels->map(fn($level) => $level['type'] === 'sell' ? $level['price'] : null)->toArray(),
                'borderColor' => 'rgb(239, 68, 68)',
                'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                'pointBackgroundColor' => 'rgb(239, 68, 68)',
                'pointBorderColor' => 'rgb(255, 255, 255)',
                'pointBorderWidth' => 2,
                'pointRadius' => 6,
                'pointHoverRadius' => 8,
                'spanGaps' => false,
                'tension' => 0.1,
                'borderWidth' => 3,
                'metadata' => $this->generateMetadata($allLevels)
            ];
        }

        // Current price line
        if ($this->currentPrice > 0) {
            $datasets[] = [
                'label' => 'قیمت فعلی',
                'data' => array_fill(0, count($labels), $this->currentPrice),
                'borderColor' => 'rgb(234, 179, 8)',
                'backgroundColor' => 'rgba(234, 179, 8, 0.05)',
                'borderDash' => [5, 5],
                'pointRadius' => 0,
                'pointHoverRadius' => 0,
                'borderWidth' => 2,
                'tension' => 0
            ];
        }

        // Center price line
        if ($this->centerPrice > 0 && $this->centerPrice !== $this->currentPrice) {
            $datasets[] = [
                'label' => 'قیمت مرکزی',
                'data' => array_fill(0, count($labels), $this->centerPrice),
                'borderColor' => 'rgb(168, 85, 247)',
                'backgroundColor' => 'rgba(168, 85, 247, 0.05)',
                'borderDash' => [10, 5],
                'pointRadius' => 0,
                'pointHoverRadius' => 0,
                'borderWidth' => 2,
                'tension' => 0
            ];
        }

        // Risk zones
        if ($this->showRiskZones && $this->riskAnalysis) {
            $this->addRiskZones($datasets, $labels);
        }

        // Price history
        if ($this->showPriceHistory && !empty($this->priceHistory)) {
            $this->addPriceHistory($datasets, $labels);
        }

        $this->chartData = [
            'labels' => $labels,
            'datasets' => $datasets
        ];

        $this->updateChartOptions();
    }

    private function addRiskZones(array &$datasets, array $labels): void
    {
        // High risk zone (above center + threshold)
        if (isset($this->riskAnalysis['price_range_analysis'])) {
            $analysis = $this->riskAnalysis['price_range_analysis'];
            
            if (isset($analysis['resistance_level'])) {
                $datasets[] = [
                    'label' => 'منطقه ریسک بالا',
                    'data' => array_fill(0, count($labels), $analysis['resistance_level']),
                    'borderColor' => 'rgba(239, 68, 68, 0.3)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'borderDash' => [3, 3],
                    'pointRadius' => 0,
                    'borderWidth' => 1,
                    'fill' => '+1'
                ];
            }
            
            if (isset($analysis['support_level'])) {
                $datasets[] = [
                    'label' => 'منطقه ریسک پایین',
                    'data' => array_fill(0, count($labels), $analysis['support_level']),
                    'borderColor' => 'rgba(34, 197, 94, 0.3)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'borderDash' => [3, 3],
                    'pointRadius' => 0,
                    'borderWidth' => 1,
                    'fill' => '-1'
                ];
            }
        }
    }

    private function addPriceHistory(array &$datasets, array $labels): void
    {
        if (empty($this->priceHistory)) return;
        
        // Create historical price line
        $historyPrices = array_column($this->priceHistory, 'price');
        $avgPrice = array_sum($historyPrices) / count($historyPrices);
        
        $datasets[] = [
            'label' => "میانگین قیمت {$this->timeframe}",
            'data' => array_fill(0, count($labels), $avgPrice),
            'borderColor' => 'rgba(99, 102, 241, 0.7)',
            'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
            'borderDash' => [8, 4],
            'pointRadius' => 0,
            'borderWidth' => 1.5,
            'tension' => 0
        ];
    }

    private function generateMetadata(Collection $levels): array
    {
        return $levels->map(function ($level) {
            $distancePercent = $this->currentPrice > 0 ? 
                round((($level['price'] - $this->currentPrice) / $this->currentPrice) * 100, 2) : 0;
            
            return [
                'distancePercent' => $distancePercent,
                'orderSize' => number_format($level['amount'] ?? 0, 8),
                'probability' => round(($level['execution_probability'] ?? 0.5) * 100),
                'value' => number_format(($level['amount'] ?? 0) * $level['price'], 0),
                'priority' => $level['priority'] ?? 5
            ];
        })->toArray();
    }

    private function updateChartOptions(): void
    {
        $isDark = $this->chartTheme === 'dark' || 
                 ($this->chartTheme === 'auto' && request()->cookie('theme') === 'dark');
        
        $gridColor = $isDark ? 'rgba(75, 85, 99, 0.3)' : 'rgba(209, 213, 219, 0.3)';
        $textColor = $isDark ? '#9ca3af' : '#6b7280';
        $backgroundColor = $isDark ? 'rgba(31, 41, 55, 0.95)' : 'rgba(255, 255, 255, 0.95)';
        
        $this->chartOptions = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'labels' => [
                        'usePointStyle' => true,
                        'padding' => 20,
                        'color' => $textColor,
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 12
                        ]
                    ]
                ],
                'tooltip' => [
                    'enabled' => $this->showTooltips,
                    'mode' => 'index',
                    'intersect' => false,
                    'backgroundColor' => $backgroundColor,
                    'titleColor' => $textColor,
                    'bodyColor' => $textColor,
                    'borderColor' => $gridColor,
                    'borderWidth' => 1,
                    'cornerRadius' => 8,
                    'displayColors' => true,
                    'padding' => 12,
                    'callbacks' => $this->getTooltipCallbacks()
                ]
            ],
            'scales' => [
                'x' => [
                    'display' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'سطوح گرید',
                        'color' => $textColor,
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 14,
                            'weight' => 'bold'
                        ]
                    ],
                    'grid' => [
                        'display' => $this->showGridLines,
                        'color' => $gridColor
                    ],
                    'ticks' => [
                        'color' => $textColor,
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif'
                        ]
                    ]
                ],
                'y' => [
                    'display' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'قیمت (ریال)',
                        'color' => $textColor,
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 14,
                            'weight' => 'bold'
                        ]
                    ],
                    'grid' => [
                        'display' => $this->showGridLines,
                        'color' => $gridColor
                    ],
                    'ticks' => [
                        'color' => $textColor,
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif'
                        ]
                    ]
                ]
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false
            ],
            'elements' => [
                'point' => [
                    'radius' => 6,
                    'hoverRadius' => 8,
                    'borderWidth' => 2,
                    'hoverBorderWidth' => 3
                ],
                'line' => [
                    'borderWidth' => 3,
                    'tension' => 0.1
                ]
            ]
        ];

        // Add zoom and pan if enabled
        if ($this->enableZoom || $this->enablePan) {
            $this->chartOptions['plugins']['zoom'] = [
                'zoom' => [
                    'wheel' => [
                        'enabled' => $this->enableZoom
                    ],
                    'pinch' => [
                        'enabled' => $this->enableZoom
                    ],
                    'mode' => 'xy'
                ],
                'pan' => [
                    'enabled' => $this->enablePan,
                    'mode' => 'xy'
                ]
            ];
        }
    }

    private function getDefaultChartOptions(): array
    {
        $this->updateChartOptions();
        return $this->chartOptions;
    }

    private function getTooltipCallbacks(): array
    {
        return [
            'title' => 'function(context) { return "سطح " + (context[0].dataIndex + 1); }',
            'label' => 'function(context) {
                const value = context.parsed.y;
                const type = context.dataset.label;
                return type + ": " + new Intl.NumberFormat("fa-IR").format(value) + " ریال";
            }',
            'afterBody' => 'function(context) {
                if (context[0]?.dataset?.metadata) {
                    const meta = context[0].dataset.metadata[context[0].dataIndex];
                    if (meta) {
                        return [
                            "فاصله از مرکز: " + meta.distancePercent + "%",
                            "اندازه سفارش: " + meta.orderSize + " BTC",
                            "احتمال اجرا: " + meta.probability + "%",
                            "ارزش: " + new Intl.NumberFormat("fa-IR").format(meta.value) + " ریال"
                        ];
                    }
                }
                return [];
            }'
        ];
    }

    // ========== Price Data Management ==========
    
    private function loadCurrentPrice(): void
    {
        try {
            $this->currentPrice = $this->nobitexService->getCurrentPrice('BTCIRT');
        } catch (\Exception $e) {
            Log::warning('Failed to load current price: ' . $e->getMessage());
            $this->currentPrice = Cache::get('btc_current_price', $this->currentPrice);
        }
    }

    private function loadPriceHistory(): void
    {
        try {
            // In a real implementation, this would fetch actual historical data
            // For now, we'll generate mock data
            $this->priceHistory = $this->generateMockPriceHistory();
            
        } catch (\Exception $e) {
            Log::warning('Failed to load price history: ' . $e->getMessage());
            $this->priceHistory = [];
        }
    }

    private function generateMockPriceHistory(): array
    {
        $history = [];
        $basePrice = $this->currentPrice ?: 6000000000;
        $volatility = 0.02; // 2% volatility
        
        for ($i = $this->historyPoints; $i >= 0; $i--) {
            $timestamp = now()->subMinutes($i * $this->getTimeframeMinutes());
            $change = (mt_rand(-100, 100) / 100) * $volatility;
            $price = $basePrice * (1 + $change);
            
            $history[] = [
                'timestamp' => $timestamp->toISOString(),
                'price' => $price,
                'volume' => mt_rand(1000000, 10000000)
            ];
            
            $basePrice = $price; // Use previous price as base for next
        }
        
        return $history;
    }

    private function getTimeframeMinutes(): int
    {
        return match($this->timeframe) {
            '5m' => 5,
            '15m' => 15,
            '1h' => 60,
            '4h' => 240,
            '1d' => 1440,
            default => 60
        ];
    }

    private function updateCurrentPriceLine(): void
    {
        if (empty($this->chartData['datasets'])) return;
        
        // Find and update current price line
        foreach ($this->chartData['datasets'] as &$dataset) {
            if ($dataset['label'] === 'قیمت فعلی') {
                $dataset['data'] = array_fill(0, count($this->chartData['labels']), $this->currentPrice);
                break;
            }
        }
        
        $this->dispatch('chart-price-updated', [
            'currentPrice' => $this->currentPrice,
            'timestamp' => now()->toISOString()
        ]);
    }

    // ========== Helper Methods ==========
    
    public function getLevelDensity(): string
    {
        if (!$this->gridLevels || $this->gridLevels->isEmpty()) return 'نامحاسبه';
        
        $range = $this->priceRange;
        if ($range['spread_percent'] <= 0) return 'نامحاسبه';
        
        $density = $this->gridLevels->count() / $range['spread_percent'];
        
        if ($density > 2) return 'متراکم';
        if ($density > 1) return 'متوسط';
        return 'پراکنده';
    }

    public function getChartHealthScore(): int
    {
        $score = 100;
        
        // Data quality
        if ($this->dataPoints < 4) $score -= 30;
        if ($this->currentPrice <= 0) $score -= 20;
        
        // Price range coverage
        $range = $this->priceRange;
        if ($range['spread_percent'] < 5) $score -= 15;
        if ($range['spread_percent'] > 50) $score -= 10;
        
        // Update frequency
        if (!$this->autoRefresh) $score -= 5;
        
        return max(0, min(100, $score));
    }

    // ========== Render Method ==========
    
    public function render()
    {
        return view('livewire.grid-calculator-chart', [
            'hasChartData' => $this->hasChartData,
            'buyLevelsCount' => $this->buyLevelsCount,
            'sellLevelsCount' => $this->sellLevelsCount,
            'priceRange' => $this->priceRange,
            'dataPoints' => $this->dataPoints,
            'levelDensity' => $this->getLevelDensity(),
            'chartHealthScore' => $this->getChartHealthScore()
        ]);
    }
}