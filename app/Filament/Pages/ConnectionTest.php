<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Exception;

class ConnectionTest extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wifi';
    
    protected static ?string $navigationLabel = 'آزمایش اتصال';
    
    protected static ?string $title = 'آزمایش اتصال API نوبیتکس';
    
    protected static ?string $navigationGroup = 'ابزارها';
    
    protected static ?int $navigationSort = 1;
    
    protected static string $view = 'filament.pages.connection-test';
    
    public $connectionStatus = null;
    public $lastChecked = null;
    public $responseTime = null;
    public $btcPrice = null;
    public $accountBalance = null;
    public $apiEndpoint = 'https://apiv2.nobitex.ir'; // 🔧 درست شد
    public $isLoading = false;
    public $simulationMode = true;

    public function mount(): void
    {
        // Load cached data and check simulation mode
        $this->simulationMode = config('trading.simulation_mode', true);
        $this->loadCachedData();
    }

    protected function getActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('تست اتصال')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->size('lg')
                ->action('performConnectionTest')
                ->keyBindings(['command+t', 'ctrl+t']),
                
            Action::make('testPriceApi')
                ->label('تست قیمت')
                ->icon('heroicon-o-currency-dollar')
                ->color('info')
                ->action('testPriceEndpoint'),
                
            Action::make('testBalanceApi')
                ->label('تست موجودی')
                ->icon('heroicon-o-wallet')
                ->color('warning')
                ->action('testBalanceEndpoint'),

            Action::make('testOrderbook')
                ->label('تست اردربوک')
                ->icon('heroicon-o-chart-bar')
                ->color('success')
                ->action('testOrderbookEndpoint'),
                
            Action::make('clearCache')
                ->label('پاک کردن کش')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action('clearConnectionCache'),

            Action::make('healthCheck')
                ->label('بررسی سلامت کامل')
                ->icon('heroicon-o-heart')
                ->color('info')
                ->action('performHealthCheck'),
        ];
    }

    public function performConnectionTest(): void
    {
        $this->isLoading = true;
        
        try {
            $startTime = microtime(true);
            
            if ($this->simulationMode) {
                // Simulation mode test
                sleep(1); // Simulate network delay
                $this->connectionStatus = 'success';
                $this->responseTime = rand(50, 200);
                $this->lastChecked = now()->format('Y-m-d H:i:s');
                
                Notification::make()
                    ->title('✅ اتصال موفق (شبیه‌سازی)')
                    ->body("زمان پاسخ: {$this->responseTime}ms - حالت تست")
                    ->success()
                    ->duration(3000)
                    ->send();
            } else {
                // Real API test using profile endpoint (doesn't need market data)
                $response = Http::withHeaders([
                    'Authorization' => 'Token ' . config('services.nobitex.api_key'),
                    'User-Agent' => 'TraderBot/GridBot_v1'
                ])->timeout(10)->get($this->apiEndpoint . '/users/profile');
                
                $endTime = microtime(true);
                $this->responseTime = round(($endTime - $startTime) * 1000, 2);
                
                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (isset($data['status']) && $data['status'] === 'ok') {
                        $this->connectionStatus = 'success';
                        $this->lastChecked = now()->format('Y-m-d H:i:s');
                        
                        // Cache the result
                        Cache::put('nobitex_connection_status', [
                            'status' => 'success',
                            'response_time' => $this->responseTime,
                            'checked_at' => $this->lastChecked,
                            'profile_data' => $data['profile'] ?? null
                        ], 300); // 5 minutes
                        
                        Notification::make()
                            ->title('✅ اتصال موفق')
                            ->body("زمان پاسخ: {$this->responseTime}ms - API کار می‌کند")
                            ->success()
                            ->duration(3000)
                            ->send();
                    } else {
                        throw new Exception('API returned status: ' . ($data['status'] ?? 'unknown'));
                    }
                } else {
                    throw new Exception('HTTP Status: ' . $response->status());
                }
            }
            
        } catch (Exception $e) {
            $this->connectionStatus = 'failed';
            $this->lastChecked = now()->format('Y-m-d H:i:s');
            $this->responseTime = null;
            
            Cache::put('nobitex_connection_status', [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => $this->lastChecked
            ], 60);
            
            Notification::make()
                ->title('❌ خطا در اتصال')
                ->body('خطا: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
        
        $this->isLoading = false;
    }

    public function testPriceEndpoint(): void
    {
        try {
            $nobitex = app(NobitexService::class);
            $price = $nobitex->getCurrentPrice('BTCIRT');
            
            $this->btcPrice = number_format($price, 0) . ' IRR';
            
            Cache::put('btc_current_price', $price, 300);
            
            $mode = $this->simulationMode ? ' (شبیه‌سازی)' : '';
            
            Notification::make()
                ->title('💰 قیمت بیت‌کوین' . $mode)
                ->body("قیمت فعلی: {$this->btcPrice}")
                ->info()
                ->send();
                
        } catch (Exception $e) {
            Notification::make()
                ->title('خطا در دریافت قیمت')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function testBalanceEndpoint(): void
    {
        try {
            $nobitex = app(NobitexService::class);
            $balance = $nobitex->getBalances();

            if ($this->simulationMode) {
                $this->accountBalance = [
                    'btc' => $balance['btc']['available'] ?? 0,
                    'irt' => number_format($balance['rls']['available'] ?? 0, 0)
                ];
            } else {
                $this->accountBalance = [
                    'btc' => $balance['btc']['available'] ?? 0,
                    'irt' => number_format($balance['rls']['available'] ?? 0, 0)
                ];
            }
            
            $mode = $this->simulationMode ? ' (شبیه‌سازی)' : '';
            
            Notification::make()
                ->title('💼 موجودی حساب' . $mode)
                ->body("BTC: {$this->accountBalance['btc']} | IRR: {$this->accountBalance['irt']}")
                ->success()
                ->send();
                
        } catch (Exception $e) {
            Notification::make()
                ->title('خطا در دریافت موجودی')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function testOrderbookEndpoint(): void
    {
        try {
            $nobitex = app(NobitexService::class);
            $orderbook = $nobitex->getOrderBook('BTCIRT');

            // OrderBookDto is an object, access properties directly
            $askCount = count($orderbook->asks);
            $bidCount = count($orderbook->bids);
            $lastPrice = number_format($orderbook->lastPrice, 0);

            $mode = $this->simulationMode ? ' (شبیه‌سازی)' : '';

            Notification::make()
                ->title('📊 اردربوک' . $mode)
                ->body("Asks: {$askCount} | Bids: {$bidCount} | آخرین قیمت: {$lastPrice} IRR")
                ->info()
                ->send();

        } catch (Exception $e) {
            Notification::make()
                ->title('خطا در دریافت اردربوک')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function performHealthCheck(): void
    {
        try {
            $nobitex = app(NobitexService::class);
            $healthData = $nobitex->healthCheck();
            
            $status = $healthData['status'] === 'ok' ? '✅ سالم' : '❌ مشکل';
            $mode = $healthData['mode'] === 'simulation' ? ' (شبیه‌سازی)' : ' (مستقیم)';
            $responseTime = $healthData['response_time'] ? round($healthData['response_time'] * 1000, 2) . 'ms' : 'نامشخص';
            
            Notification::make()
                ->title('🔍 بررسی سلامت کامل')
                ->body("وضعیت: {$status}{$mode} | زمان پاسخ: {$responseTime}")
                ->color($healthData['status'] === 'ok' ? 'success' : 'danger')
                ->send();
                
        } catch (Exception $e) {
            Notification::make()
                ->title('خطا در بررسی سلامت')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function clearConnectionCache(): void
    {
        Cache::forget('nobitex_connection_status');
        Cache::forget('btc_current_price');
        Cache::forget('price_BTCIRT');
        Cache::forget('nobitex_balance');
        
        $this->connectionStatus = null;
        $this->lastChecked = null;
        $this->responseTime = null;
        $this->btcPrice = null;
        $this->accountBalance = null;
        
        Notification::make()
            ->title('🗑️ کش پاک شد')
            ->body('تمام داده‌های کش شده حذف شدند')
            ->warning()
            ->send();
    }

    public function loadCachedData(): void
    {
        // Load connection status
        $cachedStatus = Cache::get('nobitex_connection_status');
        if ($cachedStatus) {
            $this->connectionStatus = $cachedStatus['status'];
            $this->lastChecked = $cachedStatus['checked_at'] ?? null;
            $this->responseTime = $cachedStatus['response_time'] ?? null;
        }
        
        // Load cached price
        $cachedPrice = Cache::get('btc_current_price');
        if ($cachedPrice) {
            $this->btcPrice = number_format($cachedPrice, 0) . ' IRR';
        }
    }

    public function getConnectionStatusColor(): string
    {
        return match($this->connectionStatus) {
            'success' => 'success',
            'failed' => 'danger',
            default => 'gray'
        };
    }

    public function getConnectionStatusIcon(): string
    {
        return match($this->connectionStatus) {
            'success' => 'heroicon-o-check-circle',
            'failed' => 'heroicon-o-x-circle',
            default => 'heroicon-o-question-mark-circle'
        };
    }

    public function getConnectionStatusText(): string
    {
        return match($this->connectionStatus) {
            'success' => 'متصل',
            'failed' => 'قطع',
            default => 'نامشخص'
        };
    }

    public function getApiEndpointInfo(): array
    {
        return [
            'base_url' => $this->apiEndpoint,
            'simulation_mode' => $this->simulationMode,
            'api_key_configured' => !empty(config('services.nobitex.api_key')),
            'available_endpoints' => [
                '/users/profile' => 'اطلاعات کاربر',
                '/v3/orderbook/BTCIRT' => 'اردربوک بیت‌کوین',
                '/users/wallets/balance' => 'موجودی کیف پول',
                '/market/stats' => 'آمار بازار'
            ]
        ];
    }
}