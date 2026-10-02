<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\MarketDataLayer;
use App\Services\SimulatedBasePosition;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 7 — simulation runs the same fee model and exit sizing as
 * live and keeps a simulated BTC wallet, so paper trading shows what a live
 * bot would hit. End-to-end through CheckTradesJob::handle().
 */
final class SimulationParityTest extends TestCase
{
    use BuildsGridSchema;

    private const STEP = '0.00000001';

    /** Market price the mocked MarketDataLayer returns. */
    private int $market = 0;

    private LoggerInterface $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        config([
            'trading.fees.buy_fee_bps' => '25', 'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency' => 'base', 'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.fee_scale' => 10,
            'trading.min_order_value_irt' => 3_000_000,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);

        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->andReturnUsing(fn () => $this->market);
        $this->app->instance(MarketDataLayer::class, $md);

        $this->log = Mockery::spy(LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->andReturn($this->log);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function simBot(): BotConfig
    {
        return BotConfig::create([
            'name' => 'sim-parity', 'symbol' => 'BTCIRT', 'simulation' => true,
            'is_active' => true, 'grid_spacing' => 1.00,
        ]);
    }

    /**
     * 100 buy-first cycles, zero seed BTC: each cycle a fresh grid buy fills
     * (market dips below it), its exit sell is sized by ExitSizer, and the
     * exit fills (market rises). Simulation must never place an exit larger
     * than the simulated BTC it holds, and its BTC drift must equal the dust
     * ledger and stay within (cycles × 1 step).
     */
    public function test_hundred_buy_first_cycles_with_zero_seed_btc_never_oversize(): void
    {
        $bot = $this->simBot();
        mt_srand(7);
        $sim = app(SimulatedBasePosition::class);

        for ($i = 1; $i <= 100; $i++) {
            $price  = mt_rand(100_000, 250_000) * 1_000_000;
            $amount = Money::floorToScale(Money::div((string) mt_rand(3_100_000, 900_000_000), (string) $price, 18), 8);

            $buy = GridOrder::create([
                'bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'placed', 'price' => (string) $price,
                'amount' => $amount, 'original_amount' => $amount, 'role' => 'grid',
                'client_order_id' => "sim-buy-{$i}", 'nobitex_order_id' => "SIM-B{$i}",
            ]);

            $this->market = $price - 1;           // buy fills, exit sell is placed
            (new CheckTradesJob())->handle();

            $buy->refresh();
            $exit = GridOrder::findOrFail($buy->paired_order_id);
            $credited = Money::sub($amount, Money::trimZeros((string) $buy->fee_amount));
            $this->assertSame('estimated', $buy->fee_source);
            $this->assertLessThanOrEqual(0, Money::compare((string) $exit->amount, Money::add($credited, '0.00000001')), "cycle {$i}");

            $this->market = (int) $exit->price + 1; // exit sell fills
            (new CheckTradesJob())->handle();
            $this->assertSame('filled', $exit->fresh()->status, "cycle {$i}");

            // Simulated wallet == dust ledger; drift bounded; never negative.
            $position = $sim->position($bot->id);
            $dust     = Money::trimZeros((string) BotConfig::find($bot->id)->base_dust);
            $this->assertSame(0, Money::compare($position, $dust), "cycle {$i}: position {$position} != dust {$dust}");
            $this->assertGreaterThanOrEqual(0, Money::compare($position, '0'), "cycle {$i}");
            $this->assertLessThanOrEqual(0, Money::compare($position, Money::mul((string) $i, self::STEP)), "cycle {$i}");
        }

        $this->assertSame(100, CompletedTrade::where('bot_config_id', $bot->id)->count());
        $this->assertLessThan(0, Money::compare(Money::trimZeros((string) BotConfig::find($bot->id)->base_dust), self::STEP));
        $this->log->shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => $m === 'SIM_WOULD_FAIL_INSUFFICIENT_BASE'), Mockery::any()]);
    }

    /**
     * Positive control: an exit that is larger than the simulated wallet
     * (here because the bot's dust ledger claims BTC the simulation never
     * credited) triggers SIM_WOULD_FAIL_INSUFFICIENT_BASE — the same point a
     * live bot would get InsufficientBalance.
     */
    public function test_exit_beyond_the_simulated_wallet_warns(): void
    {
        $bot = $this->simBot();
        BotConfig::whereKey($bot->id)->update(['base_dust' => '0.00001']);
        GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'placed', 'price' => '100000000000',
            'amount' => '0.001', 'original_amount' => '0.001', 'client_order_id' => 'sim-x', 'nobitex_order_id' => 'SIM-X',
        ]);

        $this->market = 99_000_000_000;
        (new CheckTradesJob())->handle();

        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $c = []) => $m === 'SIM_WOULD_FAIL_INSUFFICIENT_BASE'
                && $c['amount'] === '0.0010075' && $c['sim_position'] === '0.0009975')
            ->once();
        $this->addToAssertionCount(1); // the Mockery verification above
    }

    /** A sim sell-first cycle restores the simulated inventory (+ sub-step excess). */
    public function test_sell_first_sim_cycle_restores_inventory(): void
    {
        $bot = $this->simBot();
        $sell = GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'sell', 'status' => 'placed', 'price' => '216000000000',
            'amount' => '0.00578704', 'original_amount' => '0.00578704', 'client_order_id' => 'sim-s', 'nobitex_order_id' => 'SIM-S',
        ]);

        $this->market = 216_000_000_001;
        (new CheckTradesJob())->handle();
        $exit = GridOrder::findOrFail($sell->fresh()->paired_order_id);
        $this->assertSame('0.00580155', Money::trimZeros((string) $exit->amount)); // restore

        $this->market = 213_000_000_000;      // exit buy price 213,840,000,000 → fills
        (new CheckTradesJob())->handle();

        // Simulation uses the fee ESTIMATE, rounded up at 10 dp:
        // 0.00580155 × 0.0025 = 0.000014503875 → 0.0000145039, so the exit buy
        // credits 0.0057870461 and the cycle leaves −0.00578704 + 0.0057870461
        // = +0.0000000061 BTC (live, with the exact actual fee: +0.000000006125).
        $pos = app(SimulatedBasePosition::class)->position($bot->id);
        $this->assertSame('0.0000000061', $pos);
        $this->assertSame('0.0000000061', Money::trimZeros((string) BotConfig::find($bot->id)->base_dust));
    }
}
