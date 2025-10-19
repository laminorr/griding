<?php

namespace App\Filament\Widgets;

use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class BotStatusWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    
    protected int | string | array $columnSpan = 'full';
    
    protected function getStats(): array
    {
        $activeBots = BotConfig::where('is_active', true)->count();
        $totalBots = BotConfig::count();
        
        // سرمایه به ریال
        $totalCapitalIRT = BotConfig::sum('total_capital');
        
        $activeCapitalIRT = BotConfig::where('is_active', true)
            ->get()
            ->sum(function ($bot) {
                return ($bot->total_capital * $bot->active_capital_percent) / 100;
            });
        
        // سودهای ریالی
        $todayProfit = CompletedTrade::whereDate('created_at', today())
            ->sum('profit');
        
        $monthProfit = CompletedTrade::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('profit');
        
        // محاسبه آمار بیشتر
        $activeOrders = GridOrder::where('status', 'placed')->count();
        $totalTrades = CompletedTrade::count();
        $successfulTrades = CompletedTrade::where('profit', '>', 0)->count();
        $winRate = $totalTrades > 0 ? ($successfulTrades / $totalTrades) * 100 : 0;
        
        // آمار 7 روز گذشته برای چارت
        $last7DaysProfit = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dayProfit = CompletedTrade::whereDate('created_at', $date)->sum('profit');
            $last7DaysProfit[] = max(0, $dayProfit / 1000000); // تبدیل به میلیون ریال برای چارت
        }
        
        return [
            Stat::make('وضعیت ربات‌ها', $activeBots . ' از ' . $totalBots . ' فعال')
                ->description($activeBots > 0 ? 'در حال معامله' : 'همه ربات‌ها غیرفعال')
                ->descriptionIcon($activeBots > 0 ? 'heroicon-m-play' : 'heroicon-m-pause')
                ->color($activeBots > 0 ? 'success' : 'gray')
                ->chart($activeBots > 0 ? [3, 5, 4, 7, 6, 8, 9] : [0]),
            
            Stat::make('سرمایه کل', Number::format($totalCapitalIRT, 0) . ' ریال')
                ->description('فعال: ' . Number::format($activeCapitalIRT, 0) . ' ریال')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
            
            Stat::make('سود امروز', 
                ($todayProfit >= 0 ? '+' : '') . Number::format($todayProfit, 0) . ' ریال'
            )
                ->description(
                    $todayProfit >= 0 
                        ? Number::percentage($totalCapitalIRT > 0 ? ($todayProfit / $totalCapitalIRT) * 100 : 0, 3) . ' بازدهی'
                        : 'ضرر روزانه'
                )
                ->descriptionIcon(
                    $todayProfit >= 0 
                        ? 'heroicon-m-arrow-trending-up' 
                        : 'heroicon-m-arrow-trending-down'
                )
                ->color($todayProfit >= 0 ? 'success' : 'danger')
                ->chart($last7DaysProfit),
            
            Stat::make('سود این ماه', 
                ($monthProfit >= 0 ? '+' : '') . Number::format($monthProfit, 0) . ' ریال'
            )
                ->description(
                    $monthProfit >= 0 
                        ? Number::percentage($totalCapitalIRT > 0 ? ($monthProfit / $totalCapitalIRT) * 100 : 0, 2) . ' بازدهی ماهانه'
                        : 'ضرر ماهانه'
                )
                ->descriptionIcon(
                    $monthProfit >= 0 
                        ? 'heroicon-m-calendar-days' 
                        : 'heroicon-m-exclamation-triangle'
                )
                ->color($monthProfit >= 0 ? 'success' : 'danger'),
            
            Stat::make('سفارشات فعال', Number::format($activeOrders))
                ->description('در صف اجرا')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color('warning'),
            
            Stat::make('نرخ موفقیت', Number::percentage($winRate, 1))
                ->description(Number::format($totalTrades) . ' کل معاملات')
                ->descriptionIcon('heroicon-m-trophy')
                ->color($winRate >= 70 ? 'success' : ($winRate >= 50 ? 'warning' : 'danger'))
                ->chart([
                    $winRate >= 80 ? 10 : 5,
                    $winRate >= 70 ? 9 : 4,
                    $winRate >= 60 ? 8 : 3,
                    $winRate >= 50 ? 7 : 2,
                    $winRate >= 40 ? 6 : 1,
                    $winRate >= 30 ? 5 : 0,
                    $winRate
                ]),
        ];
    }
    
    public function getColumns(): int
    {
        return 3;
    }
    
    /**
     * هر 30 ثانیه یکبار refresh شود
     */
    protected static ?string $pollingInterval = '30s';
}