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

            $completedTrades = CompletedTrade::where('bot_config_id', $bot->id)
                ->where('created_at', '>=', now()->subHours(24))
                ->get();

            $totalProfit = $completedTrades->sum('profit');

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
                'completed_trades_24h' => $completedTrades->count(),
                'profit_24h' => $totalProfit,
                'last_check_at' => $bot->last_check_at,
            ];
        }

        return $data;
    }
}
