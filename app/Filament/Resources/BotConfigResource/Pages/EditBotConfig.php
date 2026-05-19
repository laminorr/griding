<?php

namespace App\Filament\Resources\BotConfigResource\Pages;

use App\Filament\Resources\BotConfigResource;
use App\Services\GridCalculatorService;
use App\Services\NobitexService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditBotConfig extends EditRecord
{
    protected static string $resource = BotConfigResource::class;
    
    protected static ?string $title = 'ویرایش ربات گرید';
    
    protected static ?string $breadcrumb = 'ویرایش';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('ذخیره تغییرات')
            ->icon('heroicon-o-check-circle')
            ->color('success');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('انصراف')
            ->icon('heroicon-o-x-mark');
    }

    protected function getHeaderActions(): array
    {
        return [
            // 2. تحلیل ریسک
            Action::make('risk_analysis')
                ->label('تحلیل ریسک')
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning')
                ->modalHeading('تحلیل ریسک و امنیت')
                ->modalContent(function () {
                    try {
                        $calculator = app(GridCalculatorService::class);
                        $nobitex = app(NobitexService::class);

                        $currentPrice = $this->record->center_price ?? $nobitex->getCurrentPrice('BTCIRT');

                        $riskAnalysis = $calculator->assessGridRisk([
                            'center_price' => $currentPrice,
                            'spacing' => $this->record->grid_spacing,
                            'levels' => $this->record->grid_levels,
                            'active_percent' => $this->record->active_capital_percent
                        ], $this->record->total_capital);
                        
                        return view('filament.modals.risk-analysis', [
                            'record' => $this->record,
                            'analysis' => $riskAnalysis
                        ]);
                    } catch (\Exception $e) {
                        return view('filament.modals.error', [
                            'title' => 'خطا در تحلیل ریسک',
                            'message' => $e->getMessage()
                        ]);
                    }
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('بستن'),

            // 3. بررسی سلامت
            Action::make('health_check')
                ->label('بررسی سلامت')
                ->icon('heroicon-o-heart')
                ->color('success')
                ->action(function () {
                    try {
                        // بررسی اتصال نوبیتکس
                        $nobitex = app(NobitexService::class);
                        $healthCheck = $nobitex->healthCheck();
                        
                        // جمع‌آوری آمار ربات
                        $activeOrders = $this->record->gridOrders()
                            ->where('status', 'placed')
                            ->count();
                            
                        $healthScore = $this->calculateBotHealth();
                        $lastActivity = $this->record->updated_at->diffForHumans();
                        
                        // تعیین وضعیت‌ها
                        $connectionStatus = $healthCheck['status'] === 'ok' ? '✅ متصل' : '❌ قطع';
                        $botStatus = $this->record->is_active ? '🟢 فعال' : '🔴 غیرفعال';
                        $responseTime = round($healthCheck['response_time'] ?? 0, 2);
                        
                        // تشخیص مشکلات
                        $issues = [];
                        if ($healthCheck['status'] !== 'ok') {
                            $issues[] = '⚠️ مشکل در اتصال به نوبیتکس';
                        }
                        if ($this->record->is_active && $activeOrders === 0) {
                            $issues[] = '⚠️ ربات فعال اما سفارشی ندارد';
                        }
                        if ($healthScore < 70) {
                            $issues[] = '⚠️ امتیاز سلامت پایین است';
                        }
                        
                        $issuesText = empty($issues) ? '✅ مشکلی یافت نشد' : implode("\n", $issues);
                        
                        Notification::make()
                            ->title('🏥 گزارش سلامت سیستم')
                            ->body("
                                🌐 اتصال نوبیتکس: {$connectionStatus} ({$responseTime}s)
                                🤖 وضعیت ربات: {$botStatus}
                                📊 امتیاز سلامت: {$healthScore}/100
                                🔢 سفارشات فعال: {$activeOrders}
                                ⏱️ آخرین فعالیت: {$lastActivity}
                                
                                📋 وضعیت کلی:
                                {$issuesText}
                            ")
                            ->color($healthScore >= 80 ? 'success' : ($healthScore >= 60 ? 'warning' : 'danger'))
                            ->duration(10000)
                            ->send();
                            
                    } catch (\Exception $e) {
                        Log::error('خطا در بررسی سلامت ربات', [
                            'bot_id' => $this->record->id,
                            'error' => $e->getMessage()
                        ]);
                        
                        Notification::make()
                            ->title('❌ خطا در بررسی سلامت')
                            ->body('نتوانستیم وضعیت سیستم را بررسی کنیم: ' . $e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // بررسی محدودیت‌های ربات فعال
        if ($this->record->is_active) {
            $restrictedFields = [
                'total_capital' => 'مقدار سرمایه',
                'grid_levels' => 'تعداد سطوح گرید',
                'grid_spacing' => 'فاصله گرید'
            ];
            
            foreach ($restrictedFields as $field => $label) {
                if (isset($data[$field]) && $data[$field] != $this->record->{$field}) {
                    Notification::make()
                        ->title('⚠️ تغییر غیرمجاز')
                        ->body("نمی‌توانید '{$label}' را در حالت فعال تغییر دهید. ابتدا ربات را متوقف کنید.")
                        ->warning()
                        ->persistent()
                        ->send();
                    
                    // بازگردانی مقدار قبلی
                    $data[$field] = $this->record->{$field};
                }
            }
        }
        
        // اعتبارسنجی داده‌ها
        $this->validateFormData($data);
        
        // ثبت تغییرات در لاگ
        $this->logConfigurationChanges($data);
        
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            DB::beginTransaction();
            
            // بروزرسانی رکورد
            $record = parent::handleRecordUpdate($record, $data);
            
            // تنظیم قیمت مرکز اگر موجود نباشد
            if (!$record->center_price) {
                $currentPrice = app(NobitexService::class)->getCurrentPrice('BTCIRT');
                $record->update(['center_price' => $currentPrice]);

                Log::info('قیمت مرکز تنظیم شد', [
                    'bot_id' => $record->id,
                    'center_price' => $currentPrice
                ]);
            }
            
            DB::commit();
            
            return $record;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('خطا در بروزرسانی تنظیمات ربات', [
                'bot_id' => $record->id,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            throw $e;
        }
    }

    public function getSubheading(): ?string
    {
        $status = $this->record->is_active ? '🟢 فعال' : '🔴 غیرفعال';
        $lastUpdate = $this->record->updated_at->diffForHumans();
        
        $summary = "وضعیت: {$status} | آخرین بروزرسانی: {$lastUpdate}";
        
        // اضافه کردن اطلاعات اضافی اگر ربات فعال باشد
        if ($this->record->is_active) {
            $activeOrders = $this->record->gridOrders()
                ->where('status', 'placed')
                ->count();
            $summary .= " | سفارشات فعال: {$activeOrders}";
        }
        
        return $summary;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title('✅ تنظیمات ذخیره شد')
            ->body('تغییرات ربات گرید با موفقیت اعمال شد.')
            ->success();
    }

    // ========== Helper Methods ==========
    
    /**
     * محاسبه امتیاز سلامت ربات
     */
    private function calculateBotHealth(): int
    {
        $score = 100;
        
        // کسر امتیاز برای مشکلات
        if (!$this->record->is_active) {
            $score -= 10; // ربات غیرفعال
        }
        
        // محاسبه سود کل
        $totalProfit = $this->record->completedTrades()
            ->selectRaw('SUM(profit - fee) as net_profit')
            ->value('net_profit') ?? 0;
        
        if ($totalProfit < 0) {
            $score -= 20; // ضرر کلی
        }
        
        // محاسبه نرخ موفقیت
        $totalTrades = $this->record->completedTrades()->count();
        $winRate = 0;
        
        if ($totalTrades > 0) {
            $winningTrades = $this->record->completedTrades()
                ->where('profit', '>', 0)
                ->count();
            $winRate = ($winningTrades / $totalTrades) * 100;
        }
        
        if ($winRate < 50) {
            $score -= 15; // نرخ برد پایین
        }
        
        // بررسی سفارشات فعال
        $activeOrders = $this->record->gridOrders()
            ->where('status', 'placed')
            ->count();
            
        if ($this->record->is_active && $activeOrders === 0) {
            $score -= 25; // ربات فعال بدون سفارش
        }
        
        // اضافه امتیاز برای عملکرد خوب
        if ($winRate > 80) {
            $score += 10;
        }
        
        if ($totalProfit > 0) {
            $score += 5;
        }
        
        // محدود کردن امتیاز بین 0 تا 100
        return max(0, min(100, $score));
    }
    
    /**
     * اعتبارسنجی داده‌های فرم
     */
    private function validateFormData(array $data): void
    {
        // بررسی حداقل سرمایه
        if (isset($data['total_capital']) && $data['total_capital'] < 50000000) {
            throw new \InvalidArgumentException('حداقل سرمایه مورد نیاز 50 میلیون ریال است.');
        }
        
        // بررسی تعداد سطوح گرید
        if (isset($data['grid_levels'])) {
            if ($data['grid_levels'] < 4 || $data['grid_levels'] > 20) {
                throw new \InvalidArgumentException('تعداد سطوح گرید باید بین 4 تا 20 باشد.');
            }
        }
        
        // بررسی فاصله گرید
        if (isset($data['grid_spacing'])) {
            if ($data['grid_spacing'] < 0.5 || $data['grid_spacing'] > 10) {
                throw new \InvalidArgumentException('فاصله گرید باید بین 0.5% تا 10% باشد.');
            }
        }
        
        // بررسی درصد سرمایه فعال
        if (isset($data['active_capital_percent'])) {
            if ($data['active_capital_percent'] < 10 || $data['active_capital_percent'] > 100) {
                throw new \InvalidArgumentException('درصد سرمایه فعال باید بین 10% تا 100% باشد.');
            }
        }
    }
    
    /**
     * ثبت تغییرات در لاگ
     */
    private function logConfigurationChanges(array $data): void
    {
        $changes = [];
        
        foreach ($data as $key => $newValue) {
            $oldValue = $this->record->{$key};
            
            if ($oldValue != $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }
        
        if (!empty($changes)) {
            Log::info('تغییرات پیکربندی ربات', [
                'bot_id' => $this->record->id,
                'bot_name' => $this->record->name,
                'user_id' => auth()->id(),
                'changes' => $changes,
                'timestamp' => now()
            ]);
        }
    }
}