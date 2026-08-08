<?php

namespace App\Filament\Pages;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Models\CompletedTrade;
use App\Models\BotActivityLog;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BotMonitoring extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.bot-monitoring';

    protected static ?string $navigationLabel = 'مانیتورینگ زنده';

    protected static ?string $title = 'مانیتورینگ زنده';

    protected static ?int $navigationSort = 2;

    /**
     * Selected bot for the deep single-bot analytics block (P4-final).
     *
     * The «هوش ربات» (Bot Intelligence) page was merged into this one: the live
     * fleet view (above) and the deep per-bot analysis (grid map, capital
     * concentration, grid drift, stability, completed pairs) now live on a
     * single «مانیتورینگ زنده» page, driven by this picker. The metric methods
     * below moved over verbatim from BotIntelDashboard — no computation changed.
     */
    public ?int $selectedBotId = null;
    public ?BotConfig $selectedBot = null;

    public function mount(): void
    {
        // Default the analytics picker to the first active bot (else the first).
        $this->selectedBot = BotConfig::active()->first() ?? BotConfig::first();
        $this->selectedBotId = $this->selectedBot?->id;
    }

    public function updatedSelectedBotId($value): void
    {
        $this->selectedBot = BotConfig::find($value);
    }

    /**
     * Single source of truth for the page's selected bot.
     *
     * Driven by the prominent top-of-page selector (P4-compact): one click
     * updates BOTH the live fleet view (Alpine focuses this bot via a
     * $wire.$watch on selectedBotId) AND the deep analytics block below.
     */
    public function selectBot(int $botId): void
    {
        $this->selectedBotId = $botId;
        $this->selectedBot = BotConfig::find($botId);
    }

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

            // Currently-held grid levels: filled orders whose round-trip is
            // still open (the continuation leg hasn't filled, or none exists
            // yet). This is the "how many levels are filled right NOW" count
            // shown in the «سفارشات فعال» subline. It is deliberately different
            // from debug.total_filled below, which counts EVERY fill ever
            // booked — both legs of every completed cycle — and therefore only
            // ever grows (that cumulative number is why the subline read "۱۰"
            // when a single level was actually held).
            $filledLegs = $bot->gridOrders()
                ->where('status', 'filled')
                ->get(['id', 'paired_order_id']);
            $filledIds = $filledLegs->pluck('id')->all();
            $currentlyFilled = $filledLegs->filter(function ($o) use ($filledIds) {
                // Open position = no continuation yet, or its partner leg
                // (the paired order) has not itself filled. A closed cycle has
                // both legs filled, so both are excluded here.
                return $o->paired_order_id === null
                    || ! in_array($o->paired_order_id, $filledIds, true);
            })->count();

            // Debug data
            $debugData = [
                'total_orders' => $bot->gridOrders()->count(),
                'total_with_status_active' => $bot->gridOrders()->whereIn('status', ['placed', 'active'])->count(),
                'total_not_executed' => $bot->gridOrders()->whereNull('filled_at')->count(),
                'total_not_paired' => $bot->gridOrders()->whereNull('paired_order_id')->count(),
                'total_filled' => $bot->gridOrders()->where('status', 'filled')->count(),
                'currently_filled' => $currentlyFilled,
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
                // Presentation-only: newest trade time, derived from the
                // $completedTrades collection already loaded above (ordered asc,
                // so last() is the most recent). No extra query — feeds the
                // "زمان از آخرین معامله" stat card in the live view.
                'last_trade_at' => optional($completedTrades->last())->created_at?->toIso8601String(),

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

    /* =====================================================================
       Deep single-bot analytics — relocated verbatim from the former
       BotIntelDashboard (merged into this page in P4-final). These power the
       bot-picker-driven analytics block: grid map, capital concentration,
       grid drift, stability and completed pairs. The buy/sell dimension lives
       in grid_orders.type (not `side`) — the P2 fix these methods carry — so
       BotIntelDashboardSideMetricsTest keeps asserting against them here.
       ===================================================================== */

    /**
     * All bots available for the analytics picker.
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
                'status' => $bot->is_active ? 'فعال' : 'متوقف',
            ]);
    }

    /**
     * Grid map data showing levels and active orders for the selected bot.
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
                'message' => 'سفارش فعالی برای نمایش سطوح گرید وجود ندارد',
            ];
        }

        $levels = [];
        $index = 1;

        foreach ($activeOrders as $order) {
            $levels[] = [
                'index' => $index++,
                'price' => number_format($order->price, 0),
                'side' => $order->type,
                'amount' => $order->amount,
                'status' => $order->status,
                'order_id' => $order->id,
            ];
        }

        return [
            'levels' => $levels,
            'has_data' => true,
            'top_price' => $levels[0]['price'] ?? 'ندارد',
            'bottom_price' => $levels[count($levels) - 1]['price'] ?? 'ندارد',
            'total_levels' => count($levels),
        ];
    }

    /**
     * Recent completed trade pairs for the selected bot.
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
                'duration' => $trade->execution_time_formatted ?? 'ندارد',
                'completed_at' => $trade->created_at->diffForHumans(),
                'is_profitable' => $trade->profit > 0,
            ]);
    }

    /**
     * Capital concentration across order types for the selected bot.
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

        $buyOrders = $activeOrders->where('type', 'buy');
        $sellOrders = $activeOrders->where('type', 'sell');

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
     * Grid drift indicator — where in the grid the bot is trading.
     */
    public function getGridDrift(): array
    {
        if (!$this->selectedBot) {
            return ['status' => 'ندارد', 'description' => 'رباتی انتخاب نشده', 'position' => 50];
        }

        $bot = $this->selectedBot;
        $activeOrders = $bot->gridOrders()
            ->whereIn('status', ['placed', 'active'])
            ->get();

        if ($activeOrders->isEmpty()) {
            return [
                'status' => 'بدون داده',
                'description' => 'سفارش فعالی برای سنجش انحراف وجود ندارد',
                'position' => 50,
                'color' => 'gray',
            ];
        }

        $buyOrders = $activeOrders->where('type', 'buy')->count();
        $totalOrders = $activeOrders->count();

        $buyPercent = ($buyOrders / $totalOrders) * 100;

        if ($buyPercent > 75) {
            $status = 'یک‌چهارم پایینی گرید';
            $color = 'info';
        } elseif ($buyPercent > 60) {
            $status = 'ناحیه میانی-پایین';
            $color = 'primary';
        } elseif ($buyPercent >= 40) {
            $status = 'مرکز گرید';
            $color = 'success';
        } elseif ($buyPercent >= 25) {
            $status = 'ناحیه میانی-بالا';
            $color = 'primary';
        } else {
            $status = 'یک‌چهارم بالایی گرید';
            $color = 'warning';
        }

        return [
            'status' => $status,
            'description' => 'شاخص ناحیه معاملاتی',
            'position' => round(100 - $buyPercent, 1),
            'color' => $color,
        ];
    }

    /**
     * System health & stability signals for the selected bot.
     */
    public function getSystemHealth(): array
    {
        if (!$this->selectedBot) {
            return $this->getEmptySystemHealth();
        }

        $bot = $this->selectedBot;

        $lastCheckTrades = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('action_type', 'CHECK_TRADES_END')
            ->where('level', '!=', 'ERROR')
            ->orderBy('created_at', 'desc')
            ->first();

        $lastApiCall = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('action_type', 'API_CALL')
            ->orderBy('created_at', 'desc')
            ->first();

        $errorsLast24h = BotActivityLog::where('bot_config_id', $bot->id)
            ->where('level', 'ERROR')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        return [
            'check_trades' => [
                'label' => 'آخرین بررسی',
                'value' => $lastCheckTrades ? $lastCheckTrades->created_at->diffForHumans() : 'هرگز',
                'status' => $lastCheckTrades && $lastCheckTrades->created_at->gt(now()->subMinutes(10)) ? 'healthy' : 'stale',
                'color' => $lastCheckTrades && $lastCheckTrades->created_at->gt(now()->subMinutes(10)) ? 'success' : 'warning',
            ],
            'api_connectivity' => [
                'label' => 'API نوبیتکس',
                'value' => $lastApiCall ? $lastApiCall->created_at->diffForHumans() : 'بدون فراخوانی',
                'status' => $lastApiCall && $lastApiCall->created_at->gt(now()->subMinutes(5)) ? 'healthy' : 'stale',
                'color' => $lastApiCall && $lastApiCall->created_at->gt(now()->subMinutes(5)) ? 'success' : 'warning',
            ],
            'stability' => [
                'label' => 'پایداری',
                'value' => $errorsLast24h === 0 ? 'پایدار' : 'نیازمند بررسی',
                'status' => $errorsLast24h === 0 ? 'healthy' : 'degraded',
                'color' => $errorsLast24h === 0 ? 'success' : 'danger',
                'errors_24h' => $errorsLast24h,
            ],
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
            'check_trades' => ['label' => 'آخرین بررسی', 'value' => 'ندارد', 'status' => 'unknown', 'color' => 'gray'],
            'api_connectivity' => ['label' => 'API نوبیتکس', 'value' => 'ندارد', 'status' => 'unknown', 'color' => 'gray'],
            'stability' => ['label' => 'پایداری', 'value' => 'ندارد', 'status' => 'unknown', 'color' => 'gray', 'errors_24h' => 0],
        ];
    }
}
