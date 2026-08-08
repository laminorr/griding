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
    
    protected static ?string $maxHeight = '180px';

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
        
        // Terminal palette: accent green for a net-positive curve, soft red
        // for a net-negative one. Thin line, no dots — a dense sparkline look.
        $color = $cumulative >= 0 ? 'rgb(52, 211, 153)' : 'rgb(248, 113, 113)';

        return [
            'datasets' => [
                [
                    'label' => 'سود/زیان تجمعی (ریال)',
                    'data' => $data,
                    'borderColor' => $color,
                    'backgroundColor' => $color . '1F',
                    'fill' => true,
                    'tension' => 0.3,
                    'borderWidth' => 1.5,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 3,
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
                    'align' => 'end',
                    'labels' => [
                        'boxWidth' => 8,
                        'boxHeight' => 8,
                        'usePointStyle' => true,
                        'padding' => 8,
                        'font' => [
                            'family' => 'Vazirmatn',
                            'size' => 10,
                        ],
                        'color' => '#7C8899',
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => false,
                    'grid' => [
                        'color' => 'rgba(148, 163, 184, 0.08)',
                    ],
                    'ticks' => [
                        'maxTicksLimit' => 5,
                        'font' => [
                            'family' => 'Vazirmatn',
                            'size' => 10,
                        ],
                        'color' => '#7C8899',
                        'callback' => "function(value) { return value.toLocaleString() + ' ریال'; }",
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'maxTicksLimit' => 8,
                        'font' => [
                            'family' => 'Vazirmatn',
                            'size' => 9,
                        ],
                        'color' => '#7C8899',
                    ],
                ],
            ],
            'maintainAspectRatio' => false,
        ];
    }
}