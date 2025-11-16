<?php

namespace App\Filament\Pages;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Models\CompletedTrade;
use App\Models\BotActivityLog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class BotIntelDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string $view = 'filament.pages.bot-intel-dashboard';

    protected static ?string $navigationLabel = 'Bot Intelligence';

    protected static ?string $title = 'Bot Intelligence';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationGroup = 'Grid Trading';

    public ?int $selectedBotId = null;
    public ?BotConfig $selectedBot = null;

    public function mount(): void
    {
        // Select first active bot by default
        $this->selectedBot = BotConfig::active()->first() ?? BotConfig::first();
        $this->selectedBotId = $this->selectedBot?->id;
    }

    public function updatedSelectedBotId($value): void
    {
        $this->selectedBot = BotConfig::find($value);
    }

    /**
     * Get all available bots for selection
     */
    public function getAvailableBots(): Collection
    {
        return BotConfig::orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn($bot) => [
                'id' => $bot->id,
                'name' => $bot->name,
                'symbol' => $bot->symbol,
                'is_active' => $bot->is_active,
                'status' => $bot->is_active ? 'Active' : 'Paused',
            ]);
    }

    /**
     * Get global snapshot metrics for the selected bot
     */
    public function getSnapshotMetrics(): array
    {
        if (!$this->selectedBot) {
            return $this->getEmptyMetrics();
        }

        $bot = $this->selectedBot;

        // Status & last check
        $lastCheckRelative = $bot->last_run_at
            ? $bot->last_run_at->diffForHumans()
            : 'Never';

        // Capital in use
        $activeOrders = $bot->gridOrders()
            ->whereIn('status', ['placed', 'active'])
            ->get();

        $capitalInUse = $activeOrders->sum(function($order) {
            return $order->price * $order->amount;
        });

        $totalCapital = $bot->budget_irt ?: $bot->total_capital ?: 0;
        $capitalPercent = $totalCapital > 0 ? round(($capitalInUse / $totalCapital) * 100, 1) : 0;

        // Grid health - determine if bot is trading in upper/lower/middle of grid
        $gridHealth = $this->calculateGridHealth($bot, $activeOrders);

        // Completed cycles
        $completedCycles = CompletedTrade::where('bot_config_id', $bot->id)->count();

        // Win rate
        $successfulTrades = CompletedTrade::where('bot_config_id', $bot->id)
            ->where('profit', '>', 0)
            ->count();
        $winRate = $completedCycles > 0
            ? round(($successfulTrades / $completedCycles) * 100, 1)
            : 0;

        // Average cycle duration
        $avgCycleDuration = $this->calculateAvgCycleDuration($bot);

        return [
            'status' => [
                'label' => 'Status',
                'value' => $bot->is_active ? 'Active' : 'Paused',
                'caption' => 'Last check: ' . $lastCheckRelative,
                'color' => $bot->is_active ? 'success' : 'gray',
                'icon' => $bot->is_active ? 'heroicon-o-check-circle' : 'heroicon-o-pause-circle',
            ],
            'capital' => [
                'label' => 'Capital in Use',
                'value' => number_format($capitalInUse / 1000000000, 2) . 'M IRT',
                'caption' => $capitalPercent . '% of total',
                'color' => 'primary',
                'icon' => 'heroicon-o-banknotes',
            ],
            'grid_health' => [
                'label' => 'Grid Health',
                'value' => $gridHealth['status'],
                'caption' => $gridHealth['description'],
                'color' => $gridHealth['color'],
                'icon' => 'heroicon-o-chart-bar-square',
            ],
            'cycles' => [
                'label' => 'Cycles Completed',
                'value' => number_format($completedCycles),
                'caption' => 'All-time',
                'color' => 'info',
                'icon' => 'heroicon-o-arrow-path',
            ],
            'win_rate' => [
                'label' => 'Win Rate',
                'value' => $winRate . '%',
                'caption' => $successfulTrades . ' profitable',
                'color' => $winRate >= 70 ? 'success' : ($winRate >= 50 ? 'warning' : 'danger'),
                'icon' => 'heroicon-o-trophy',
            ],
            'avg_duration' => [
                'label' => 'Avg Cycle Duration',
                'value' => $avgCycleDuration,
                'caption' => 'Buy to sell',
                'color' => 'secondary',
                'icon' => 'heroicon-o-clock',
            ],
        ];
    }

    /**
     * Calculate grid health based on active orders distribution
     */
    private function calculateGridHealth(BotConfig $bot, Collection $activeOrders): array
    {
        if ($activeOrders->isEmpty()) {
            return [
                'status' => 'Balanced',
                'description' => 'No active orders',
                'color' => 'gray',
            ];
        }

        $buyOrders = $activeOrders->where('side', 'buy')->count();
        $sellOrders = $activeOrders->where('side', 'sell')->count();
        $total = $activeOrders->count();

        if ($total === 0) {
            return [
                'status' => 'Balanced',
                'description' => 'No orders',
                'color' => 'gray',
            ];
        }

        $buyPercent = ($buyOrders / $total) * 100;

        if ($buyPercent > 70) {
            return [
                'status' => 'Bottom-Heavy',
                'description' => 'Most orders below price',
                'color' => 'info',
            ];
        } elseif ($buyPercent < 30) {
            return [
                'status' => 'Top-Heavy',
                'description' => 'Most orders above price',
                'color' => 'warning',
            ];
        }

        return [
            'status' => 'Balanced',
            'description' => 'Good distribution',
            'color' => 'success',
        ];
    }

    /**
     * Calculate average cycle duration
     */
    private function calculateAvgCycleDuration(BotConfig $bot): string
    {
        $trades = CompletedTrade::where('bot_config_id', $bot->id)
            ->whereNotNull('execution_time_seconds')
            ->get();

        if ($trades->isEmpty()) {
            return 'N/A';
        }

        $avgSeconds = $trades->avg('execution_time_seconds');

        if ($avgSeconds < 60) {
            return round($avgSeconds) . 's';
        } elseif ($avgSeconds < 3600) {
            return round($avgSeconds / 60) . 'm';
        } else {
            return round($avgSeconds / 3600, 1) . 'h';
        }
    }

    /**
     * Get grid map data showing levels and orders
     */
    public function getGridMapData(): array
    {
        if (!$this->selectedBot) {
            return ['levels' => []];
        }

        $bot = $this->selectedBot;
        $activeOrders = $bot->gridOrders()
            ->whereIn('status', ['placed', 'active'])
            ->orderBy('price', 'desc')
            ->get();

        if ($activeOrders->isEmpty()) {
            return [
                'levels' => [],
                'has_data' => false,
                'message' => 'No active orders to display grid levels',
            ];
        }

        // Group orders by price level
        $levels = [];
        $index = 1;

        foreach ($activeOrders as $order) {
            $levels[] = [
                'index' => $index++,
                'price' => number_format($order->price, 0),
                'side' => $order->side,
                'amount' => $order->amount,
                'status' => $order->status,
                'order_id' => $order->id,
            ];
        }

        return [
            'levels' => $levels,
            'has_data' => true,
            'top_price' => $levels[0]['price'] ?? 'N/A',
            'bottom_price' => $levels[count($levels) - 1]['price'] ?? 'N/A',
            'total_levels' => count($levels),
        ];
    }

    /**
     * Get open orders
     */
    public function getOpenOrders(): Collection
    {
        if (!$this->selectedBot) {
            return collect([]);
        }

        return $this->selectedBot->gridOrders()
            ->whereIn('status', ['placed', 'active'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($order) => [
                'id' => $order->id,
                'type' => ucfirst($order->side ?? 'unknown'),
                'side' => $order->side,
                'price' => number_format($order->price, 0),
                'amount' => number_format($order->amount, 8),
                'time_ago' => $order->created_at->diffForHumans(),
                'status' => $order->status,
            ]);
    }

    /**
     * Get completed trade pairs
     */
    public function getCompletedPairs(): Collection
    {
        if (!$this->selectedBot) {
            return collect([]);
        }

        return CompletedTrade::where('bot_config_id', $this->selectedBot->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($trade) => [
                'id' => substr(md5($trade->id), 0, 8),
                'buy_price' => number_format($trade->buy_price, 0),
                'sell_price' => number_format($trade->sell_price, 0),
                'profit' => number_format($trade->profit, 0),
                'profit_formatted' => ($trade->profit >= 0 ? '+' : '') . number_format($trade->profit, 0) . ' IRT',
                'duration' => $trade->execution_time_formatted ?? 'N/A',
                'completed_at' => $trade->created_at->diffForHumans(),
                'is_profitable' => $trade->profit > 0,
            ]);
    }

    /**
     * Get capital concentration data
     */
    public function getCapitalConcentration(): array
    {
        if (!$this->selectedBot) {
            return $this->getEmptyCapitalData();
        }

        $bot = $this->selectedBot;
        $activeOrders = $bot->gridOrders()
            ->whereIn('status', ['placed', 'active'])
            ->get();

        $buyOrders = $activeOrders->where('side', 'buy');
        $sellOrders = $activeOrders->where('side', 'sell');

        $buyCapital = $buyOrders->sum(fn($o) => $o->price * $o->amount);
        $sellCapital = $sellOrders->sum(fn($o) => $o->price * $o->amount);
        $totalCapital = $bot->budget_irt ?: $bot->total_capital ?: 1;
        $freeCapital = max(0, $totalCapital - $buyCapital - $sellCapital);

        $buyPercent = round(($buyCapital / $totalCapital) * 100, 1);
        $sellPercent = round(($sellCapital / $totalCapital) * 100, 1);
        $freePercent = round(($freeCapital / $totalCapital) * 100, 1);

        return [
            'buy' => [
                'count' => $buyOrders->count(),
                'capital' => number_format($buyCapital / 1000000000, 2) . 'M',
                'percent' => $buyPercent,
            ],
            'sell' => [
                'count' => $sellOrders->count(),
                'capital' => number_format($sellCapital / 1000000000, 2) . 'M',
                'percent' => $sellPercent,
            ],
            'free' => [
                'capital' => number_format($freeCapital / 1000000000, 2) . 'M',
                'percent' => $freePercent,
            ],
        ];
    }

    /**
     * Get grid drift indicator
     */
    public function getGridDrift(): array
    {
        if (!$this->selectedBot) {
            return ['status' => 'N/A', 'description' => 'No bot selected', 'position' => 50];
        }

        $bot = $this->selectedBot;
        $activeOrders = $bot->gridOrders()
            ->whereIn('status', ['placed', 'active'])
            ->get();

        if ($activeOrders->isEmpty()) {
            return [
                'status' => 'No Data',
                'description' => 'No active orders to measure drift',
                'position' => 50,
                'color' => 'gray',
            ];
        }

        $buyOrders = $activeOrders->where('side', 'buy')->count();
        $totalOrders = $activeOrders->count();

        $buyPercent = ($buyOrders / $totalOrders) * 100;

        if ($buyPercent > 75) {
            $status = 'Lower 25% of grid';
            $color = 'info';
        } elseif ($buyPercent > 60) {
            $status = 'Lower-middle zone';
            $color = 'primary';
        } elseif ($buyPercent >= 40) {
            $status = 'Centered in grid';
            $color = 'success';
        } elseif ($buyPercent >= 25) {
            $status = 'Upper-middle zone';
            $color = 'primary';
        } else {
            $status = 'Upper 25% of grid';
            $color = 'warning';
        }

        return [
            'status' => $status,
            'description' => "Trading zone indicator",
            'position' => round(100 - $buyPercent, 1),
            'color' => $color,
        ];
    }

    /**
     * Get system health & job status
     */
    public function getSystemHealth(): array
    {
        if (!$this->selectedBot) {
            return $this->getEmptySystemHealth();
        }

        $bot = $this->selectedBot;

        // Get last successful CHECK_TRADES
        $lastCheckTrades = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('action_type', 'CHECK_TRADES_END')
            ->where('level', '!=', 'ERROR')
            ->orderBy('created_at', 'desc')
            ->first();

        // Get last API call
        $lastApiCall = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('action_type', 'API_CALL')
            ->orderBy('created_at', 'desc')
            ->first();

        // Get last API error
        $lastApiError = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('action_type', 'API_CALL')
            ->where('level', 'ERROR')
            ->orderBy('created_at', 'desc')
            ->first();

        // Count errors in last 24h
        $errorsLast24h = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('level', 'ERROR')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        return [
            'check_trades' => [
                'label' => 'Last Check Run',
                'value' => $lastCheckTrades ? $lastCheckTrades->created_at->diffForHumans() : 'Never',
                'status' => $lastCheckTrades && $lastCheckTrades->created_at->gt(now()->subMinutes(10)) ? 'healthy' : 'stale',
                'color' => $lastCheckTrades && $lastCheckTrades->created_at->gt(now()->subMinutes(10)) ? 'success' : 'warning',
            ],
            'api_connectivity' => [
                'label' => 'Nobitex API',
                'value' => $lastApiCall ? $lastApiCall->created_at->diffForHumans() : 'No calls',
                'status' => $lastApiCall && $lastApiCall->created_at->gt(now()->subMinutes(5)) ? 'healthy' : 'stale',
                'color' => $lastApiCall && $lastApiCall->created_at->gt(now()->subMinutes(5)) ? 'success' : 'warning',
            ],
            'stability' => [
                'label' => 'Stability',
                'value' => $errorsLast24h === 0 ? 'Stable' : 'Attention',
                'status' => $errorsLast24h === 0 ? 'healthy' : 'degraded',
                'color' => $errorsLast24h === 0 ? 'success' : 'danger',
                'errors_24h' => $errorsLast24h,
            ],
        ];
    }

    /**
     * Get activity timeline logs
     */
    public function getActivityLogs(): Collection
    {
        if (!$this->selectedBot) {
            return collect([]);
        }

        return BotActivityLog::where('bot_config_id', $this->selectedBot->id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(fn($log) => [
                'id' => $log->id,
                'action_type' => $log->action_type,
                'level' => $log->level,
                'message' => $log->message,
                'time_ago' => $log->created_at->diffForHumans(),
                'timestamp' => $log->created_at->format('Y-m-d H:i:s'),
                'has_api_data' => !empty($log->api_request) || !empty($log->api_response),
                'api_request' => $log->api_request,
                'api_response' => $log->api_response,
                'execution_time' => $log->execution_time,
                'icon' => $this->getLogIcon($log->level),
                'color' => $this->getLogColor($log->level),
            ]);
    }

    /**
     * Get icon for log level
     */
    private function getLogIcon(string $level): string
    {
        return match($level) {
            'SUCCESS' => 'heroicon-o-check-circle',
            'ERROR' => 'heroicon-o-x-circle',
            'WARNING' => 'heroicon-o-exclamation-triangle',
            default => 'heroicon-o-information-circle',
        };
    }

    /**
     * Get color for log level
     */
    private function getLogColor(string $level): string
    {
        return match($level) {
            'SUCCESS' => 'success',
            'ERROR' => 'danger',
            'WARNING' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Empty metrics fallback
     */
    private function getEmptyMetrics(): array
    {
        return [
            'status' => ['label' => 'Status', 'value' => 'N/A', 'caption' => 'No bot selected', 'color' => 'gray', 'icon' => 'heroicon-o-pause-circle'],
            'capital' => ['label' => 'Capital in Use', 'value' => '0', 'caption' => 'N/A', 'color' => 'gray', 'icon' => 'heroicon-o-banknotes'],
            'grid_health' => ['label' => 'Grid Health', 'value' => 'N/A', 'caption' => 'N/A', 'color' => 'gray', 'icon' => 'heroicon-o-chart-bar-square'],
            'cycles' => ['label' => 'Cycles Completed', 'value' => '0', 'caption' => 'N/A', 'color' => 'gray', 'icon' => 'heroicon-o-arrow-path'],
            'win_rate' => ['label' => 'Win Rate', 'value' => '0%', 'caption' => 'N/A', 'color' => 'gray', 'icon' => 'heroicon-o-trophy'],
            'avg_duration' => ['label' => 'Avg Cycle Duration', 'value' => 'N/A', 'caption' => 'N/A', 'color' => 'gray', 'icon' => 'heroicon-o-clock'],
        ];
    }

    private function getEmptyCapitalData(): array
    {
        return [
            'buy' => ['count' => 0, 'capital' => '0', 'percent' => 0],
            'sell' => ['count' => 0, 'capital' => '0', 'percent' => 0],
            'free' => ['capital' => '0', 'percent' => 100],
        ];
    }

    private function getEmptySystemHealth(): array
    {
        return [
            'check_trades' => ['label' => 'Last Check Run', 'value' => 'N/A', 'status' => 'unknown', 'color' => 'gray'],
            'api_connectivity' => ['label' => 'Nobitex API', 'value' => 'N/A', 'status' => 'unknown', 'color' => 'gray'],
            'stability' => ['label' => 'Stability', 'value' => 'N/A', 'status' => 'unknown', 'color' => 'gray', 'errors_24h' => 0],
        ];
    }
}
