<?php

namespace App\Filament\Resources\BotConfigResource\Pages;

use App\Filament\Resources\BotConfigResource;
use App\Services\GridCalculatorService;
use App\Services\NobitexService;
use App\Services\TradingEngineService;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CreateBotConfig extends CreateRecord
{
    protected static string $resource = BotConfigResource::class;
    
    protected static ?string $title = 'ایجاد ربات گرید جدید';
    
    protected static ?string $breadcrumb = 'ایجاد ربات';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('ایجاد ربات')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->size('lg');
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
            Action::make('test_connection')
                ->label('تست اتصال نوبیتکس')
                ->icon('heroicon-o-wifi')
                ->color('info')
                ->action(function () {
                    try {
                        $nobitex = app(NobitexService::class);
                        $health = $nobitex->healthCheck();
                        
                        if ($health['status'] === 'ok') {
                            Notification::make()
                                ->title('اتصال موفق')
                                ->body("زمان پاسخ: {$health['response_time']}s")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('خطا در اتصال')
                                ->body($health['message'])
                                ->warning()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('خطای اتصال')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            
            Action::make('get_market_price')
                ->label('قیمت فعلی BTC')
                ->icon('heroicon-o-currency-dollar')
                ->color('warning')
                ->action(function () {
                    try {
                        $nobitex = app(NobitexService::class);
                        $price = $nobitex->getCurrentPrice('BTCIRT');
                        $formattedPrice = number_format($price, 0);
                        
                        Notification::make()
                            ->title('قیمت فعلی BTC')
                            ->body("{$formattedPrice} تومان")
                            ->info()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('خطا در دریافت قیمت')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // تنظیم مقادیر پیش‌فرض
        $data['center_price'] = $data['center_price'] ?? $this->getCurrentBTCPrice();
        $data['is_active'] = false; // همیشه غیرفعال شروع می‌شه
        
        // اعتبارسنجی تنظیمات
        $this->validateBotConfiguration($data);
        
        // محاسبه و ذخیره اطلاعات اضافی
        $data = $this->enrichFormData($data);
        
        Log::info('ایجاد ربات جدید', [
            'name' => $data['name'],
            'total_capital' => $data['total_capital'],
            'grid_levels' => $data['grid_levels']
        ]);
        
        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            DB::beginTransaction();
            
            // ایجاد رکورد
            $record = parent::handleRecordCreation($data);
            
            // بررسی امکان ایجاد (محدودیت‌های کاربر)
            $this->checkUserLimits($record);
            
            // تنظیم اولیه (بدون فعال‌سازی)
            $this->prepareInitialSetup($record);
            
            DB::commit();
            
            // ارسال اعلان موفقیت
            $this->sendSuccessNotification($record);
            
            return $record;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('خطا در ایجاد ربات', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            Notification::make()
                ->title('خطا در ایجاد ربات')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
            
            throw $e;
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null; // استفاده از اعلان سفارشی
    }

    // ========== Helper Methods ==========
    
    private function getCurrentBTCPrice(): float
    {
        try {
            $nobitex = app(NobitexService::class);
            return $nobitex->getCurrentPrice('BTCIRT');
        } catch (\Exception $e) {
            Log::warning('خطا در دریافت قیمت BTC', ['error' => $e->getMessage()]);
            return Cache::get('btc_price_backup', 2100000000); // قیمت پیش‌فرض
        }
    }

    private function validateBotConfiguration(array $data): void
    {
        // بررسی سرمایه
        if ($data['total_capital'] < 100) {
            throw new \InvalidArgumentException('حداقل سرمایه 100 دلار است');
        }
        
        if ($data['total_capital'] > 100000) {
            throw new \InvalidArgumentException('حداکثر سرمایه 100,000 دلار است');
        }
        
        // بررسی تنظیمات گرید
        if ($data['grid_levels'] < 4 || $data['grid_levels'] > 20) {
            throw new \InvalidArgumentException('تعداد سطوح باید بین 4 تا 20 باشد');
        }
        
        if ($data['grid_levels'] % 2 !== 0) {
            throw new \InvalidArgumentException('تعداد سطوح باید زوج باشد');
        }
        
        if ($data['grid_spacing'] < 0.5 || $data['grid_spacing'] > 10) {
            throw new \InvalidArgumentException('فاصله گرید باید بین 0.5 تا 10 درصد باشد');
        }
        
        // بررسی اندازه سفارش
        try {
            $calculator = app(GridCalculatorService::class);
            $orderSizeResult = $calculator->calculateOrderSize(
                $data['total_capital'],
                $data['active_capital_percent'],
                $data['grid_levels']
            );
            
            if (!$orderSizeResult['is_valid']) {
                throw new \InvalidArgumentException('اندازه سفارش کمتر از حداقل مجاز: ' . implode(', ', $orderSizeResult['warnings']));
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('خطا در محاسبه اندازه سفارش: ' . $e->getMessage());
        }
    }

    private function enrichFormData(array $data): array
    {
        try {
            $calculator = app(GridCalculatorService::class);
            
            // محاسبه سود مورد انتظار
            $orderSizeResult = $calculator->calculateOrderSize(
                $data['total_capital'],
                $data['active_capital_percent'],
                $data['grid_levels']
            );
            
            $expectedProfit = $calculator->calculateExpectedProfit(
                $data['center_price'],
                $data['grid_spacing'],
                $data['grid_levels'],
                $orderSizeResult['btc_amount']
            );
            
            // تحلیل ریسک
            $riskAnalysis = $calculator->analyzeGridRisk(
                $data['center_price'],
                $data['grid_spacing'],
                $data['grid_levels'],
                $data['total_capital']
            );
            
            // ذخیره اطلاعات تکمیلی
            $data['expected_daily_profit'] = $expectedProfit['avg_profit_per_cycle'];
            $data['max_drawdown_calculated'] = $riskAnalysis['max_drawdown_percent'];
            $data['risk_level'] = $riskAnalysis['risk_level'];
            
        } catch (\Exception $e) {
            Log::warning('خطا در محاسبه اطلاعات تکمیلی', ['error' => $e->getMessage()]);
        }
        
        return $data;
    }

    private function checkUserLimits(Model $record): void
    {
        $user = auth()->user();
        
        // بررسی تعداد ربات‌های همزمان
        if (!$user->canCreateBot()) {
            throw new \InvalidArgumentException('شما به حداکثر تعداد ربات‌های مجاز رسیده‌اید');
        }
        
        // بررسی محدودیت سرمایه
        if (!$user->canUseCapital($record->total_capital)) {
            $remaining = $user->getRemainingCapitalLimit();
            throw new \InvalidArgumentException("محدودیت سرمایه. باقی‌مانده: $" . number_format($remaining, 0));
        }
    }

    private function prepareInitialSetup(Model $record): void
    {
        try {
            // تنظیم قیمت مرکز گرید
            $record->update([
                'center_price' => $this->getCurrentBTCPrice(),
                'created_by' => auth()->id(),
                'last_health_check' => now()
            ]);
            
            // ایجاد لاگ اولیه
            Log::info('ربات جدید آماده شد', [
                'bot_id' => $record->id,
                'name' => $record->name,
                'user_id' => auth()->id()
            ]);
            
        } catch (\Exception $e) {
            Log::error('خطا در تنظیم اولیه ربات', [
                'bot_id' => $record->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sendSuccessNotification(Model $record): void
    {
        $calculator = app(GridCalculatorService::class);
        
        try {
            $orderSizeResult = $calculator->calculateOrderSize(
                $record->total_capital,
                $record->active_capital_percent,
                $record->grid_levels
            );
            
            $activeCapital = ($record->total_capital * $record->active_capital_percent) / 100;
            $activeCapitalToman = $activeCapital * 42000;
            
            Notification::make()
                ->title('🎉 ربات با موفقیت ایجاد شد!')
                ->body("
                    📊 **{$record->name}** آماده است
                    💰 سرمایه فعال: $" . number_format($activeCapital, 0) . " (" . number_format($activeCapitalToman, 0) . " تومان)
                    📏 {$record->grid_levels} سطح با فاصله {$record->grid_spacing}%
                    🔹 اندازه هر سفارش: " . number_format($orderSizeResult['btc_amount'], 8) . " BTC
                    
                    💡 برای شروع معامله، ربات را فعال کنید
                ")
                ->success()
                ->persistent()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('activate')
                        ->label('فعال‌سازی فوری')
                        ->button()
                        ->url(BotConfigResource::getUrl('edit', ['record' => $record]))
                        ->openUrlInNewTab(false),
                    
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('مشاهده جزئیات')
                        ->url(BotConfigResource::getUrl('view', ['record' => $record]))
                        ->openUrlInNewTab(false),
                ])
                ->send();
                
        } catch (\Exception $e) {
            // اعلان ساده در صورت خطا
            Notification::make()
                ->title('ربات ایجاد شد')
                ->body("'{$record->name}' با موفقیت ایجاد شد و آماده فعال‌سازی است")
                ->success()
                ->send();
        }
    }

    // ========== Page Customization ==========
    
    protected function getHeaderWidgets(): array
    {
        return [
            // می‌توان ویجت‌های مربوط به آمار یا راهنمایی اضافه کرد
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            // می‌توان ویجت‌های راهنما یا نکات اضافه کرد
        ];
    }

    public function getSubheading(): ?string
    {
        return '🤖 ربات جدید خود را با تنظیمات دلخواه ایجاد کنید. توصیه می‌شود ابتدا با سرمایه کم شروع کنید.';
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('create_and_start')
                ->label('ایجاد و شروع فوری')
                ->icon('heroicon-o-rocket-launch')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('ایجاد و شروع فوری ربات')
                ->modalDescription('آیا مطمئن هستید که می‌خواهید ربات را ایجاد کرده و فوراً شروع کنید؟')
                ->modalSubmitActionLabel('ایجاد و شروع')
                ->action(function () {
                    // دریافت داده‌های فرم
                    $data = $this->form->getState();
                    
                    // ایجاد ربات
                    $record = $this->handleRecordCreation($data);
                    
                    // شروع فوری
                    try {
                        $tradingEngine = app(TradingEngineService::class);
                        $result = $tradingEngine->initializeGrid($record);
                        
                        if ($result['success']) {
                            $record->start();
                            
                            Notification::make()
                                ->title('🚀 ربات ایجاد و شروع شد!')
                                ->body("گرید با {$result['stats']['successful']} سفارش فعال شد")
                                ->success()
                                ->persistent()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('ربات ایجاد شد اما شروع نشد')
                                ->body($result['message'])
                                ->warning()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('خطا در شروع ربات')
                            ->body('ربات ایجاد شد اما نتوانست شروع شود: ' . $e->getMessage())
                            ->warning()
                            ->send();
                    }
                    
                    // انتقال به صفحه لیست
                    $this->redirect($this->getRedirectUrl());
                }),
            
            $this->getCancelFormAction(),
        ];
    }

    // ========== Real-time Validation ==========
    
    protected function getFormSchema(): array
    {
        return BotConfigResource::form($this->makeForm())->getSchema();
    }
}