<?php

namespace App\Filament\Pages;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Models\CompletedTrade;
use App\Models\BotActivityLog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class BotMonitoring extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.bot-monitoring';

    protected static ?string $navigationLabel = 'Bot Monitoring';

    protected static ?string $title = 'Grid Trading Bot Monitoring';

    protected static ?int $navigationSort = 2;

    public function getBotData()
    {
        $bots = BotConfig::where('is_active', true)->get();

        $data = [];

        foreach ($bots as $bot) {
            // Active orders: فقط سفارشاتی که واقعاً فعال هستند (هنوز fill نشده و pair نشده)
            $activeOrders = $bot->gridOrders()
                ->whereIn('status', ['placed', 'active'])
                ->whereNull('filled_at')        // هنوز fill نشده
                ->whereNull('paired_order_id')  // هنوز pair نشده
                ->get();

            // Filled orders in last 24h
            $filledOrders = $bot->gridOrders()
                ->where('status', 'filled')
                ->where('filled_at', '>=', now()->subHours(24))
                ->count();

            // Get completed trades with dates for charts
            $completedTrades = CompletedTrade::where('bot_config_id', $bot->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->orderBy('created_at', 'asc')
                ->get();

            // Calculate daily profits for chart
            $dailyProfits = [];
            for ($i = 29; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $dayProfit = $completedTrades
                    ->filter(fn($t) => $t->created_at->format('Y-m-d') === $date)
                    ->sum('profit');
                $dailyProfits[] = [
                    'date' => $date,
                    'profit' => $dayProfit,
                    'trades' => $completedTrades
                        ->filter(fn($t) => $t->created_at->format('Y-m-d') === $date)
                        ->count()
                ];
            }

            // Calculate order fill distribution by hour
            $fillDistribution = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $count = $bot->gridOrders()
                    ->where('status', 'filled')
                    ->whereNotNull('filled_at')
                    ->where('filled_at', '>=', now()->subDays(7))
                    ->get()
                    ->filter(fn($o) => $o->filled_at->hour === $hour)
                    ->count();
                $fillDistribution[] = [
                    'hour' => $hour,
                    'count' => $count
                ];
            }

            // Calculate average cycle duration
            $pairedOrders = $bot->gridOrders()
                ->where('status', 'filled')
                ->whereNotNull('paired_order_id')
                ->get();

            $cycleDurations = [];
            foreach ($pairedOrders as $order) {
                $paired = $bot->gridOrders()->find($order->paired_order_id);
                if ($paired && $paired->filled_at && $order->filled_at) {
                    $duration = abs($paired->filled_at->diffInMinutes($order->filled_at));
                    $cycleDurations[] = $duration;
                }
            }

            $avgCycleDuration = !empty($cycleDurations) ? array_sum($cycleDurations) / count($cycleDurations) : 0;

            // Calculate 24h change
            $profit24h = $completedTrades
                ->filter(fn($t) => $t->created_at >= now()->subHours(24))
                ->sum('profit');

            $profitPrevious24h = CompletedTrade::where('bot_config_id', $bot->id)
                ->whereBetween('created_at', [now()->subHours(48), now()->subHours(24)])
                ->sum('profit');

            $profitChange = $profitPrevious24h > 0
                ? (($profit24h - $profitPrevious24h) / $profitPrevious24h) * 100
                : 0;

            // Get latest activity logs (safe check for table existence)
            try {
                $activityLogs = BotActivityLog::where('bot_config_id', $bot->id)
                    ->orderBy('created_at', 'desc')
                    ->limit(150)
                    ->get();

                // Group logs into cycles and calculate statistics
                $cycleData = $this->groupLogsToCycles($activityLogs);
            } catch (\Exception $e) {
                // Table doesn't exist yet - return empty data
                $cycleData = [
                    'cycles' => [],
                    'summary' => [
                        'last_cycle_status' => null,
                        'avg_cycle_duration' => 0,
                        'avg_api_latency' => 0,
                        'cycles_count_24h' => 0,
                        'error_count_24h' => 0,
                    ],
                ];
            }

            // Debug data
            $debugData = [
                'total_orders' => $bot->gridOrders()->count(),
                'total_with_status_active' => $bot->gridOrders()->whereIn('status', ['placed', 'active'])->count(),
                'total_not_executed' => $bot->gridOrders()->whereNull('filled_at')->count(),
                'total_not_paired' => $bot->gridOrders()->whereNull('paired_order_id')->count(),
                'total_filled' => $bot->gridOrders()->where('status', 'filled')->count(),
                'completed_trades_total' => $bot->completedTrades()->count(),
                'completed_trades_24h_actual' => $bot->completedTrades()->where('created_at', '>=', now()->subHours(24))->count(),
                'profit_total' => $bot->completedTrades()->sum('profit'),
                'profit_24h_actual' => $bot->completedTrades()->where('created_at', '>=', now()->subHours(24))->sum('profit'),
            ];

            $data[] = [
                'id' => $bot->id,
                'name' => $bot->name,
                'symbol' => $bot->symbol,
                'status' => $bot->is_active ? 'active' : 'inactive',
                'capital' => $bot->total_capital,
                'grid_levels' => $bot->grid_levels,
                'grid_spacing' => $bot->grid_spacing,
                'active_orders' => $activeOrders->map(fn($o) => [
                    'id' => $o->id,
                    'type' => $o->type,
                    'price' => $o->price,
                    'amount' => $o->amount,
                    'status' => $o->status,
                    'paired_order_id' => $o->paired_order_id,
                    'nobitex_order_id' => $o->nobitex_order_id,
                ]),
                'filled_24h' => $filledOrders,
                'completed_trades_24h' => $completedTrades->filter(fn($t) => $t->created_at >= now()->subHours(24))->count(),
                'profit_24h' => $profit24h,
                'profit_change_24h' => round($profitChange, 2),
                'last_check_at' => $bot->last_check_at,

                // Chart data
                'daily_profits' => $dailyProfits,
                'fill_distribution' => $fillDistribution,
                'avg_cycle_duration' => round($avgCycleDuration, 1),
                'total_cycles' => count($cycleDurations),

                // Activity logs - new cycle-based structure
                'activity_cycles' => $cycleData['cycles'],
                'activity_summary' => $cycleData['summary'],

                // Debug data
                'debug' => $debugData,
            ];
        }

        return $data;
    }

    /**
     * Group activity logs into cycles (CHECK_TRADES_START to CHECK_TRADES_END)
     * and calculate summary statistics.
     */
    private function groupLogsToCycles($logs)
    {
        if ($logs->isEmpty()) {
            return [
                'cycles' => [],
                'summary' => [
                    'last_cycle_status' => null,
                    'avg_cycle_duration' => 0,
                    'avg_api_latency' => 0,
                    'cycles_count_24h' => 0,
                    'error_count_24h' => 0,
                ],
            ];
        }

        // Reverse to process chronologically (oldest first)
        $logsArray = $logs->reverse()->values();
        $cycles = [];
        $currentCycle = null;
        $ungroupedEvents = [];

        foreach ($logsArray as $log) {
            $event = [
                'id' => $log->id,
                'type' => $log->action_type,
                'level' => $log->level,
                'message' => $log->message,
                'time' => $log->created_at,
                'time_iso' => $log->created_at->toIso8601String(),
                'details' => $log->details,
                'api_request' => $log->api_request,
                'api_response' => $log->api_response,
                'execution_time' => $log->execution_time,
            ];

            if ($log->action_type === 'CHECK_TRADES_START') {
                // Start a new cycle
                if ($currentCycle !== null) {
                    // Close previous unclosed cycle as incomplete
                    $cycles[] = $this->finalizeCycle($currentCycle);
                }

                $currentCycle = [
                    'id' => 'cycle-' . $log->id,
                    'started_at' => $log->created_at,
                    'started_at_iso' => $log->created_at->toIso8601String(),
                    'ended_at' => null,
                    'ended_at_iso' => null,
                    'duration_ms' => null,
                    'status' => 'in_progress',
                    'summary' => [
                        'orders_active' => 0,
                        'api_calls' => 0,
                        'errors' => 0,
                        'orders_filled' => 0,
                        'trades_completed' => 0,
                    ],
                    'events' => [$event],
                ];
            } elseif ($log->action_type === 'CHECK_TRADES_END' && $currentCycle !== null) {
                // Close the current cycle
                $currentCycle['ended_at'] = $log->created_at;
                $currentCycle['ended_at_iso'] = $log->created_at->toIso8601String();
                $currentCycle['duration_ms'] = $currentCycle['started_at']->diffInMilliseconds($log->created_at);
                $currentCycle['events'][] = $event;

                $cycles[] = $this->finalizeCycle($currentCycle);
                $currentCycle = null;
            } else {
                // Add event to current cycle or ungrouped
                if ($currentCycle !== null) {
                    $currentCycle['events'][] = $event;

                    // Update summary based on event type
                    if ($log->action_type === 'API_CALL') {
                        $currentCycle['summary']['api_calls']++;
                    } elseif ($log->action_type === 'ORDERS_RECEIVED') {
                        // Extract order count from message or details
                        if (preg_match('/(\d+)\s*سفارش/', $log->message, $matches)) {
                            $currentCycle['summary']['orders_active'] = (int)$matches[1];
                        }
                    } elseif ($log->action_type === 'ORDER_FILLED') {
                        $currentCycle['summary']['orders_filled']++;
                    } elseif ($log->action_type === 'TRADE_COMPLETED') {
                        $currentCycle['summary']['trades_completed']++;
                    } elseif ($log->level === 'ERROR') {
                        $currentCycle['summary']['errors']++;
                    }
                } else {
                    $ungroupedEvents[] = $event;
                }
            }
        }

        // If there's an unclosed cycle at the end, add it as in-progress
        if ($currentCycle !== null) {
            $cycles[] = $this->finalizeCycle($currentCycle);
        }

        // Add ungrouped events as a separate "cycle" at the end if any exist
        if (!empty($ungroupedEvents)) {
            $cycles[] = [
                'id' => 'cycle-ungrouped',
                'started_at' => $ungroupedEvents[0]['time'],
                'started_at_iso' => $ungroupedEvents[0]['time_iso'],
                'ended_at' => null,
                'ended_at_iso' => null,
                'duration_ms' => null,
                'status' => 'ungrouped',
                'summary' => [
                    'orders_active' => 0,
                    'api_calls' => 0,
                    'errors' => 0,
                    'orders_filled' => 0,
                    'trades_completed' => 0,
                ],
                'events' => $ungroupedEvents,
            ];
        }

        // Reverse cycles to show newest first
        $cycles = array_reverse($cycles);

        // Calculate summary statistics
        $summary = $this->calculateSummaryStats($cycles);

        return [
            'cycles' => $cycles,
            'summary' => $summary,
        ];
    }

    /**
     * Finalize a cycle by determining its status based on events.
     */
    private function finalizeCycle($cycle)
    {
        // Determine status
        if ($cycle['summary']['errors'] > 0) {
            $cycle['status'] = 'error';
        } elseif ($cycle['ended_at'] === null) {
            $cycle['status'] = 'in_progress';
        } else {
            // Check for slow API calls or long duration
            $hasSlowApi = false;
            foreach ($cycle['events'] as $event) {
                if ($event['type'] === 'API_CALL' && $event['execution_time'] > 1000) {
                    $hasSlowApi = true;
                    break;
                }
            }

            if ($hasSlowApi || ($cycle['duration_ms'] !== null && $cycle['duration_ms'] > 5000)) {
                $cycle['status'] = 'warning';
            } else {
                $cycle['status'] = 'success';
            }
        }

        return $cycle;
    }

    /**
     * Calculate summary statistics across all cycles.
     */
    private function calculateSummaryStats($cycles)
    {
        if (empty($cycles)) {
            return [
                'last_cycle_status' => null,
                'avg_cycle_duration' => 0,
                'avg_api_latency' => 0,
                'cycles_count_24h' => 0,
                'error_count_24h' => 0,
            ];
        }

        // Filter for valid cycles (not ungrouped, not in-progress)
        $completedCycles = array_filter($cycles, fn($c) =>
            $c['status'] !== 'ungrouped' &&
            $c['status'] !== 'in_progress' &&
            $c['duration_ms'] !== null
        );

        $cycles24h = array_filter($cycles, fn($c) =>
            $c['started_at'] >= now()->subHours(24)
        );

        // Calculate average cycle duration
        $avgDuration = 0;
        if (!empty($completedCycles)) {
            $totalDuration = array_sum(array_column($completedCycles, 'duration_ms'));
            $avgDuration = $totalDuration / count($completedCycles);
        }

        // Calculate average API latency
        $apiLatencies = [];
        foreach ($cycles as $cycle) {
            foreach ($cycle['events'] as $event) {
                if ($event['type'] === 'API_CALL' && $event['execution_time'] !== null) {
                    $apiLatencies[] = $event['execution_time'];
                }
            }
        }
        $avgApiLatency = !empty($apiLatencies) ? array_sum($apiLatencies) / count($apiLatencies) : 0;

        // Count errors in last 24h
        $errorCount = 0;
        foreach ($cycles24h as $cycle) {
            $errorCount += $cycle['summary']['errors'];
        }

        // Get last cycle status and time
        $lastCycleStatus = null;
        $lastCycleTime = null;
        foreach ($cycles as $cycle) {
            if ($cycle['status'] !== 'ungrouped') {
                $lastCycleStatus = $cycle['status'];
                $lastCycleTime = $cycle['started_at'];
                break;
            }
        }

        return [
            'last_cycle_status' => $lastCycleStatus,
            'last_cycle_time' => $lastCycleTime,
            'last_cycle_duration' => isset($cycles[0]) && $cycles[0]['duration_ms'] ? $cycles[0]['duration_ms'] : null,
            'avg_cycle_duration' => round($avgDuration, 1),
            'avg_api_latency' => round($avgApiLatency, 1),
            'cycles_count_24h' => count($cycles24h),
            'error_count_24h' => $errorCount,
        ];
    }
}
