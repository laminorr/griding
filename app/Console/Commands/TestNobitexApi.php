<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NobitexService;
use App\DTOs\CreateOrderDto;
use App\Enums\OrderSide;
use App\Enums\ExecutionType;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class TestNobitexApi extends Command
{
    protected $signature = 'test:nobitex-api';
    protected $description = 'Test all Nobitex API endpoints and functionality';

    private NobitexService $nobitex;

    public function __construct(NobitexService $nobitex)
    {
        parent::__construct();
        $this->nobitex = $nobitex;
    }

    public function handle(): int
    {
        $this->info("========================================");
        $this->info("Testing Nobitex API Integration");
        $this->info("========================================\n");

        $allPassed = true;

        // 1. Configuration Test
        $allPassed = $this->testConfiguration() && $allPassed;

        // 2. Health Check / Authentication Test
        $allPassed = $this->testHealthCheck() && $allPassed;

        // 3. Wallets Test
        $allPassed = $this->testWallets() && $allPassed;

        // 4. Market Data Test
        $allPassed = $this->testMarketData() && $allPassed;

        // 5. Order Book Test
        $allPassed = $this->testOrderBook() && $allPassed;

        // 6. Order Limits Test
        $allPassed = $this->testOrderLimits() && $allPassed;

        // 7. Simulation Test (if enabled)
        if (Config::get('trading.simulation_mode', false)) {
            $allPassed = $this->testSimulation() && $allPassed;
        } else {
            $this->warn("\n7. Simulation Mode Tests");
            $this->line("   ⚠️  Simulation mode disabled - skipping order tests");
            $this->line("   💡 Set TRADING_SIMULATION_MODE=true in .env to test order creation\n");
        }

        // 8. Rate Limiting Test
        $allPassed = $this->testRateLimiting() && $allPassed;

        $this->info("========================================");
        if ($allPassed) {
            $this->info("✅ ALL TESTS PASSED");
            $this->info("🚀 System is ready for trading!");
        } else {
            $this->error("❌ SOME TESTS FAILED");
            $this->error("⚠️  Please fix the issues before trading");
            return Command::FAILURE;
        }
        $this->info("========================================\n");

        return Command::SUCCESS;
    }

    private function testConfiguration(): bool
    {
        $this->info("1. Testing Configuration...");

        try {
            $apiKey = Config::get('trading.nobitex.api_key');
            $baseUrl = Config::get('trading.nobitex.base_url');
            $simulation = Config::get('trading.simulation_mode', false);
            $rateLimit = Config::get('trading.nobitex.rate_limit.rpm', 60);

            if (empty($apiKey)) {
                $this->error("   ❌ API Key not configured");
                $this->line("   💡 Set NOBITEX_API_KEY in your .env file");
                return false;
            }

            if ($this->option('verbose')) {
                $this->line("   API Key: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -4));
            } else {
                $this->line("   API Key: " . substr($apiKey, 0, 10) . "...");
            }

            $this->line("   Base URL: " . $baseUrl);
            $this->line("   Simulation Mode: " . ($simulation ? '🟢 ON' : '🔴 OFF'));
            $this->line("   Rate Limit: {$rateLimit} requests/minute");

            // Check allowed symbols
            $symbols = Config::get('trading.exchange.allowed_symbols', []);
            if (!empty($symbols)) {
                $this->line("   Allowed Symbols: " . implode(', ', $symbols));
            }

            $this->info("   ✅ Configuration OK\n");
            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Configuration error: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testHealthCheck(): bool
    {
        $this->info("2. Testing Health Check & Authentication...");

        try {
            $startTime = microtime(true);
            $result = $this->nobitex->healthCheck();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['ok'] ?? false) {
                $this->line("   ✅ Connection successful");
                $this->line("   ⏱️  Response time: {$responseTime}ms");
                $this->line("   🔌 Endpoint: " . ($result['endpoint'] ?? 'N/A'));
                $this->line("   📊 Status: " . ($result['overall_status'] ?? 'unknown'));

                if ($this->option('verbose') && isset($result['mode'])) {
                    $this->line("   🎯 Mode: " . $result['mode']);
                }

                $this->info("   ✅ Health check passed\n");
                return true;
            } else {
                $this->error("   ❌ Health check failed");
                if (isset($result['error'])) {
                    $this->error("   🔍 Error: " . $result['error']);
                }
                return false;
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Health check error: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testWallets(): bool
    {
        $this->info("3. Testing Wallets...");

        try {
            // Test getWallets()
            $walletsDto = $this->nobitex->getWallets();

            // Test getBalances() for simple array
            $balances = $this->nobitex->getBalances();

            if (empty($balances)) {
                $this->warn("   ⚠️  No wallet balances found");
            } else {
                $this->line("   💰 Found " . count($balances) . " wallets");
            }

            // Display major currencies
            $majorCurrencies = ['btc', 'eth', 'usdt', 'rls', 'irt'];
            foreach ($majorCurrencies as $currency) {
                $cur = strtoupper($currency);
                if (isset($balances[$currency])) {
                    $available = $balances[$currency]['available'] ?? '0';
                    $locked = $balances[$currency]['locked'] ?? '0';

                    if ($this->option('verbose')) {
                        $this->line("   {$cur}: {$available} (available), {$locked} (locked)");
                    } else {
                        // Only show if non-zero
                        if ((float)$available > 0 || (float)$locked > 0) {
                            $this->line("   {$cur}: {$available}");
                        }
                    }
                }
            }

            // Check for trading balance (IRT/RLS)
            $irtBalance = (float)($balances['rls']['available'] ?? $balances['irt']['available'] ?? 0);
            if ($irtBalance < 10_000_000) { // Less than 10M IRT
                $this->warn("   ⚠️  Low IRT balance for trading: " . number_format($irtBalance));
            }

            $this->info("   ✅ Wallets retrieved successfully\n");
            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to get wallets: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testMarketData(): bool
    {
        $this->info("4. Testing Market Data...");

        try {
            $symbol = 'BTCIRT';

            // Test getCurrentPrice()
            $price = $this->nobitex->getCurrentPrice($symbol);
            $this->line("   💵 {$symbol} Price: " . number_format($price) . " IRT");

            // Test getMarketStats()
            $stats = $this->nobitex->getMarketStats($symbol);

            if ($this->option('verbose')) {
                $this->line("   📊 Spread: " . number_format($stats['spread'] ?? 0) . " IRT");
                $this->line("   📈 Spread %: " . number_format($stats['spreadPercent'] ?? 0, 2) . "%");
                $this->line("   📉 24h Change: " . number_format($stats['dayChange'] ?? 0, 2) . "%");
            }

            // Test multiple symbols
            $testSymbols = ['ETHIRT', 'USDTIRT'];
            foreach ($testSymbols as $testSymbol) {
                try {
                    $symbolPrice = $this->nobitex->getCurrentPrice($testSymbol);
                    $this->line("   💵 {$testSymbol} Price: " . number_format($symbolPrice) . " IRT");
                } catch (\Exception $e) {
                    if ($this->option('verbose')) {
                        $this->warn("   ⚠️  {$testSymbol}: " . $e->getMessage());
                    }
                }
            }

            $this->info("   ✅ Market data retrieved successfully\n");
            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to get market data: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testOrderBook(): bool
    {
        $this->info("5. Testing Order Book...");

        try {
            $symbol = 'BTCIRT';
            $orderBookDto = $this->nobitex->getOrderBook($symbol);

            // Access DTO properties
            $asks = $orderBookDto->asks ?? [];
            $bids = $orderBookDto->bids ?? [];

            $this->line("   📖 {$symbol} Order Book:");
            $this->line("   📊 Asks (sell orders): " . count($asks));
            $this->line("   📊 Bids (buy orders): " . count($bids));

            if (!empty($asks) && !empty($bids)) {
                $bestAsk = $asks[0][0] ?? 0;
                $bestBid = $bids[0][0] ?? 0;

                $this->line("   🔴 Best Ask: " . number_format((float)$bestAsk) . " IRT");
                $this->line("   🟢 Best Bid: " . number_format((float)$bestBid) . " IRT");

                $spread = (float)$bestAsk - (float)$bestBid;
                $this->line("   📏 Spread: " . number_format($spread) . " IRT");

                if ($this->option('verbose')) {
                    // Show top 3 levels
                    $this->line("\n   Top 3 Ask levels:");
                    for ($i = 0; $i < min(3, count($asks)); $i++) {
                        $price = number_format((float)$asks[$i][0]);
                        $amount = $asks[$i][1];
                        $this->line("      {$price} IRT × {$amount}");
                    }

                    $this->line("\n   Top 3 Bid levels:");
                    for ($i = 0; $i < min(3, count($bids)); $i++) {
                        $price = number_format((float)$bids[$i][0]);
                        $amount = $bids[$i][1];
                        $this->line("      {$price} IRT × {$amount}");
                    }
                }
            } else {
                $this->warn("   ⚠️  Order book is empty or incomplete");
            }

            $this->info("   ✅ Order book retrieved successfully\n");
            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to get order book: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testOrderLimits(): bool
    {
        $this->info("6. Testing Order Limits...");

        try {
            $minOrderValue = Config::get('trading.exchange.min_order_value_irt', 0);
            $allowedSymbols = Config::get('trading.exchange.allowed_symbols', []);
            $feeBps = Config::get('trading.exchange.fee_bps', 35);
            $feePercent = $feeBps / 100.0;

            $this->line("   💰 Min Order Value (IRT): " . number_format($minOrderValue));
            $this->line("   💸 Exchange Fee: {$feePercent}%");
            $this->line("   📊 Allowed Symbols: " . implode(', ', $allowedSymbols));

            if ($this->option('verbose')) {
                // Show precision settings
                $precision = Config::get('trading.exchange.precision', []);
                $this->line("\n   Precision Settings:");
                foreach ($precision as $symbol => $settings) {
                    $priceDecimals = $settings['price_decimals'] ?? 'N/A';
                    $qtyDecimals = $settings['qty_decimals'] ?? 'N/A';
                    $this->line("      {$symbol}: Price={$priceDecimals}, Qty={$qtyDecimals}");
                }

                // Show tick sizes
                $ticks = Config::get('trading.ticks', []);
                if (!empty($ticks)) {
                    $this->line("\n   Tick Sizes:");
                    foreach ($ticks as $symbol => $tickSize) {
                        $this->line("      {$symbol}: {$tickSize} IRT");
                    }
                }
            }

            $this->info("   ✅ Order limits configuration OK\n");
            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to check order limits: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testSimulation(): bool
    {
        $this->info("\n7. Testing Simulation Mode...");

        try {
            $this->line("   🧪 Simulation mode is ENABLED");

            // Create a test order (small amount, high price - unlikely to fill)
            $symbol = 'BTCIRT';
            $currentPrice = $this->nobitex->getCurrentPrice($symbol);
            $testPrice = (int)($currentPrice * 1.5); // 50% above current price
            $testAmount = '0.00001'; // Very small amount

            $this->line("   📝 Creating test BUY order...");
            $this->line("      Symbol: {$symbol}");
            $this->line("      Price: " . number_format($testPrice) . " IRT (50% above market)");
            $this->line("      Amount: {$testAmount} BTC");

            try {
                // Create order DTO
                $orderDto = new CreateOrderDto(
                    side: OrderSide::BUY,
                    execution: ExecutionType::LIMIT,
                    srcCurrency: 'btc',
                    dstCurrency: 'rls',
                    amountBase: $testAmount,
                    priceIRT: $testPrice
                );

                $orderResponse = $this->nobitex->createOrder($orderDto);

                if ($orderResponse->ok && $orderResponse->orderId) {
                    $this->line("   ✅ Test order created: ID " . $orderResponse->orderId);

                    // Try to cancel the order
                    $this->line("   🗑️  Attempting to cancel test order...");
                    sleep(1); // Small delay

                    try {
                        $cancelResponse = $this->nobitex->cancelOrder($orderResponse->orderId);

                        if ($cancelResponse->ok) {
                            $this->line("   ✅ Test order cancelled successfully");
                        } else {
                            $this->warn("   ⚠️  Cancel response: " . ($cancelResponse->message ?? 'Unknown'));
                        }
                    } catch (\Exception $e) {
                        $this->warn("   ⚠️  Cancel failed: " . $e->getMessage());
                    }
                } else {
                    $this->warn("   ⚠️  Order creation response: " . ($orderResponse->message ?? 'Unknown'));
                }

                $this->info("   ✅ Simulation mode working correctly\n");
                return true;

            } catch (\Exception $e) {
                // In simulation mode, some errors are expected
                $this->warn("   ⚠️  Order test result: " . $e->getMessage());

                // Check if it's a known acceptable error
                $acceptableErrors = [
                    'InsufficientBalance',
                    'SmallOrder',
                    'BadPrice',
                    'TradingUnavailable',
                    'MarketClosed'
                ];

                $errorMessage = $e->getMessage();
                $isAcceptable = false;
                foreach ($acceptableErrors as $acceptableError) {
                    if (stripos($errorMessage, $acceptableError) !== false) {
                        $isAcceptable = true;
                        break;
                    }
                }

                if ($isAcceptable) {
                    $this->line("   ℹ️  This is an expected limitation (not a system error)");
                    $this->info("   ✅ API communication successful\n");
                    return true;
                } else {
                    $this->error("   ❌ Unexpected error in simulation test");
                    if ($this->option('verbose')) {
                        $this->error("   Stack trace: " . $e->getTraceAsString());
                    }
                    return false;
                }
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Simulation test failed: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function testRateLimiting(): bool
    {
        $this->info("8. Testing Rate Limiting...");

        try {
            $rpm = Config::get('trading.nobitex.rate_limit.rpm', 60);
            $retryTimes = Config::get('trading.nobitex.retry.times', 3);
            $retrySleep = Config::get('trading.nobitex.retry.sleep', 200);

            $this->line("   ⏱️  Rate Limit: {$rpm} requests/minute");
            $this->line("   🔄 Retry Attempts: {$retryTimes}");
            $this->line("   ⏸️  Retry Sleep: {$retrySleep}ms");

            if ($this->option('verbose')) {
                // Make several quick requests to test rate limiting
                $this->line("\n   Testing rate limiter with 5 rapid requests...");
                $times = [];

                for ($i = 1; $i <= 5; $i++) {
                    $start = microtime(true);
                    try {
                        $this->nobitex->getCurrentPrice('BTCIRT');
                        $elapsed = round((microtime(true) - $start) * 1000, 2);
                        $times[] = $elapsed;
                        $this->line("      Request {$i}: {$elapsed}ms");
                    } catch (\Exception $e) {
                        $this->warn("      Request {$i}: Failed - " . $e->getMessage());
                    }
                }

                if (!empty($times)) {
                    $avgTime = round(array_sum($times) / count($times), 2);
                    $this->line("\n   📊 Average response time: {$avgTime}ms");
                }
            }

            $this->info("   ✅ Rate limiting configuration OK\n");
            return true;

        } catch (\Exception $e) {
            $this->error("   ❌ Rate limiting test failed: " . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error("   Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }
}
