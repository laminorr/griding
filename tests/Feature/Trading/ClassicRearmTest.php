<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\GridRearmer;
use App\Services\MarketDataLayer;
use App\Services\NobitexService;
use App\Services\SimulatedBasePosition;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Classic-grid re-arm (bot_configs.rearm_exits) end to end through
 * CheckTradesJob — the minute poller / simulation path (handle()) and the W4
 * single-order path (processSingleOrder()).
 */
final class ClassicRearmTest extends TestCase
{
    use BuildsGridSchema;

    private const STEP  = '0.00000001';
    private const LEVEL = 200_000_000_000;   // tick-aligned BTCIRT level
    private const QTY   = '0.001';

    private int $market = 0;
    private int $placeCalls = 0;
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
        $this->placeCalls = 0;
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function bot(array $over = []): BotConfig
    {
        return BotConfig::create(array_merge([
            'name' => 'rearm', 'symbol' => 'BTCIRT', 'simulation' => true,
            'is_active' => true, 'grid_spacing' => 1.50, 'rearm_exits' => true,
        ], $over));
    }

    private function root(BotConfig $bot, string $side, int $price = self::LEVEL, string $qty = self::QTY, array $over = []): GridOrder
    {
        static $n = 0;
        $n++;
        return GridOrder::create(array_merge([
            'bot_config_id' => $bot->id, 'type' => $side, 'status' => 'placed', 'price' => (string) $price,
            'amount' => $qty, 'original_amount' => $qty, 'role' => 'initial_grid', 'grid_generation' => 0,
            'client_order_id' => "root-{$n}", 'nobitex_order_id' => "SIM-ROOT-{$n}",
        ], $over));
    }

    private function tick(): void
    {
        (new CheckTradesJob())->handle();
    }

    private function dust(BotConfig $bot): string
    {
        return Money::trimZeros((string) BotConfig::find($bot->id)->base_dust);
    }

    /**
     * The BTC ledger is exact: what the bot holds (seed + Σ net_base_delta)
     * equals base_dust plus every BTC amount still committed to an open sell.
     */
    private function assertLedger(BotConfig $bot, string $seed, string $msg): void
    {
        $held = Money::add($seed, app(SimulatedBasePosition::class)->position($bot->id));
        $open = '0';
        foreach (GridOrder::where('bot_config_id', $bot->id)->where('type', 'sell')->whereIn('status', ['placed', 'partially_filled'])->get() as $o) {
            $open = Money::add($open, Money::trimZeros((string) $o->amount));
        }
        $this->assertSame(0, Money::compare($held, Money::add($this->dust($bot), $open)), "{$msg}: held {$held} != dust {$this->dust($bot)} + open sells {$open}");
    }

    private function assertWarned(string $event): void
    {
        $this->log->shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === $event)->atLeast()->once();
        $this->addToAssertionCount(1);
    }

    private function rearms(BotConfig $bot)
    {
        return GridOrder::where('bot_config_id', $bot->id)->where('role', 'rearm')->orderBy('id')->get();
    }

    /** Sim: root sell fills, its exit buy is placed. Returns [root, exit]. */
    private function openSellFirstCycle(BotConfig $bot): array
    {
        $root = $this->root($bot, 'sell');
        $this->market = self::LEVEL + 1;
        $this->tick();
        $exit = GridOrder::findOrFail($root->fresh()->paired_order_id);
        $this->assertSame('cycle_exit', $exit->role);
        return [$root->fresh(), $exit];
    }

    /* ------------------------------------------------------------------ */
    /* full chains                                                         */
    /* ------------------------------------------------------------------ */

    public function test_sell_first_chain_rearms_the_same_level_every_cycle_with_an_exact_dust_ledger(): void
    {
        $bot  = $this->bot();
        $root = $this->root($bot, 'sell');
        $leg  = $root;
        $this->assertLedger($bot, self::QTY, 'start');

        for ($i = 1; $i <= 3; $i++) {
            $this->market = self::LEVEL + 1;          // the level's sell fills
            $this->tick();
            $leg  = $leg->fresh();
            $this->assertSame('filled', $leg->status, "cycle {$i}");
            $exit = GridOrder::findOrFail($leg->paired_order_id);
            $this->assertSame(['buy', 'cycle_exit'], [$exit->type, $exit->role], "cycle {$i}");
            $this->assertLedger($bot, self::QTY, "cycle {$i} open");

            $this->market = (int) $exit->price - 1;  // the exit buy fills → re-arm
            $this->tick();
            $exit = $exit->fresh();
            $this->assertSame('filled', $exit->status);
            $this->assertSame($i, CompletedTrade::where('bot_config_id', $bot->id)->count(), "cycle {$i} booked");
            $this->assertSame('placed', $exit->rearm_state);

            $rearm = GridOrder::findOrFail($exit->rearm_order_id);
            $this->assertSame(['sell', 'rearm', 'placed'], [$rearm->type, $rearm->role, $rearm->status]);
            $this->assertSame(0, Money::compare((string) $rearm->price, (string) self::LEVEL), "cycle {$i}: level drifted");
            $this->assertSame($root->id, $rearm->rearm_root_order_id);
            $this->assertSame($exit->id, $rearm->rearm_exit_order_id);
            $this->assertSame(0, $rearm->grid_generation);

            // amount = floor(backing + dust): never more than restored + dust, dust < one step.
            $dust = $this->dust($bot);
            $this->assertGreaterThanOrEqual(0, Money::compare($dust, '0'), "cycle {$i}");
            $this->assertLessThan(0, Money::compare($dust, self::STEP), "cycle {$i}");
            $this->assertSame(0, Money::compare(Money::trimZeros((string) $rearm->exit_dust_delta), Money::sub(Money::abs((string) $leg->net_base_delta), Money::trimZeros((string) $rearm->amount))));
            $this->assertLedger($bot, self::QTY, "cycle {$i} re-armed");

            $leg = $rearm;
        }

        $trades = CompletedTrade::where('bot_config_id', $bot->id)->get();
        $this->assertCount(3, $trades);
        foreach ($trades as $t) {
            $this->assertSame(0, Money::compare((string) $t->sell_price, (string) self::LEVEL));
        }
        $this->assertCount(3, $this->rearms($bot));
        $this->log->shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'REARM_PLACED'
            && $c['side'] === 'sell' && $c['price'] === (string) self::LEVEL && $c['root_order_id'] === $root->id)->times(3);
    }

    public function test_buy_first_chain_rearms_the_same_level_at_the_level_size(): void
    {
        $bot  = $this->bot();
        $root = $this->root($bot, 'buy');
        $leg  = $root;

        for ($i = 1; $i <= 3; $i++) {
            $this->market = self::LEVEL - 1;          // the level's buy fills
            $this->tick();
            $leg  = $leg->fresh();
            $exit = GridOrder::findOrFail($leg->paired_order_id);
            $this->assertSame(['sell', 'cycle_exit'], [$exit->type, $exit->role]);
            $this->assertLedger($bot, '0', "cycle {$i} open");

            $this->market = (int) $exit->price + 1;  // the exit sell fills → re-arm
            $this->tick();
            $exit = $exit->fresh();
            $this->assertSame($i, CompletedTrade::where('bot_config_id', $bot->id)->count());

            $rearm = GridOrder::findOrFail($exit->rearm_order_id);
            $this->assertSame(['buy', 'rearm', 'placed'], [$rearm->type, $rearm->role, $rearm->status]);
            $this->assertSame(0, Money::compare((string) $rearm->price, (string) self::LEVEL));
            // The exit sell's proceeds cover the level size: same quantity as the level.
            $this->assertSame(self::QTY, Money::trimZeros((string) $rearm->amount));
            $this->assertNull($rearm->exit_dust_delta);
            $this->assertLedger($bot, '0', "cycle {$i} re-armed");

            $leg = $rearm;
        }
        $this->assertSame(3, CompletedTrade::where('bot_config_id', $bot->id)->count());
    }

    public function test_simulation_oscillating_between_two_levels_closes_one_cycle_per_oscillation(): void
    {
        foreach ([true => 5, false => 1] as $flag => $expected) {
            $this->buildGridSchema();
            $bot = $this->bot(['rearm_exits' => $flag]);
            $this->root($bot, 'buy', 100_000_000_000);

            for ($n = 0; $n < 5; $n++) {
                $this->market = 99_000_000_000;      // below the level → buy fills
                $this->tick();
                $this->market = 102_000_000_000;     // above its exit (101.5B) → exit fills
                $this->tick();
            }

            $this->assertSame($expected, CompletedTrade::where('bot_config_id', $bot->id)->count(), 'rearm_exits=' . var_export($flag, true));
        }
    }

    /* ------------------------------------------------------------------ */
    /* idempotency (W4 + poller)                                           */
    /* ------------------------------------------------------------------ */

    /** Live bot, sell root filled, its exit buy live on the exchange as 900001. */
    private function liveOpenCycle(): array
    {
        $bot  = $this->bot(['simulation' => false]);
        $root = $this->root($bot, 'sell', self::LEVEL, self::QTY, [
            'status' => 'filled', 'filled_amount' => self::QTY, 'filled_at' => now()->subMinute(),
            'fee_amount' => '500000', 'fee_currency' => 'quote', 'fee_source' => 'actual',
            'avg_fill_price' => (string) self::LEVEL, 'net_base_delta' => '-0.001', 'nobitex_order_id' => '900000',
        ]);
        $exit = GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'placed', 'price' => '197000000000',
            'amount' => '0.00100251', 'original_amount' => '0.00100251', 'role' => 'cycle_exit',
            'paired_order_id' => $root->id, 'client_order_id' => 'exit-1', 'nobitex_order_id' => '900001',
        ]);
        $root->update(['paired_order_id' => $exit->id]);

        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(fn (array $ids) => array_values(array_filter(array_map(
            fn ($id) => $id === '900001' ? OrderStatusDto::fromApi([
                'id' => '900001', 'status' => 'Done', 'type' => 'buy', 'execution' => 'limit',
                'amount' => '0.00100251', 'matchedAmount' => '0.00100251', 'price' => 197_000_000_000,
                'createdAt' => 1_700_000_000_000,
            ]) : null,
            $ids
        ))));
        $svc->shouldReceive('placeOrder')->andReturnUsing(function () {
            $this->placeCalls++;
            return ['status' => 'ok', 'order' => ['id' => 950000 + $this->placeCalls]];
        });
        $this->app->instance(NobitexService::class, $svc);

        return [$bot, $root, $exit];
    }

    public function test_w4_then_poller_on_one_exit_fill_places_exactly_one_rearm(): void
    {
        [$bot, $root, $exit] = $this->liveOpenCycle();

        (new CheckTradesJob())->processSingleOrder($exit, $bot);

        $this->assertSame('filled', $exit->fresh()->status);
        $this->assertSame(1, CompletedTrade::where('bot_config_id', $bot->id)->count());
        $this->assertSame(1, $this->placeCalls);
        $rearm = $this->rearms($bot)->sole();
        $this->assertSame(['sell', 'placed', '950001'], [$rearm->type, $rearm->status, $rearm->nobitex_order_id]);
        $this->assertSame($rearm->id, $exit->fresh()->rearm_order_id);

        // The minute poller runs right after (and the replayed event, and a stale copy).
        $this->tick();
        (new CheckTradesJob())->processSingleOrder($exit->fresh(), $bot);
        // A copy loaded after the fill but before the back-link was written.
        $stale = $exit->fresh();
        $stale->rearm_order_id = null;
        $stale->rearm_state = null;
        $stale->syncOriginal();
        $this->assertSame('already', app(GridRearmer::class)->rearmAfterExit($stale, $bot));

        $this->assertSame(1, $this->placeCalls);
        $this->assertCount(1, $this->rearms($bot));
        $this->assertSame(1, CompletedTrade::where('bot_config_id', $bot->id)->count());
    }

    public function test_a_busy_rearm_lock_defers_to_the_poller_which_places_one_rearm(): void
    {
        [$bot, , $exit] = $this->liveOpenCycle();

        $held = Cache::lock("rearm:{$exit->id}", 10);
        $this->assertTrue($held->get());
        (new CheckTradesJob())->processSingleOrder($exit, $bot);   // W4 fills it, re-arm lock busy
        $this->assertCount(0, $this->rearms($bot));
        $this->assertNull($exit->fresh()->rearm_state);
        $held->release();

        $this->tick();                                                 // poller sweep
        $this->tick();
        $this->assertSame(1, $this->placeCalls);
        $this->assertCount(1, $this->rearms($bot));
    }

    public function test_the_database_refuses_a_second_rearm_for_one_exit(): void
    {
        $bot = $this->bot();
        [, $exit] = $this->openSellFirstCycle($bot);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $rearm = $this->rearms($bot)->sole();

        $this->expectException(QueryException::class);
        GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'sell', 'status' => 'pending', 'price' => (string) self::LEVEL,
            'amount' => '0.001', 'role' => 'rearm', 'rearm_exit_order_id' => $rearm->rearm_exit_order_id,
        ]);
    }

    public function test_a_definitively_rejected_rearm_is_cancelled_its_dust_returned_and_not_retried(): void
    {
        [$bot, , $exit] = $this->liveOpenCycle();
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(fn (array $ids) => in_array('900001', $ids, true) ? [OrderStatusDto::fromApi([
            'id' => '900001', 'status' => 'Done', 'type' => 'buy', 'execution' => 'limit',
            'amount' => '0.00100251', 'matchedAmount' => '0.00100251', 'price' => 197_000_000_000, 'createdAt' => 1_700_000_000_000,
        ])] : []);
        $svc->shouldReceive('placeOrder')->once()->andThrow(\App\Exceptions\InsufficientBalanceRejection::withCode('InsufficientBalance', 'Insufficient balance'));
        $this->app->instance(NobitexService::class, $svc);

        (new CheckTradesJob())->processSingleOrder($exit, $bot);
        $settled = $this->dust($bot);
        $this->tick();

        $exit  = $exit->fresh();
        $rearm = GridOrder::findOrFail($exit->rearm_order_id);
        $this->assertSame('skipped:REJECTED', $exit->rearm_state);
        $this->assertSame(['cancelled', 'InsufficientBalance'], [$rearm->status, $rearm->last_error_code]);
        $this->assertSame(0, Money::compare(Money::trimZeros((string) $rearm->exit_dust_delta), '0'));
        $this->assertSame(0, Money::compare($this->dust($bot), Money::trimZeros((string) $exit->exit_dust_delta)));
        $this->assertSame($settled, $this->dust($bot));
        $this->assertWarned('REARM_SKIPPED_REJECTED');
    }

    /* ------------------------------------------------------------------ */
    /* skips                                                               */
    /* ------------------------------------------------------------------ */

    public function test_level_busy_skips_and_keeps_one_live_order_on_the_level(): void
    {
        $bot = $this->bot();
        [, $exit] = $this->openSellFirstCycle($bot);
        // Something else is already live on the level (e.g. a rebuild's order).
        $this->root($bot, 'sell', self::LEVEL, self::QTY, ['role' => 'rebalance']);

        $this->market = (int) $exit->price - 1;
        $this->tick();

        $this->assertSame('skipped:LEVEL_BUSY', $exit->fresh()->rearm_state);
        $this->assertNull($exit->fresh()->rearm_order_id);
        $this->assertCount(0, $this->rearms($bot));
        $this->assertSame(1, GridOrder::where('bot_config_id', $bot->id)->where('price', (string) self::LEVEL)->whereIn('status', ['placed', 'partially_filled'])->count());
        $this->assertSame(1, CompletedTrade::where('bot_config_id', $bot->id)->count());
        $this->assertWarned('REARM_SKIPPED_LEVEL_BUSY');
    }

    public function test_below_min_notional_skips_and_moves_no_dust(): void
    {
        $bot = $this->bot();
        [, $exit] = $this->openSellFirstCycle($bot);
        config(['trading.min_order_value_irt' => 1_000_000_000_000]);

        $this->market = (int) $exit->price - 1;
        $this->tick();

        $this->assertSame('skipped:MIN_NOTIONAL', $exit->fresh()->rearm_state);
        $this->assertCount(0, $this->rearms($bot));
        // Only the exit buy's own settlement moved the ledger.
        $this->assertSame(0, Money::compare($this->dust($bot), Money::trimZeros((string) $exit->fresh()->exit_dust_delta)));
        $this->assertWarned('REARM_SKIPPED_MIN_NOTIONAL');
    }

    public function test_inactive_bot_is_not_rearmed(): void
    {
        $bot = $this->bot(['rearm_exits' => false]);
        [, $exit] = $this->openSellFirstCycle($bot);
        $this->market = (int) $exit->price - 1;
        $this->tick();                                       // flag off: the exit just fills

        BotConfig::whereKey($bot->id)->update(['rearm_exits' => true, 'is_active' => false]);
        $this->assertSame('skipped', app(GridRearmer::class)->rearmAfterExit($exit->fresh(), $bot));
        $this->assertSame('skipped:BOT_INACTIVE', $exit->fresh()->rearm_state);
        $this->assertCount(0, $this->rearms($bot));
        $this->assertWarned('REARM_SKIPPED_BOT_INACTIVE');
    }

    public function test_tripped_kill_switch_is_not_rearmed(): void
    {
        $bot = $this->bot(['grid_center_price' => (string) self::LEVEL, 'stop_loss_percent' => 1.00]);
        [, $exit] = $this->openSellFirstCycle($bot);

        $this->market = (int) $exit->price - 1;             // 1.5% below the center > 1% stop-loss
        $this->tick();

        $this->assertSame('skipped:KILL_SWITCH', $exit->fresh()->rearm_state);
        $this->assertCount(0, $this->rearms($bot));
        $this->assertFalse((bool) BotConfig::find($bot->id)->is_active);
        $this->assertSame(1, CompletedTrade::where('bot_config_id', $bot->id)->count());
        $this->assertWarned('REARM_SKIPPED_KILL_SWITCH');
    }

    public function test_exit_blocked_bot_or_order_is_not_rearmed(): void
    {
        $bot = $this->bot();
        [, $exit] = $this->openSellFirstCycle($bot);
        BotConfig::whereKey($bot->id)->update(['last_error_code' => 'EXIT_BLOCKED']);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $this->assertSame('skipped:EXIT_BLOCKED', $exit->fresh()->rearm_state);

        $this->buildGridSchema();
        $bot = $this->bot();
        [$root, $exit] = $this->openSellFirstCycle($bot);
        $root->forceFill(['exit_state' => 'blocked'])->save();
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $this->assertSame('skipped:EXIT_BLOCKED', $exit->fresh()->rearm_state);
        $this->assertCount(0, $this->rearms($bot));
        $this->assertWarned('REARM_SKIPPED_EXIT_BLOCKED');
    }

    public function test_flag_off_leaves_the_level_dark_and_writes_nothing(): void
    {
        $bot = $this->bot(['rearm_exits' => false]);
        [, $exit] = $this->openSellFirstCycle($bot);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $this->tick();

        $exit = $exit->fresh();
        $this->assertSame('filled', $exit->status);
        $this->assertNull($exit->rearm_state);
        $this->assertNull($exit->rearm_order_id);
        $this->assertCount(0, $this->rearms($bot));
        $this->assertSame(1, CompletedTrade::where('bot_config_id', $bot->id)->count());
        $this->log->shouldNotHaveReceived('info', [Mockery::on(fn ($m) => str_starts_with((string) $m, 'REARM_')), Mockery::any()]);
        $this->log->shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_starts_with((string) $m, 'REARM_')), Mockery::any()]);
    }

    public function test_a_rearm_on_the_wrong_side_of_its_exit_is_refused(): void
    {
        $bot = $this->bot();
        [$root, $exit] = $this->openSellFirstCycle($bot);
        // Corrupt level: the root now sits BELOW its exit buy.
        GridOrder::whereKey($root->id)->update(['price' => '190000000000']);

        $this->market = (int) $exit->price - 1;
        $this->tick();

        $this->assertSame('skipped:PRICE_INVARIANT', $exit->fresh()->rearm_state);
        $this->assertCount(0, $this->rearms($bot));
        $this->log->shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => $m === 'REARM_REFUSED_PRICE_INVARIANT')->once();
    }

    public function test_exits_that_filled_before_the_window_are_not_rearmed(): void
    {
        $bot = $this->bot(['rearm_exits' => false]);
        [, $exit] = $this->openSellFirstCycle($bot);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        GridOrder::whereKey($exit->id)->update(['filled_at' => now()->subHours(3)]);

        BotConfig::whereKey($bot->id)->update(['rearm_exits' => true]);  // switched on later
        $this->tick();

        $this->assertNull($exit->fresh()->rearm_state);
        $this->assertCount(0, $this->rearms($bot));
    }

    /* ------------------------------------------------------------------ */
    /* inventory edge cases                                                */
    /* ------------------------------------------------------------------ */

    public function test_rearm_sell_never_sells_more_than_restored_plus_dust(): void
    {
        $bot = $this->bot();
        [$root, $exit] = $this->openSellFirstCycle($bot);
        // A recorded shortfall (e.g. a self-healed exit): the account holds less.
        BotConfig::whereKey($bot->id)->update(['base_dust' => '-0.00020003']);

        $this->market = (int) $exit->price - 1;
        $this->tick();

        $exit    = $exit->fresh();
        $rearm   = GridOrder::findOrFail($exit->rearm_order_id);
        $settled = Money::add('-0.00020003', Money::trimZeros((string) $exit->exit_dust_delta));   // dust before the re-arm
        $backing = Money::abs((string) $root->fresh()->net_base_delta);
        $amount  = Money::trimZeros((string) $rearm->amount);

        // ≤ restored (backing + exit buy excess) + dust, and the shortfall is absorbed.
        $this->assertLessThanOrEqual(0, Money::compare($amount, Money::add($backing, $settled)));
        $this->assertLessThan(0, Money::compare($amount, self::QTY));
        $dust = $this->dust($bot);
        $this->assertGreaterThanOrEqual(0, Money::compare($dust, '0'));
        $this->assertLessThan(0, Money::compare($dust, self::STEP));
        $this->assertLedger($bot, Money::add(self::QTY, '-0.00020003'), 'shortfall');
    }

    public function test_rearm_sell_skipped_when_the_shortfall_leaves_less_than_the_minimum(): void
    {
        $bot = $this->bot();
        [, $exit] = $this->openSellFirstCycle($bot);
        BotConfig::whereKey($bot->id)->update(['base_dust' => '-0.00099']);  // ~0.00001 BTC left ≈ 2M rial

        $this->market = (int) $exit->price - 1;
        $this->tick();

        $exit = $exit->fresh();
        $this->assertSame('skipped:MIN_NOTIONAL', $exit->rearm_state);
        $this->assertSame(0, Money::compare($this->dust($bot), Money::add('-0.00099', Money::trimZeros((string) $exit->exit_dust_delta))));
    }

    public function test_rearm_buy_is_sized_down_to_the_proceeds(): void
    {
        $bot  = $this->bot();
        $root = $this->root($bot, 'buy');
        $this->market = self::LEVEL - 1;
        $this->tick();
        $exit = GridOrder::findOrFail($root->fresh()->paired_order_id);
        // The level was planned larger than this cycle can fund.
        GridOrder::whereKey($root->id)->update(['original_amount' => '0.002', 'amount' => '0.002']);

        $this->market = (int) $exit->price + 1;
        $this->tick();

        $exit  = $exit->fresh();
        $rearm = GridOrder::findOrFail($exit->rearm_order_id);
        $proceeds = Money::sub(Money::mul(Money::trimZeros((string) $exit->filled_amount), (string) $exit->avg_fill_price), Money::trimZeros((string) $exit->fee_amount));
        $cost     = Money::mul(Money::trimZeros((string) $rearm->amount), (string) $rearm->price);
        $this->assertLessThanOrEqual(0, Money::compare($cost, $proceeds));
        $this->assertLessThan(0, Money::compare(Money::trimZeros((string) $rearm->amount), '0.002'));
        $this->assertGreaterThan(0, Money::compare(Money::add($cost, Money::mul(self::STEP, (string) $rearm->price)), $proceeds));
    }

    /* ------------------------------------------------------------------ */
    /* bot:audit                                                           */
    /* ------------------------------------------------------------------ */

    private function audit(BotConfig $bot): array
    {
        $dir = storage_path('framework/testing/rearm-audit-' . uniqid());
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        try {
            $A = app(\App\Services\Audit\BotAuditor::class)->audit($bot->id, [
                'from' => null, 'to' => null, 'start_rls' => null, 'start_btc' => null, 'no_exchange' => true,
                'candles' => '1h', 'synced' => [], 'log_dir' => $dir, 'rate_ms' => 300,
            ]);
            // The report renders the re-arm block.
            $md = app(\App\Services\Audit\AuditReportRenderer::class)->write($A, $dir . '/out')['report.md'];
            $this->assertStringContainsString('Classic re-arm (rearm_exits ON', (string) file_get_contents($md));
            return $A;
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }

    public function test_audit_counts_rearms_and_finds_no_violation_on_a_clean_chain(): void
    {
        $bot  = $this->bot();
        $leg  = $this->root($bot, 'sell');
        for ($i = 1; $i <= 2; $i++) {
            $this->market = self::LEVEL + 1;
            $this->tick();
            $exit = GridOrder::findOrFail($leg->fresh()->paired_order_id);
            $this->market = (int) $exit->price - 1;
            $this->tick();
            $leg = GridOrder::findOrFail($exit->fresh()->rearm_order_id);
        }

        $A  = $this->audit($bot);
        $rm = $A['orders']['rearm'];
        $this->assertTrue($rm['flag_on']);
        $this->assertSame(2, $rm['rows']);
        $this->assertSame(['placed' => 2], $rm['exit_decisions']);
        $this->assertSame(['one_rearm_per_exit' => 0, 'level_free' => 0, 'price_side' => 0], $rm['violations']);
        $codes = array_column($A['anomalies'], 'code');
        foreach (['REARM_DUPLICATE', 'REARM_LEVEL_NOT_FREE', 'REARM_PRICE_INVARIANT', 'REARM_LEVEL_DRIFT', 'DUST_LEDGER_MISMATCH', 'DUST_DELTA_MISMATCH', 'FILL_WITHOUT_EXIT'] as $c) {
            $this->assertNotContains($c, $codes, $c);
        }
        $this->assertContains('rearm_sell_sized', array_column($A['dust']['steps'], 'kind'));
    }

    public function test_audit_flags_a_shared_level_and_a_wrong_side_rearm(): void
    {
        $bot = $this->bot();
        [, $exit] = $this->openSellFirstCycle($bot);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $rearm = GridOrder::findOrFail($exit->fresh()->rearm_order_id);

        // Invariant 2 broken by hand: a second live order on the re-arm's level.
        $this->root($bot, 'buy', self::LEVEL, self::QTY, ['role' => 'rebalance']);
        // Invariant 5 broken by hand: a fake re-arm sell BELOW its exit buy.
        $fakeExit = GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'filled', 'price' => '160000000000', 'amount' => '0.001',
            'role' => 'cycle_exit', 'client_order_id' => 'fake-exit',
        ]);
        GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'sell', 'status' => 'cancelled', 'price' => '150000000000', 'amount' => '0.001',
            'role' => 'rearm', 'rearm_exit_order_id' => $fakeExit->id, 'client_order_id' => 'fake',
        ]);

        $rm = $this->audit($bot)['orders']['rearm'];
        $this->assertSame(1, $rm['violations']['level_free']);
        $this->assertSame(1, $rm['violations']['price_side']);
        $this->assertSame($rearm->id, $rearm->fresh()->id);
    }
}
