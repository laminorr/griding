<?php

namespace App\Filament\Resources\BotConfigResource\Pages;

use App\Filament\Resources\BotConfigResource;
use App\Services\TradingEngineService;
use App\Services\NobitexService;
use App\Models\BotConfig;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class ListBotConfigs extends ListRecords
{
    protected static string $resource = BotConfigResource::class;
    
    protected static ?string $title = 'ربات‌های گرید معاملاتی';
    
    protected static ?string $breadcrumb = 'ربات‌ها';

    protected function getHeaderActions(): array
    {
        return [
            // ایجاد ربات جدید
            Actions\CreateAction::make()
                ->label('ربات جدید')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->size('lg')
                ->button(),

            // شروع همه ربات‌ها
            Action::make('start_all')
                ->label('شروع همه')
                ->icon('heroicon-o-play')
                ->color('success')
                ->tooltip('شروع تمام ربات‌های غیرفعال')
                ->requiresConfirmation()
                ->modalHeading('شروع دسته‌جمعی ربات‌ها')
                ->modalDescription(function () {
                    $count = BotConfig::where('is_active', false)->count();
                    return "آیا می‌خواهید {$count} ربات غیرفعال را شروع کنید؟";
                })
                ->action(function () {
                    $this->startAllBots();
                })
                ->visible(fn () => BotConfig::where('is_active', false)->exists()),

            // توقف همه ربات‌ها
            Action::make('stop_all')
                ->label('توقف همه')
                ->icon('heroicon-o-pause')
                ->color('danger')
                ->tooltip('توقف تمام ربات‌های فعال')
                ->requiresConfirmation()
                ->modalHeading('توقف دسته‌جمعی ربات‌ها')
                ->modalDescription(function () {
                    $count = BotConfig::where('is_active', true)->count();
                    return "آیا می‌خواهید {$count} ربات فعال را متوقف کنید؟";
                })
                ->action(function () {
                    $this->stopAllBots();
                })
                ->visible(fn () => BotConfig::where('is_active', true)->exists()),

            // نمایش وضعیت سیستم
            Action::make('system_health')
                ->label('')
                ->icon('heroicon-o-heart')
                ->color($this->getSystemHealthColor())
                ->tooltip('وضعیت سلامت سیستم')
                ->badge($this->getSystemHealthBadge())
                ->badgeColor($this->getSystemHealthColor())
                ->action(function () {
                    $this->showSystemHealth();
                }),
        ];
    }

    // ========== Action Methods ==========
    
    private function startAllBots(): void
    {
        try {
            $inactiveBots = BotConfig::where('is_active', false)->get();
            $successCount = 0;
            $errors = [];
            
            foreach ($inactiveBots as $bot) {
                try {
                    $tradingEngine = app(TradingEngineService::class);
                    $result = $tradingEngine->initializeGrid($bot);
                    
                    if ($result['success']) {
                        // stop_reason پرشدنی نیست؛ پاک‌سازی مستقیم تا نشان سلامت قابل‌اعتماد بماند
                        $bot->stop_reason = null;
                        $bot->update(['is_active' => true]);
                        $successCount++;
                    } else {
                        $errors[] = "ربات {$bot->name}: {$result['message']}";
                    }
                } catch (\Exception $e) {
                    $errors[] = "ربات {$bot->name}: {$e->getMessage()}";
                    Log::error('خطا در شروع ربات', [
                        'bot_id' => $bot->id, 
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $message = "✅ {$successCount} ربات با موفقیت شروع شد";
            if (!empty($errors)) {
                $message .= "\n❌ خطاها:\n" . implode("\n", array_slice($errors, 0, 3));
            }
            
            Notification::make()
                ->title('نتیجه شروع دسته‌جمعی')
                ->body($message)
                ->color($successCount > 0 ? 'success' : 'danger')
                ->persistent()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('خطا در شروع ربات‌ها')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    private function stopAllBots(): void
    {
        try {
            $activeBots = BotConfig::where('is_active', true)->get();
            $stoppedCount = 0;
            
            foreach ($activeBots as $bot) {
                try {
                    // لغو سفارشات فعال
                    $bot->gridOrders()
                        ->where('status', 'placed')
                        ->update(['status' => 'cancelled']);
                    
                    // متوقف کردن ربات
                    $bot->update(['is_active' => false]);
                    $stoppedCount++;
                    
                } catch (\Exception $e) {
                    Log::error('خطا در توقف ربات', [
                        'bot_id' => $bot->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            Notification::make()
                ->title("⏸️ {$stoppedCount} ربات متوقف شد")
                ->body('تمام سفارشات فعال لغو شدند')
                ->warning()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('خطا در توقف ربات‌ها')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    private function showSystemHealth(): void
    {
        try {
            $nobitex = app(NobitexService::class);
            $healthCheck = $nobitex->healthCheck();
            
            // آمار کلی
            $totalBots = BotConfig::count();
            $activeBots = BotConfig::where('is_active', true)->count();
            $totalTrades = \App\Models\CompletedTrade::count();
            $totalProfit = \App\Models\CompletedTrade::sum('profit');
            $todayTrades = \App\Models\CompletedTrade::whereDate('created_at', today())->count();
            
            // وضعیت API
$overallStatus = $healthCheck['overall_status'] ?? 'unhealthy';
$apiStatus = $overallStatus === 'healthy' ? '✅ سالم' : '❌ مشکل';
$responseTime = round($healthCheck['response_time_ms'] ?? 0, 2);
            
            Notification::make()
                ->title('🏥 گزارش وضعیت سیستم')
                ->body("
                    📡 API نوبیتکس: {$apiStatus} ({$responseTime}s)
                    🤖 ربات‌ها: {$activeBots}/{$totalBots} فعال
                    💼 کل معاملات: {$totalTrades}
                    📈 معاملات امروز: {$todayTrades}
                    💰 کل سود: " . number_format($totalProfit, 0) . " IRR
                    ⏰ زمان بررسی: " . now()->format('H:i:s') . "
                ")
                ->info()
                ->persistent()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('خطا در بررسی سلامت سیستم')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getSubheading(): ?string
    {
        try {
            $total = BotConfig::count();
            $active = BotConfig::where('is_active', true)->count();
            $totalProfit = \App\Models\CompletedTrade::sum('profit') ?? 0;
            $profitFormatted = number_format($totalProfit, 0);
            
            return "📊 {$total} ربات | 🟢 {$active} فعال | 💰 سود کل: {$profitFormatted} IRR";
        } catch (\Exception $e) {
            return "📊 ربات‌های گرید معاملاتی";
        }
    }

    // ========== System Health Helpers ==========
    
    private function getSystemHealthBadge(): string
    {
        try {
            $activeBots = BotConfig::where('is_active', true)->count();
            
            if ($activeBots === 0) {
                return '😴'; // هیچ ربات فعالی نیست
            }
            
            // بررسی ربات‌های مشکل‌دار
            $problematicBots = BotConfig::where('is_active', true)
                ->where('updated_at', '<', now()->subMinutes(15))
                ->count();
            
            $healthPercentage = $activeBots > 0 ? 
                (($activeBots - $problematicBots) / $activeBots) * 100 : 0;
            
            if ($healthPercentage >= 90) return '💚'; // سالم
            if ($healthPercentage >= 70) return '💛'; // هشدار
            return '❤️‍🩹'; // مشکل
            
        } catch (\Exception $e) {
            return '❓'; // نامشخص
        }
    }
    
    private function getSystemHealthColor(): string
    {
        try {
            $activeBots = BotConfig::where('is_active', true)->count();
            
            if ($activeBots === 0) {
                return 'gray';
            }
            
            $problematicBots = BotConfig::where('is_active', true)
                ->where('updated_at', '<', now()->subMinutes(15))
                ->count();
            
            $healthPercentage = $activeBots > 0 ? 
                (($activeBots - $problematicBots) / $activeBots) * 100 : 0;
            
            if ($healthPercentage >= 90) return 'success';
            if ($healthPercentage >= 70) return 'warning';
            return 'danger';
            
        } catch (\Exception $e) {
            return 'gray';
        }
    }

    // ========== Query Optimization ==========
    
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->withCount([
                'completedTrades',
                'completedTrades as profitable_trades_count' => function ($query) {
                    $query->where('profit', '>', 0);
                },
                'activeGridRunOrders as active_orders_count'
            ])
            ->withSum('completedTrades', 'profit')
            ->with(['completedTrades' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->orderByDesc('is_active');
    }

    // ========== Health Monitoring ==========
    
    public function mount(): void
    {
        parent::mount();
        $this->checkActiveBotsHealth();
    }
    
    private function checkActiveBotsHealth(): void
    {
        try {
            // بررسی ربات‌های فعال که مدت زیادی آپدیت نشده‌اند
            $staleBotsCount = BotConfig::where('is_active', true)
                ->where('updated_at', '<', now()->subMinutes(30))
                ->count();
            
            if ($staleBotsCount > 0) {
                Notification::make()
                    ->title('⚠️ هشدار سیستم')
                    ->body("{$staleBotsCount} ربات فعال بیش از 30 دقیقه آپدیت نشده‌اند. لطفاً وضعیت آنها را بررسی کنید.")
                    ->warning()
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('health_check')
                            ->label('بررسی سلامت')
                            ->button()
                            ->action(fn () => $this->showSystemHealth()),
                    ])
                    ->persistent()
                    ->send();
            }
            
            // بررسی API نوبیتکس
            $this->checkNobitexConnection();
            
        } catch (\Exception $e) {
            Log::warning('خطا در بررسی سلامت ربات‌ها', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function checkNobitexConnection(): void
    {
        try {
            $nobitex = app(NobitexService::class);
            $health = $nobitex->healthCheck();
            
                    $overallStatus = $health['overall_status'] ?? 'unhealthy';
        if ($overallStatus !== 'healthy') {
                Notification::make()
                    ->title('🚨 مشکل اتصال')
                    ->body('اتصال به API نوبیتکس با مشکل مواجه است. ربات‌ها ممکن است درست کار نکنند.')
                    ->danger()
                    ->persistent()
                    ->send();
            }
        } catch (\Exception $e) {
            // خطای اتصال - اطلاع‌رسانی خاموش
        }
    }

    // ========== Real-time Updates ==========
    
    protected function getPollingInterval(): ?string
    {
        return '30s'; // بروزرسانی هر 30 ثانیه
    }

    // ========== Table Configuration ==========
    
    protected function getTableFiltersFormColumns(): int
    {
        return 3;
    }
    
    protected function getTableFiltersFormWidth(): string
    {
        return '3xl';
    }

    // ========== Custom Actions ==========
    
    protected function getTableBulkActions(): array
    {
        return [
            // عملیات دسته‌جمعی روی ربات‌های انتخاب شده
        ];
    }
}