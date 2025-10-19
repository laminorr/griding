<?php

namespace App\Filament\Widgets;

use App\Models\CompletedTrade;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class PerformanceChartWidget extends ChartWidget
{
    protected static ?string $heading = 'عملکرد 30 روز گذشته';
    
    protected static ?int $sort = 2;
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $days = 30;
        $data = [];
        $labels = [];
        $cumulative = 0;
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayProfit = CompletedTrade::whereDate('created_at', $date)
                ->sum('profit');
            
            $cumulative += $dayProfit;
            $data[] = round($cumulative, 2);
            $labels[] = $date->format('m/d');
        }
        
        $color = $cumulative >= 0 ? 'rgb(34, 197, 94)' : 'rgb(239, 68, 68)';
        
        return [
            'datasets' => [
                [
                    'label' => 'سود/زیان تجمعی ($)',
                    'data' => $data,
                    'borderColor' => $color,
                    'backgroundColor' => $color . '20',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 5,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
    
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'labels' => [
                        'font' => [
                            'family' => 'Vazirmatn',
                        ],
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => false,
                    'grid' => [
                        'color' => 'rgba(255, 255, 255, 0.1)',
                    ],
                    'ticks' => [
                        'font' => [
                            'family' => 'Vazirmatn',
                        ],
                        'callback' => "function(value) { return '$' + value.toLocaleString(); }",
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'font' => [
                            'family' => 'Vazirmatn',
                            'size' => 11,
                        ],
                    ],
                ],
            ],
            'maintainAspectRatio' => false,
        ];
    }
}