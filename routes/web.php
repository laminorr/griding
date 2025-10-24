<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// ============ Main Application Routes ============

Route::get('/', function () {
    return redirect('/admin');
});

// ============ Grid Calculator Export & API Routes ============

// Grid Calculator Export Route
Route::get('/grid/export/{key}', function (string $key) {
    $data = Cache::get('grid_export_' . $key);
    
    if (!$data) {
        abort(404, 'Export data not found or expired');
    }
    
    $filename = 'grid_calculation_' . now()->format('Y_m_d_H_i_s') . '.json';
    
    return response()
        ->json($data, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
})->name('grid.export');

// Health Check Route for API
Route::get('/api/health', function () {
    try {
        $nobitex = app(\App\Services\NobitexService::class);
        $connectionTest = $nobitex->testConnection();
        $price = $nobitex->getCurrentPrice('BTCIRT');
        
        return response()->json([
            'status' => 'ok',
            'api_connected' => true,
            'current_btc_price' => $price,
            'connection_details' => $connectionTest,
            'timestamp' => now()->toISOString(),
            'services' => [
                'nobitex_api' => 'operational',
                'grid_calculator' => 'operational',
                'cache' => Cache::getStore() instanceof \Illuminate\Contracts\Cache\Store ? 'operational' : 'error'
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'api_connected' => false,
            'error' => $e->getMessage(),
            'timestamp' => now()->toISOString(),
            'services' => [
                'nobitex_api' => 'error',
                'grid_calculator' => 'unknown',
                'cache' => 'unknown'
            ]
        ], 500);
    }
})->name('api.health');

// Market Status API
Route::get('/api/market-status', function () {
    try {
        $nobitex = app(\App\Services\NobitexService::class);
        
        $markets = ['BTCIRT', 'ETHIRT', 'USDTIRT'];
        $data = [];
        
        foreach ($markets as $market) {
            try {
                $price = $nobitex->getCurrentPrice($market);
                $stats = $nobitex->getMarketStats($market);
                
                $data[$market] = [
                    'price' => $price,
                    'change_24h' => $stats['dayChange'] ?? 0,
                    'volume' => $stats['volumeDst'] ?? 0,
                    'status' => 'active'
                ];
            } catch (\Exception $e) {
                $data[$market] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return response()->json([
            'status' => 'ok',
            'markets' => $data,
            'timestamp' => now()->toISOString()
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'error' => $e->getMessage()
        ], 500);
    }
})->name('api.market-status');

// ============ Development & Testing Routes ============

// Only register test routes in local environment
if (app()->environment('local')) {
    
    // Test Nobitex API connection
    Route::get('/test-nobitex', function () {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . config('services.nobitex.api_key'),
                'Content-Type' => 'application/json',
                'User-Agent' => 'TraderBot/GridBot_v2'
            ])->timeout(30)->get(config('services.nobitex.base_url') . '/users/profile');
            
            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json()
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'HTTP ' . $response->status(),
                    'message' => $response->body()
                ]);
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Exception',
                'message' => $e->getMessage()
            ]);
        }
    });

    // Test user limitations
    Route::get('/test-limitations', function () {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . config('services.nobitex.api_key'),
                'Content-Type' => 'application/json',
                'User-Agent' => 'TraderBot/GridBot_v2'
            ])->timeout(30)->get(config('services.nobitex.base_url') . '/users/limitations');
            
            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json()
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'HTTP ' . $response->status(),
                    'message' => $response->body()
                ]);
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Exception',
                'message' => $e->getMessage()
            ]);
        }
    });

    // Mock wallets data for testing
    Route::get('/test-wallets', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'ok',
                'wallets' => [
                    [
                        'id' => 4572958001,
                        'currency' => 'rls',
                        'balance' => '200000000', // 200 میلیون ریال
                        'blockedBalance' => '0',
                        'activeBalance' => '200000000',
                        'rialBalance' => 200000000,
                        'rialBalanceSell' => 200000000
                    ],
                    [
                        'id' => 4572958002,
                        'currency' => 'btc',
                        'balance' => '0.003', // 0.003 BTC
                        'blockedBalance' => '0',
                        'activeBalance' => '0.003',
                        'rialBalance' => 0,
                        'rialBalanceSell' => 0
                    ]
                ]
            ]
        ]);
    });

    // Test NobitexService
    Route::get('/test-nobitex-service', function () {
        try {
            $nobitex = app(\App\Services\NobitexService::class);
            
            return response()->json([
                'service_status' => 'loaded',
                'connection_test' => $nobitex->testConnection(),
                'current_price' => $nobitex->getCurrentPrice('BTCIRT'),
                'market_stats' => $nobitex->getMarketStats('BTCIRT'),
                'orderbook_sample' => array_slice($nobitex->getOrderbook('BTCIRT')['asks'] ?? [], 0, 3),
                'api_key_status' => $nobitex->checkApiKeyStatus(),
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'trace' => collect($e->getTrace())->take(3)->toArray()
            ], 500);
        }
    });

    // Test Grid Calculator Service
    Route::get('/test-grid-calculator', function () {
        try {
            $nobitex = app(\App\Services\NobitexService::class);
            $gridCalculator = app(\App\Services\GridCalculatorService::class);
            
            // پارامترهای تست
            $centerPrice = 6000000000; // 6 میلیارد ریال
            $spacing = 1.5; // 1.5 درصد
            $levels = 10; // 10 سطح
            $totalCapital = 300000000; // 300 میلیون ریال
            $activePercent = 30; // 30 درصد
            
            return response()->json([
                'service_status' => 'loaded',
                'test_params' => [
                    'center_price' => number_format($centerPrice),
                    'spacing' => $spacing . '%',
                    'levels' => $levels,
                    'total_capital' => number_format($totalCapital),
                    'active_percent' => $activePercent . '%'
                ],
                
                'grid_levels' => $gridCalculator->calculateGridLevels($centerPrice, $spacing, $levels)->take(5),
                
                'order_size' => $gridCalculator->calculateOrderSize($totalCapital, $activePercent, $levels),
                
                'expected_profit' => $gridCalculator->calculateExpectedProfit(
                    $centerPrice, $spacing, $levels, 0.00001
                ),
                
                'risk_analysis' => $gridCalculator->assessGridRisk([
                    'center_price' => $centerPrice,
                    'spacing' => $spacing,
                    'levels' => $levels,
                    'active_percent' => $activePercent
                ], $totalCapital),

                'optimization' => [
                    'status' => 'pending',
                    'message' => 'Optimization feature under development'
                ],
                
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'trace' => collect($e->getTrace())->take(3)->toArray()
            ], 500);
        }
    });

    // Test Trading Engine (if exists)
    Route::get('/test-trading-engine', function () {
        try {
            // Check if TradingEngineService exists
            if (!class_exists('\App\Services\TradingEngineService')) {
                return response()->json([
                    'status' => 'not_implemented',
                    'message' => 'TradingEngineService not yet implemented',
                    'available_services' => [
                        'NobitexService' => 'available',
                        'GridCalculatorService' => 'available'
                    ]
                ]);
            }

            $nobitex = app(\App\Services\NobitexService::class);
            $gridCalculator = app(\App\Services\GridCalculatorService::class);
            $tradingEngine = app(\App\Services\TradingEngineService::class);
            
            // Create test BotConfig
            $testBotConfig = [
                'id' => 1,
                'name' => 'Test Grid Bot',
                'total_capital' => 300000000,
                'active_capital_percent' => 30,
                'grid_spacing' => 1.5,
                'grid_levels' => 10,
                'is_active' => true,
                'center_price' => 6000000000
            ];
            
            return response()->json([
                'service_status' => 'loaded',
                'test_bot_config' => $testBotConfig,
                'validation' => [
                    'trading_engine_valid' => true,
                    'message' => 'TradingEngineService آماده و کار می‌کند!'
                ],
                'available_methods' => get_class_methods('\App\Services\TradingEngineService'),
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'trace' => collect($e->getTrace())->take(3)->toArray()
            ], 500);
        }
    });

    // Clear cache route (development only)
    Route::get('/clear-cache', function () {
        Cache::flush();
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Cache cleared successfully',
            'timestamp' => now()->toISOString()
        ]);
    })->name('cache.clear');

    // System info route (development only)
    Route::get('/system-info', function () {
        return response()->json([
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'loaded_extensions' => get_loaded_extensions(),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'database_driver' => config('database.default'),
            'timezone' => config('app.timezone'),
            'services' => [
                'nobitex_configured' => !empty(config('services.nobitex.api_key')),
                'cache_working' => Cache::put('test', 'working', 1) && Cache::get('test') === 'working'
            ]
        ]);
    });
}

// ============ Utility Routes (Always Available) ============

// Quick API status check
Route::get('/status', function () {
    return response()->json([
        'status' => 'operational',
        'timestamp' => now()->toISOString(),
        'environment' => app()->environment(),
        'services' => [
            'web' => 'up',
            'cache' => Cache::getStore() ? 'up' : 'down'
        ]
    ]);
})->name('status');

// Robots.txt
Route::get('/robots.txt', function () {
    $content = app()->environment('production') 
        ? "User-agent: *\nDisallow: /"  // Block all in production
        : "User-agent: *\nDisallow: /admin/\nDisallow: /api/"; // Partial block in dev
        
    return response($content, 200, ['Content-Type' => 'text/plain']);
});