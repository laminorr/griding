<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class GridLevelsChart extends Component
{
    // Properties
    public Collection $gridLevels;
    public float $centerPrice = 0;
    public float $currentPrice = 0;
    public string $timeframe = '1H';
    public bool $showOrderSize = true;
    public bool $showProfitZones = true;
    public bool $showSupport = true;
    public string $chartType = 'candlestick';
    public array $chartData = [];
    public array $chartOptions = [];
    
    // UI States
    public bool $isLoading = false;
    public string $lastUpdated = '';
    
    // Chart configuration
    protected array $defaultColors = [
        'buy' => 'rgba(34, 197, 94, 0.8)',      // Green
        'sell' => 'rgba(239, 68, 68, 0.8)',     // Red
        'center' => 'rgba(59, 130, 246, 0.8)',  // Blue
        'current' => 'rgba(245, 158, 11, 1)',   // Amber
        'support' => 'rgba(168, 85, 247, 0.4)', // Purple
        'resistance' => 'rgba(236, 72, 153, 0.4)' // Pink
    ];

    /**
     * Component initialization
     */
    public function mount(
        Collection $gridLevels = null,
        float $centerPrice = 0,
        float $currentPrice = 0,
        array $options = []
    ): void {
        $this->gridLevels = $gridLevels ?? collect();
        $this->centerPrice = $centerPrice;
        $this->currentPrice = $currentPrice ?: $centerPrice;
        
        // Apply options
        $this->showOrderSize = $options['showOrderSize'] ?? true;
        $this->showProfitZones = $options['showProfitZones'] ?? true;
        $this->showSupport = $options['showSupport'] ?? true;
        $this->chartType = $options['chartType'] ?? 'candlestick';
        
        $this->lastUpdated = now()->format('H:i:s');
        
        // Generate chart data
        $this->generateChartData();
    }

    /**
     * Generate comprehensive chart data for Chart.js
     */
    public function generateChartData(): void
    {
        try {
            $this->isLoading = true;
            
            // Base datasets
            $datasets = [];
            
            // 1. Price history (mock data for demonstration)
            if ($this->chartType === 'candlestick') {
                $datasets[] = $this->generateCandlestickData();
            } else {
                $datasets[] = $this->generateLineData();
            }
            
            // 2. Grid levels
            $datasets = array_merge($datasets, $this->generateGridLevelDatasets());
            
            // 3. Current price line
            $datasets[] = $this->generateCurrentPriceLine();
            
            // 4. Support/Resistance zones
            if ($this->showSupport) {
                $datasets = array_merge($datasets, $this->generateSupportResistanceZones());
            }
            
            // 5. Profit zones
            if ($this->showProfitZones) {
                $datasets = array_merge($datasets, $this->generateProfitZones());
            }
            
            // Labels (time points)
            $labels = $this->generateTimeLabels();
            
            $this->chartData = [
                'labels' => $labels,
                'datasets' => $datasets
            ];
            
            $this->chartOptions = $this->generateChartOptions();
            $this->lastUpdated = now()->format('H:i:s');
            
        } catch (\Exception $e) {
            logger()->error('Error generating chart data: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Generate candlestick data (OHLC)
     */
    private function generateCandlestickData(): array
    {
        $candles = [];
        $basePrice = $this->currentPrice ?: $this->centerPrice;
        
        // Generate 24 hours of mock data
        for ($i = 23; $i >= 0; $i--) {
            $timestamp = now()->subHours($i);
            
            // Simulate realistic price movement
            $volatility = 0.02; // 2% volatility
            $open = $basePrice * (1 + (rand(-100, 100) / 10000 * $volatility));
            $close = $open * (1 + (rand(-100, 100) / 10000 * $volatility));
            $high = max($open, $close) * (1 + (rand(0, 50) / 10000 * $volatility));
            $low = min($open, $close) * (1 - (rand(0, 50) / 10000 * $volatility));
            
            $candles[] = [
                'x' => $timestamp->getTimestamp() * 1000, // JavaScript timestamp
                'o' => round($open, 0),  // Open
                'h' => round($high, 0),  // High
                'l' => round($low, 0),   // Low
                'c' => round($close, 0)  // Close
            ];
            
            $basePrice = $close; // Next candle starts where previous ended
        }
        
        return [
            'label' => 'قیمت BTC',
            'type' => 'candlestick',
            'data' => $candles,
            'borderColor' => $this->defaultColors['current'],
            'backgroundColor' => $this->defaultColors['current'],
            'borderWidth' => 1,
            'order' => 1
        ];
    }

    /**
     * Generate line chart data
     */
    private function generateLineData(): array
    {
        $points = [];
        $basePrice = $this->currentPrice ?: $this->centerPrice;
        
        for ($i = 47; $i >= 0; $i--) { // Last 48 half-hours
            $timestamp = now()->subMinutes($i * 30);
            $price = $basePrice * (1 + (rand(-100, 100) / 10000 * 0.015)); // 1.5% volatility
            
            $points[] = [
                'x' => $timestamp->getTimestamp() * 1000,
                'y' => round($price, 0)
            ];
            
            $basePrice = $price;
        }
        
        return [
            'label' => 'قیمت BTC',
            'type' => 'line',
            'data' => $points,
            'borderColor' => $this->defaultColors['current'],
            'backgroundColor' => 'transparent',
            'borderWidth' => 2,
            'tension' => 0.3,
            'pointRadius' => 0,
            'pointHoverRadius' => 5,
            'order' => 1
        ];
    }

    /**
     * Generate grid level datasets
     */
    private function generateGridLevelDatasets(): array
    {
        if ($this->gridLevels->isEmpty()) {
            return [];
        }
        
        $datasets = [];
        $startTime = now()->subHours(24)->getTimestamp() * 1000;
        $endTime = now()->getTimestamp() * 1000;
        
        foreach ($this->gridLevels as $index => $level) {
            $price = $level['price'];
            $type = $level['type'];
            $amount = $level['amount'] ?? 0;
            
            // Base horizontal line
            $lineDataset = [
                'label' => sprintf('%s سطح %d - %s ریال', 
                    $type === 'buy' ? '🟢 خرید' : '🔴 فروش',
                    $level['level'] ?? ($index + 1),
                    number_format($price, 0)
                ),
                'type' => 'line',
                'data' => [
                    ['x' => $startTime, 'y' => $price],
                    ['x' => $endTime, 'y' => $price]
                ],
                'borderColor' => $this->defaultColors[$type],
                'backgroundColor' => 'transparent',
                'borderWidth' => $type === 'buy' ? 2 : 2,
                'borderDash' => $type === 'sell' ? [5, 5] : [],
                'pointRadius' => 0,
                'order' => 10,
                'yAxisID' => 'y'
            ];
            
            $datasets[] = $lineDataset;
            
            // Order size visualization
            if ($this->showOrderSize && $amount > 0) {
                $orderSizeDataset = [
                    'label' => sprintf('حجم: %s BTC', number_format($amount, 6)),
                    'type' => 'scatter',
                    'data' => [
                        [
                            'x' => $endTime - (($endTime - $startTime) * 0.1), // 90% to the right
                            'y' => $price
                        ]
                    ],
                    'backgroundColor' => $this->defaultColors[$type],
                    'borderColor' => $this->defaultColors[$type],
                    'pointRadius' => $this->calculateOrderSizeRadius($amount),
                    'pointHoverRadius' => $this->calculateOrderSizeRadius($amount) + 2,
                    'order' => 5,
                    'showLine' => false
                ];
                
                $datasets[] = $orderSizeDataset;
            }
        }
        
        return $datasets;
    }

    /**
     * Generate current price line
     */
    private function generateCurrentPriceLine(): array
    {
        if (!$this->currentPrice) {
            return [];
        }
        
        $startTime = now()->subHours(24)->getTimestamp() * 1000;
        $endTime = now()->getTimestamp() * 1000;
        
        return [
            'label' => sprintf('قیمت فعلی: %s ریال', number_format($this->currentPrice, 0)),
            'type' => 'line',
            'data' => [
                ['x' => $startTime, 'y' => $this->currentPrice],
                ['x' => $endTime, 'y' => $this->currentPrice]
            ],
            'borderColor' => $this->defaultColors['current'],
            'backgroundColor' => 'transparent',
            'borderWidth' => 3,
            'borderDash' => [10, 5],
            'pointRadius' => 0,
            'order' => 2
        ];
    }

    /**
     * Generate support and resistance zones
     */
    private function generateSupportResistanceZones(): array
    {
        if ($this->gridLevels->isEmpty()) {
            return [];
        }
        
        $datasets = [];
        $buyLevels = $this->gridLevels->where('type', 'buy')->pluck('price')->sort();
        $sellLevels = $this->gridLevels->where('type', 'sell')->pluck('price')->sort();
        
        $startTime = now()->subHours(24)->getTimestamp() * 1000;
        $endTime = now()->getTimestamp() * 1000;
        
        // Support zone (below current price)
        if ($buyLevels->isNotEmpty()) {
            $supportTop = $buyLevels->max();
            $supportBottom = $buyLevels->min();
            
            $datasets[] = [
                'label' => sprintf('منطقه حمایت: %s - %s', 
                    number_format($supportBottom, 0), 
                    number_format($supportTop, 0)
                ),
                'type' => 'line',
                'data' => [
                    ['x' => $startTime, 'y' => $supportTop],
                    ['x' => $endTime, 'y' => $supportTop],
                    ['x' => $endTime, 'y' => $supportBottom],
                    ['x' => $startTime, 'y' => $supportBottom],
                    ['x' => $startTime, 'y' => $supportTop]
                ],
                'borderColor' => $this->defaultColors['support'],
                'backgroundColor' => $this->defaultColors['support'],
                'fill' => true,
                'borderWidth' => 1,
                'pointRadius' => 0,
                'order' => 20
            ];
        }
        
        // Resistance zone (above current price)
        if ($sellLevels->isNotEmpty()) {
            $resistanceTop = $sellLevels->max();
            $resistanceBottom = $sellLevels->min();
            
            $datasets[] = [
                'label' => sprintf('منطقه مقاومت: %s - %s', 
                    number_format($resistanceBottom, 0), 
                    number_format($resistanceTop, 0)
                ),
                'type' => 'line',
                'data' => [
                    ['x' => $startTime, 'y' => $resistanceTop],
                    ['x' => $endTime, 'y' => $resistanceTop],
                    ['x' => $endTime, 'y' => $resistanceBottom],
                    ['x' => $startTime, 'y' => $resistanceBottom],
                    ['x' => $startTime, 'y' => $resistanceTop]
                ],
                'borderColor' => $this->defaultColors['resistance'],
                'backgroundColor' => $this->defaultColors['resistance'],
                'fill' => true,
                'borderWidth' => 1,
                'pointRadius' => 0,
                'order' => 20
            ];
        }
        
        return $datasets;
    }

    /**
     * Generate profit zones
     */
    private function generateProfitZones(): array
    {
        if ($this->gridLevels->isEmpty() || !$this->centerPrice) {
            return [];
        }
        
        $datasets = [];
        $startTime = now()->subHours(24)->getTimestamp() * 1000;
        $endTime = now()->getTimestamp() * 1000;
        
        // Calculate profit zones based on grid spacing
        $priceRange = $this->gridLevels->max('price') - $this->gridLevels->min('price');
        $zoneHeight = $priceRange * 0.1; // 10% of total range for each zone
        
        $profitZones = [
            [
                'name' => 'سود بالا',
                'top' => $this->centerPrice + ($zoneHeight * 2),
                'bottom' => $this->centerPrice + $zoneHeight,
                'color' => 'rgba(34, 197, 94, 0.1)' // Light green
            ],
            [
                'name' => 'سود متوسط',
                'top' => $this->centerPrice + $zoneHeight,
                'bottom' => $this->centerPrice,
                'color' => 'rgba(34, 197, 94, 0.05)' // Very light green
            ],
            [
                'name' => 'سود متوسط',
                'top' => $this->centerPrice,
                'bottom' => $this->centerPrice - $zoneHeight,
                'color' => 'rgba(34, 197, 94, 0.05)' // Very light green
            ],
            [
                'name' => 'سود بالا',
                'top' => $this->centerPrice - $zoneHeight,
                'bottom' => $this->centerPrice - ($zoneHeight * 2),
                'color' => 'rgba(34, 197, 94, 0.1)' // Light green
            ]
        ];
        
        foreach ($profitZones as $zone) {
            $datasets[] = [
                'label' => $zone['name'],
                'type' => 'line',
                'data' => [
                    ['x' => $startTime, 'y' => $zone['top']],
                    ['x' => $endTime, 'y' => $zone['top']],
                    ['x' => $endTime, 'y' => $zone['bottom']],
                    ['x' => $startTime, 'y' => $zone['bottom']],
                    ['x' => $startTime, 'y' => $zone['top']]
                ],
                'borderColor' => 'transparent',
                'backgroundColor' => $zone['color'],
                'fill' => true,
                'pointRadius' => 0,
                'order' => 30
            ];
        }
        
        return $datasets;
    }

    /**
     * Generate time labels
     */
    private function generateTimeLabels(): array
    {
        $labels = [];
        $intervals = 24; // 24 hours
        
        for ($i = $intervals - 1; $i >= 0; $i--) {
            $time = now()->subHours($i);
            $labels[] = $time->format('H:i');
        }
        
        return $labels;
    }

    /**
     * Calculate order size radius for scatter points
     */
    private function calculateOrderSizeRadius(float $amount): int
    {
        // Normalize amount to radius (3-15 pixels)
        $minRadius = 3;
        $maxRadius = 15;
        $maxAmount = 0.1; // Maximum expected amount
        
        $normalizedAmount = min($amount / $maxAmount, 1);
        
        return (int) ($minRadius + ($maxRadius - $minRadius) * $normalizedAmount);
    }

    /**
     * Generate Chart.js options
     */
    private function generateChartOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => [
                'mode' => 'index',
                'intersect' => false
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'labels' => [
                        'usePointStyle' => true,
                        'padding' => 15,
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 12
                        ],
                        'filter' => 'function(legendItem) {
                            return !legendItem.text.includes("حجم:") && 
                                   !legendItem.text.includes("منطقه") && 
                                   !legendItem.text.includes("سود");
                        }'
                    ]
                ],
                'tooltip' => [
                    'enabled' => true,
                    'mode' => 'nearest',
                    'backgroundColor' => 'rgba(0, 0, 0, 0.8)',
                    'titleColor' => '#ffffff',
                    'bodyColor' => '#ffffff',
                    'borderColor' => 'rgba(255, 255, 255, 0.1)',
                    'borderWidth' => 1,
                    'cornerRadius' => 8,
                    'displayColors' => true,
                    'titleFont' => [
                        'family' => 'Vazirmatn, sans-serif',
                        'size' => 13,
                        'weight' => 'bold'
                    ],
                    'bodyFont' => [
                        'family' => 'Vazirmatn, sans-serif',
                        'size' => 12
                    ],
                    'callbacks' => [
                        'title' => 'function(context) {
                            if (context[0] && context[0].label) {
                                return "زمان: " + context[0].label;
                            }
                            return "";
                        }',
                        'label' => 'function(context) {
                            let label = context.dataset.label || "";
                            if (label) {
                                label += ": ";
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat("fa-IR").format(context.parsed.y) + " ریال";
                            }
                            return label;
                        }'
                    ]
                ],
                'zoom' => [
                    'limits' => [
                        'y' => ['min' => 0, 'max' => 'original']
                    ],
                    'pan' => [
                        'enabled' => true,
                        'mode' => 'xy'
                    ],
                    'zoom' => [
                        'wheel' => ['enabled' => true],
                        'pinch' => ['enabled' => true],
                        'mode' => 'xy'
                    ]
                ]
            ],
            'scales' => [
                'x' => [
                    'type' => 'time',
                    'time' => [
                        'unit' => 'hour',
                        'displayFormats' => [
                            'hour' => 'HH:mm'
                        ]
                    ],
                    'title' => [
                        'display' => true,
                        'text' => 'زمان',
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 12,
                            'weight' => 'bold'
                        ]
                    ],
                    'grid' => [
                        'color' => 'rgba(0, 0, 0, 0.1)',
                        'lineWidth' => 1
                    ],
                    'ticks' => [
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 11
                        ]
                    ]
                ],
                'y' => [
                    'type' => 'linear',
                    'position' => 'left',
                    'title' => [
                        'display' => true,
                        'text' => 'قیمت (ریال)',
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 12,
                            'weight' => 'bold'
                        ]
                    ],
                    'grid' => [
                        'color' => 'rgba(0, 0, 0, 0.1)',
                        'lineWidth' => 1
                    ],
                    'ticks' => [
                        'font' => [
                            'family' => 'Vazirmatn, sans-serif',
                            'size' => 11
                        ],
                        'callback' => 'function(value) {
                            return new Intl.NumberFormat("fa-IR", {
                                notation: "compact",
                                compactDisplay: "short"
                            }).format(value);
                        }'
                    ]
                ]
            ],
            'animation' => [
                'duration' => 1000,
                'easing' => 'easeInOutQuart'
            ]
        ];
    }

    /**
     * Update chart with new data
     */
    public function refreshChart(): void
    {
        $this->isLoading = true;
        $this->generateChartData();
        $this->dispatch('chart-updated', $this->chartData, $this->chartOptions);
    }

    /**
     * Change chart type
     */
    public function setChartType(string $type): void
    {
        $this->chartType = $type;
        $this->generateChartData();
        $this->dispatch('chart-type-changed', $type);
    }

    /**
     * Toggle chart features
     */
    public function toggleFeature(string $feature): void
    {
        switch ($feature) {
            case 'orderSize':
                $this->showOrderSize = !$this->showOrderSize;
                break;
            case 'profitZones':
                $this->showProfitZones = !$this->showProfitZones;
                break;
            case 'support':
                $this->showSupport = !$this->showSupport;
                break;
        }
        
        $this->generateChartData();
        $this->dispatch('chart-feature-toggled', $feature);
    }

    /**
     * Get chart statistics
     */
    public function getChartStatistics(): array
    {
        if ($this->gridLevels->isEmpty()) {
            return [];
        }
        
        $buyLevels = $this->gridLevels->where('type', 'buy');
        $sellLevels = $this->gridLevels->where('type', 'sell');
        
        return [
            'total_levels' => $this->gridLevels->count(),
            'buy_levels' => $buyLevels->count(),
            'sell_levels' => $sellLevels->count(),
            'price_range' => [
                'min' => $this->gridLevels->min('price'),
                'max' => $this->gridLevels->max('price'),
                'spread' => $this->gridLevels->max('price') - $this->gridLevels->min('price')
            ],
            'center_price' => $this->centerPrice,
            'current_price' => $this->currentPrice,
            'last_updated' => $this->lastUpdated
        ];
    }

    /**
     * Export chart data
     */
    public function exportChartData(): array
    {
        return [
            'chart_data' => $this->chartData,
            'chart_options' => $this->chartOptions,
            'statistics' => $this->getChartStatistics(),
            'configuration' => [
                'chart_type' => $this->chartType,
                'show_order_size' => $this->showOrderSize,
                'show_profit_zones' => $this->showProfitZones,
                'show_support' => $this->showSupport
            ],
            'exported_at' => now()->toISOString()
        ];
    }

    /**
     * Render the component
     */
    public function render()
    {
        return view('livewire.grid-levels-chart', [
            'statistics' => $this->getChartStatistics()
        ]);
    }
}