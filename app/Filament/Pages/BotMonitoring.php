<?php

namespace App\Filament\Pages;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Models\CompletedTrade;
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
            $activeOrders = $bot->gridOrders()
                ->whereIn('status', ['placed', 'active'])
                ->get();

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
            ];
        }

        return $data;
    }
}
