<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Contracts\MarketData;
use App\DTOs\OrderBookDto;
use App\Support\MarketSymbols;
use Database\Factories\BotConfigFactory;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * The market-stats heartbeat must only read symbols that are BOTH in use
 * (active bots + default chart symbol) AND in trading.exchange.allowed_symbols.
 * Production evidence: with TRADING_SYMBOLS_ALLOWED=BTCIRT the old hard-coded
 * BTCIRT/ETHIRT/USDTIRT loop logged 2,880 MARKET_STATS_FAILED warnings a day.
 */
final class MarketSymbolsTest extends TestCase
{
    use BuildsGridSchema;

    /** @var list<array{0:string,1:string,2:array}> level, message, context */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        Cache::flush();
        EventFacade::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = [$e->level, $e->message, $e->context];
        });
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    /** Fake MarketData that refuses disallowed symbols exactly like MarketDataLayer. */
    private function bindFakeMarket(): object
    {
        $fake = new class implements MarketData {
            /** @var list<string> */
            public array $calls = [];

            public function getLastPrice(string $symbol, ?int $maxAge = null): int
            {
                $this->calls[] = "price:{$symbol}";
                $this->assertAllowed($symbol);
                return 5_000_000_000;
            }

            public function getOrderBook(string $symbol): OrderBookDto
            {
                $this->calls[] = "book:{$symbol}";
                $this->assertAllowed($symbol);
                return new OrderBookDto($symbol, 5_000_000_000, 1_700_000_000,
                    [['price' => 4_999_000_000, 'quantity' => '0.1']],
                    [['price' => 5_001_000_000, 'quantity' => '0.1']]);
            }

            private function assertAllowed(string $symbol): void
            {
                if (!in_array($symbol, (array) config('trading.exchange.allowed_symbols'), true)) {
                    throw new \InvalidArgumentException("Symbol '{$symbol}' is not allowed. Configure trading.exchange.allowed_symbols if needed.");
                }
            }
        };
        $this->app->instance(MarketData::class, $fake);

        return $fake;
    }

    private function marketStatsEvent(): Event
    {
        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstances();
        config(['trading.enable_scheduler' => true]);
        require base_path('routes/console.php');

        $found = array_values(array_filter(
            $schedule->events(),
            fn (Event $e) => str_starts_with((string) $e->description, 'Log last price & spread'),
        ));
        $this->assertCount(1, $found, 'exactly one market-stats schedule entry');

        return $found[0];
    }

    /** @return list<array{0:string,1:string,2:array}> */
    private function logsNamed(string $message): array
    {
        return array_values(array_filter($this->logged, fn ($l) => $l[1] === $message));
    }

    public function test_only_btcirt_allowed_means_no_attempt_and_no_warning_for_ethirt_usdtirt(): void
    {
        config(['trading.exchange.allowed_symbols' => ['BTCIRT']]);
        BotConfigFactory::new()->active()->create(['symbol' => 'BTCIRT']);
        BotConfigFactory::new()->active()->create(['symbol' => 'ETHIRT']);
        BotConfigFactory::new()->active()->create(['symbol' => 'USDTIRT']);
        $market = $this->bindFakeMarket();

        $event = $this->marketStatsEvent();
        $event->run($this->app);
        $event->run($this->app);

        $this->assertSame(['book:BTCIRT', 'price:BTCIRT', 'book:BTCIRT', 'price:BTCIRT'], $market->calls);
        $this->assertSame([], $this->logsNamed('MARKET_STATS_FAILED'));
        $this->assertSame([], array_filter($this->logged, fn ($l) => $l[0] === 'warning'));

        // In use but not allowed: one INFO line per symbol per day, not per minute.
        $skips = $this->logsNamed('MARKET_STATS_SKIPPED_NOT_ALLOWED');
        $this->assertSame(['ETHIRT', 'USDTIRT'], array_map(fn ($l) => $l[2]['symbol'], $skips));
        $this->assertSame(['info', 'info'], array_column($skips, 0));
    }

    public function test_allowed_symbol_still_logs_market_stats_unchanged(): void
    {
        config(['trading.exchange.allowed_symbols' => ['BTCIRT']]);
        $this->bindFakeMarket();

        $this->marketStatsEvent()->run($this->app);

        $stats = $this->logsNamed('MARKET_STATS');
        $this->assertCount(1, $stats);
        $this->assertSame('BTCIRT', $stats[0][2]['symbol']);
        $this->assertSame(5_000_000_000, $stats[0][2]['price']);
        $this->assertSame(4_999_000_000, $stats[0][2]['bestBid']);
        $this->assertSame(5_001_000_000, $stats[0][2]['bestAsk']);
        $this->assertSame(2_000_000, $stats[0][2]['spread']);
    }

    public function test_symbols_not_in_use_are_not_read_even_when_allowed(): void
    {
        config(['trading.exchange.allowed_symbols' => ['BTCIRT', 'ETHIRT', 'USDTIRT']]);
        config(['trading.websocket.candle_symbols' => ['BTCIRT']]);
        BotConfigFactory::new()->active()->create(['symbol' => 'ETHIRT']);
        BotConfigFactory::new()->stopped()->create(['symbol' => 'USDTIRT']);

        $this->assertSame(['ETHIRT', 'BTCIRT'], MarketSymbols::marketStatsSymbols());
    }

    public function test_partition_allowed_normalises_and_dedupes(): void
    {
        config(['trading.exchange.allowed_symbols' => [' btcirt ', 'BTCIRT']]);

        $this->assertSame(
            ['allowed' => ['BTCIRT'], 'skipped' => ['ETHIRT', 'USDTIRT']],
            MarketSymbols::partitionAllowed(['BTCIRT', 'ethirt', 'USDTIRT', '', 'btcirt', 'ETHIRT']),
        );
    }
}
