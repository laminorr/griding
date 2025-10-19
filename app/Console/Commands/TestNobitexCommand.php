<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Log;

class TestNobitexCommand extends Command
{
    protected $signature = 'nobitex:test {--detailed : Show detailed information} {--market=BTCIRT : Market to test}';
    protected $description = 'Test Nobitex API connection and functionality';

    private NobitexService $nobitexService;

    public function __construct(NobitexService $nobitexService)
    {
        parent::__construct();
        $this->nobitexService = $nobitexService;
    }

    public function handle(): int
    {
        $this->info('🚀 Testing Nobitex API Connection...');
        $this->newLine();

        $startTime = microtime(true);

        // Test 1: Basic Connection
        $this->testBasicConnection();

        // Test 2: Price Data
        $this->testPriceData();

        // Test 3: Market Stats
        $this->testMarketStats();

        // Test 4: Orderbook
        $this->testOrderbook();

        // Test 5: User Profile (if API key provided)
        $this->testUserProfile();

        // Test 6: Balance (if API key provided)
        $this->testBalance();

        // Test 7: API Key Status
        $this->testApiKeyStatus();

        // Test 8: Performance Test
        $this->testPerformance();

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->newLine();
        $this->info("✅ All tests completed in {$totalTime}ms!");
        $this->newLine();

        return Command::SUCCESS;
    }

    private function testBasicConnection(): void
    {
        $this->info('🔌 Testing basic connection...');

        try {
            $result = $this->nobitexService->testConnection();
            
            if (isset($result['status']) && $result['status'] === 'ok') {
                $this->line('   ✅ Connection successful');
                
                if ($this->option('detailed') && isset($result['public_api']['response_time_ms'])) {
                    $this->line("   ⏱️  Response time: {$result['public_api']['response_time_ms']}ms");
                }
            } else {
                $this->error('   ❌ Connection failed');
                if (isset($result['error'])) {
                    $this->error('   🔍 Error: ' . $result['error']);
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Connection error: ' . $e->getMessage());
        }
    }

    private function testPriceData(): void
    {
        $this->info('💰 Testing price data...');

        try {
            $market = $this->option('market');
            $price = $this->nobitexService->getCurrentPrice($market);
            
            $formattedPrice = $this->formatPrice($price, $market);
            $this->line("   ✅ {$market} Price: {$formattedPrice}");
            
            if ($this->option('detailed')) {
                // Test multiple markets
                $markets = ['BTCIRT', 'ETHIRT', 'USDTIRT', 'BTCUSDT', 'ETHUSDT'];
                $this->line('   📊 Testing multiple markets:');
                
                foreach ($markets as $testMarket) {
                    try {
                        $marketPrice = $this->nobitexService->getCurrentPrice($testMarket);
                        $formatted = $this->formatPrice($marketPrice, $testMarket);
                        $this->line("      📈 {$testMarket}: {$formatted}");
                    } catch (\Exception $e) {
                        $this->warn("      ⚠️  {$testMarket}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Price data error: ' . $e->getMessage());
        }
    }

    private function testMarketStats(): void
    {
        $this->info('📊 Testing market statistics...');

        try {
            $market = $this->option('market');
            $stats = $this->nobitexService->getMarketStats($market);
            
            if (isset($stats['latest'])) {
                $this->line('   ✅ Market stats retrieved');
                
                if ($this->option('detailed')) {
                    $this->table(
                        ['Metric', 'Value'],
                        [
                            ['Symbol', $stats['symbol'] ?? $market],
                            ['Latest Price', $this->formatPrice($stats['latest'], $market)],
                            ['24h Change', $stats['dayChange'] . '%'],
                            ['24h High', $this->formatPrice($stats['dayHigh'], $market)],
                            ['24h Low', $this->formatPrice($stats['dayLow'], $market)],
                            ['Best Ask', $this->formatPrice($stats['bestSell'], $market)],
                            ['Best Bid', $this->formatPrice($stats['bestBuy'], $market)],
                            ['Spread', number_format($stats['spread'] ?? 0)],
                            ['Volume (Source)', number_format($stats['volumeSrc'] ?? 0, 4)],
                            ['Volume (Destination)', number_format($stats['volumeDst'] ?? 0, 0)],
                            ['Market Status', ($stats['isClosed'] ?? false) ? 'Closed' : 'Open'],
                            ['Volatility', $stats['volatility'] ?? 'Unknown'],
                        ]
                    );
                }
            } else {
                $this->warn('   ⚠️  Market stats retrieved but incomplete');
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Market stats error: ' . $e->getMessage());
        }
    }

    private function testOrderbook(): void
    {
        $this->info('📖 Testing orderbook data...');

        try {
            $market = $this->option('market');
            $orderbook = $this->nobitexService->getOrderbook($market, 10);
            
            $askCount = count($orderbook['asks'] ?? []);
            $bidCount = count($orderbook['bids'] ?? []);
            
            $this->line("   ✅ Orderbook retrieved: {$askCount} asks, {$bidCount} bids");
            
            if ($this->option('detailed') && $askCount > 0 && $bidCount > 0) {
                $this->line("   📈 Best Ask: " . $this->formatPrice($orderbook['asks'][0][0], $market));
                $this->line("   📉 Best Bid: " . $this->formatPrice($orderbook['bids'][0][0], $market));
                
                if (isset($orderbook['lastUpdate']) && $orderbook['lastUpdate'] > 0) {
                    $updateTime = date('Y-m-d H:i:s', intval($orderbook['lastUpdate'] / 1000));
                    $this->line("   🕐 Last Update: {$updateTime}");
                }

                if (isset($orderbook['analysis'])) {
                    $analysis = $orderbook['analysis'];
                    $this->line("   💧 Liquidity Score: " . ($analysis['liquidity_score'] ?? 'N/A'));
                    $this->line("   🎯 Market Condition: " . ($analysis['market_condition'] ?? 'Unknown'));
                }

                // Show top 3 levels
                $this->line('   📊 Top 3 Ask Levels:');
                for ($i = 0; $i < min(3, $askCount); $i++) {
                    $price = $this->formatPrice($orderbook['asks'][$i][0], $market);
                    $amount = number_format($orderbook['asks'][$i][1], 6);
                    $this->line("      {$price} x {$amount}");
                }

                $this->line('   📊 Top 3 Bid Levels:');
                for ($i = 0; $i < min(3, $bidCount); $i++) {
                    $price = $this->formatPrice($orderbook['bids'][$i][0], $market);
                    $amount = number_format($orderbook['bids'][$i][1], 6);
                    $this->line("      {$price} x {$amount}");
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Orderbook error: ' . $e->getMessage());
        }
    }

    private function testUserProfile(): void
    {
        $this->info('👤 Testing user profile...');

        $apiKey = config('services.nobitex.api_key');
        
        if (empty($apiKey) || $apiKey === 'your_nobitex_api_token_here') {
            $this->warn('   ⚠️  No valid API key configured - skipping user tests');
            $this->line('   💡 Set NOBITEX_API_KEY in your .env file to test private APIs');
            return;
        }

        try {
            $profile = $this->nobitexService->getUserProfile();
            
            if (isset($profile['profile'])) {
                $this->line('   ✅ Profile retrieved successfully');
                
                if ($this->option('detailed')) {
                    $p = $profile['profile'];
                    $verifications = $p['verifications'] ?? [];
                    
                    $this->table(
                        ['Field', 'Value'],
                        [
                            ['Email', $p['email'] ?? 'N/A'],
                            ['Username', $p['username'] ?? 'N/A'],
                            ['Phone', $p['mobile'] ?? 'N/A'],
                            ['Identity Verified', ($verifications['identity'] ?? false) ? '✅ Yes' : '❌ No'],
                            ['Phone Verified', ($verifications['phone'] ?? false) ? '✅ Yes' : '❌ No'],
                            ['Email Verified', ($verifications['email'] ?? false) ? '✅ Yes' : '❌ No'],
                            ['Bank Account Verified', ($verifications['bankAccount'] ?? false) ? '✅ Yes' : '❌ No'],
                            ['Trading Enabled', ($verifications['identity'] ?? false) ? '✅ Yes' : '❌ No'],
                            ['Withdraw Eligible', ($p['withdrawEligible'] ?? false) ? '✅ Yes' : '❌ No'],
                        ]
                    );

                    // Show trading stats if available
                    if (isset($profile['tradeStats'])) {
                        $stats = $profile['tradeStats'];
                        $this->line('   📈 Trading Statistics:');
                        $this->line("      Monthly Volume: " . number_format($stats['monthTradesTotal'] ?? 0) . " IRT");
                        $this->line("      Monthly Trades: " . ($stats['monthTradesCount'] ?? 0));
                    }
                }
            } else {
                $this->error('   ❌ Failed to retrieve profile');
                if (isset($profile['error'])) {
                    $this->error('   🔍 Error: ' . $profile['error']);
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Profile error: ' . $e->getMessage());
        }
    }

    private function testBalance(): void
    {
        $this->info('💰 Testing balance data...');

        $apiKey = config('services.nobitex.api_key');
        
        if (empty($apiKey) || $apiKey === 'your_nobitex_api_token_here') {
            $this->warn('   ⚠️  No valid API key configured - skipping balance test');
            return;
        }

        try {
            // Test IRT balance
            $irtBalance = $this->nobitexService->getBalance('rls');
            $this->line('   ✅ IRT Balance: ' . number_format($irtBalance) . ' ریال');

            if ($this->option('detailed')) {
                // Test other currencies
                $currencies = ['btc', 'eth', 'usdt', 'ltc'];
                $this->line('   📊 Other balances:');
                
                foreach ($currencies as $currency) {
                    try {
                        $balance = $this->nobitexService->getBalance($currency);
                        if ($balance > 0) {
                            $this->line("      📈 " . strtoupper($currency) . ": " . number_format($balance, 8));
                        } else {
                            $this->line("      📉 " . strtoupper($currency) . ": 0");
                        }
                    } catch (\Exception $e) {
                        $this->warn("      ⚠️  " . strtoupper($currency) . ": " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Balance error: ' . $e->getMessage());
        }
    }

    private function testApiKeyStatus(): void
    {
        $this->info('🔑 Testing API key status...');

        try {
            $status = $this->nobitexService->checkApiKeyStatus();
            
            if ($status['valid']) {
                $this->line('   ✅ API key is valid');
                
                if ($this->option('detailed')) {
                    $features = $status['features'];
                    $this->table(
                        ['Feature', 'Available'],
                        [
                            ['Public Data', $features['public_data'] ? '✅ Yes' : '❌ No'],
                            ['Private Data', $features['private_data'] ? '✅ Yes' : '❌ No'],
                            ['Trading', $features['trading'] ? '✅ Yes' : '❌ No'],
                        ]
                    );

                    if (isset($status['user_info'])) {
                        $this->line('   👤 User: ' . ($status['user_info']['email'] ?? 'Unknown'));
                    }
                }
            } else {
                $this->warn('   ⚠️  API key issues detected');
                if (isset($status['error'])) {
                    $this->error('   🔍 Error: ' . $status['error']);
                }
            }
        } catch (\Exception $e) {
            $this->error('   ❌ API key test error: ' . $e->getMessage());
        }
    }

    private function testPerformance(): void
    {
        if (!$this->option('detailed')) {
            return;
        }

        $this->info('⚡ Testing API performance...');

        $tests = [
            'getCurrentPrice' => fn() => $this->nobitexService->getCurrentPrice('BTCIRT'),
            'getMarketStats' => fn() => $this->nobitexService->getMarketStats('BTCIRT'),
            'getOrderbook' => fn() => $this->nobitexService->getOrderbook('BTCIRT', 5),
        ];

        $results = [];

        foreach ($tests as $testName => $testFunction) {
            $times = [];
            
            // Run each test 3 times
            for ($i = 0; $i < 3; $i++) {
                $start = microtime(true);
                try {
                    $testFunction();
                    $times[] = (microtime(true) - $start) * 1000; // Convert to ms
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  {$testName} failed: " . $e->getMessage());
                    continue 2; // Skip to next test
                }
            }

            if (!empty($times)) {
                $avgTime = round(array_sum($times) / count($times), 2);
                $results[] = [$testName, "{$avgTime}ms"];
            }
        }

        if (!empty($results)) {
            $this->table(['API Method', 'Average Response Time'], $results);
        }
    }

    private function formatPrice(float $price, string $market): string
    {
        if (str_contains($market, 'IRT')) {
            return number_format($price) . ' ریال';
        } elseif (str_contains($market, 'USDT')) {
            return number_format($price, 2) . ' USDT';
        } else {
            return number_format($price, 8);
        }
    }
}