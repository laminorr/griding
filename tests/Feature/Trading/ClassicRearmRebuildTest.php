<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Jobs\AdjustGridJob;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\GridOrderExecutor;
use App\Services\GridOrderSync;
use App\Services\GridPlanner;
use App\Services\KillSwitchService;
use App\Services\MarketDataLayer;
use App\Services\NobitexService;
use App\Support\OrderRegistry;
use Database\Factories\BotConfigFactory;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Classic-grid re-arm × grid rebuilds: unfilled re-arms are grid orders (a
 * rebuild cancels/replaces them like initial_grid / rebalance rows), exits
 * stay protected, and the grid-generation rule keeps a rebuild from
 * resurrecting old levels.
 */
final class ClassicRearmRebuildTest extends TestCase
{
    use BuildsGridSchema;

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
            'trading.adjust_grid.allowed_symbols' => ['BTCIRT'],
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

    private function bot(array $over = []): BotConfig
    {
        return BotConfigFactory::new()->active()->create(array_merge([
            'symbol' => 'BTCIRT', 'simulation' => true, 'mode' => 'both', 'grid_levels' => 4, 'grid_spacing' => 1.50,
            'total_capital' => 1_000_000_000_000_000, 'capital_locked_irt' => 0, 'rebalance_count' => 0,
            'grid_center_price' => null, 'rearm_exits' => true,
        ], $over));
    }

    private function row(BotConfig $bot, string $role, string $side, int $price, array $over = []): GridOrder
    {
        static $n = 0;
        $n++;
        return GridOrder::create(array_merge([
            'bot_config_id' => $bot->id, 'type' => $side, 'status' => 'placed', 'price' => (string) $price,
            'amount' => '0.001', 'original_amount' => '0.001', 'role' => $role, 'grid_generation' => 0,
            'client_order_id' => "rb-{$n}", 'nobitex_order_id' => "SIM-RB-{$n}",
        ], $over));
    }

    private function plan(int $mid, array $items): array
    {
        return ['symbol' => 'BTCIRT', 'mid' => $mid, 'tick' => 1, 'min_order_value_irt' => 3_000_000, 'items' => array_map(
            fn ($i) => ['side' => $i[0], 'price' => $i[1], 'quantity' => '0.001', 'notional' => (int) ($i[1] / 1000)],
            $items
        )];
    }

    private function rebuild(BotConfig $bot, array $plan): array
    {
        $diff = (new GridOrderSync())->diff($plan, (new OrderRegistry())->getOpenForBot($bot->id, 'BTCIRT'));
        (new GridOrderExecutor(new NobitexService(), new OrderRegistry()))->applyForBot($bot->id, $diff, simulation: true, role: 'rebalance');
        return $diff;
    }

    private function tick(): void
    {
        (new CheckTradesJob())->handle();
    }

    public function test_rebuild_cancels_unfilled_rearms_never_exits_and_restamps_kept_levels(): void
    {
        $bot    = $this->bot();
        $parent = $this->row($bot, 'initial_grid', 'buy', 200_000_000_000, ['status' => 'filled']);
        $exit   = $this->row($bot, 'cycle_exit', 'sell', 203_000_000_000, ['paired_order_id' => $parent->id, 'grid_generation' => null]);
        $parent->update(['paired_order_id' => $exit->id]);
        $stale  = $this->row($bot, 'rearm', 'sell', 210_000_000_000);   // not in the new plan
        $kept   = $this->row($bot, 'rearm', 'buy', 190_000_000_000);    // matches the new plan

        $diff = $this->rebuild($bot, $this->plan(195_000_000_000, [['buy', 190_000_000_000], ['sell', 199_000_000_000]]));

        $this->assertSame([$stale->nobitex_order_id], array_column($diff['to_cancel'], 'id'));
        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame('placed', $exit->fresh()->status);          // exits are never cancelled
        $this->assertSame('placed', $kept->fresh()->status);

        $this->assertSame(1, (int) BotConfig::find($bot->id)->grid_generation);
        $this->assertSame(1, $kept->fresh()->grid_generation);         // kept level is current
        $this->assertSame(0, $stale->fresh()->grid_generation);
        $this->assertNull($exit->fresh()->grid_generation);
        $new = GridOrder::where('bot_config_id', $bot->id)->where('role', 'rebalance')->sole();
        $this->assertSame([199_000_000_000, 1], [(int) $new->price, $new->grid_generation]);
    }

    public function test_no_old_generation_rearm_after_a_rebuild_but_a_kept_level_still_rearms(): void
    {
        $bot  = $this->bot();
        $old  = $this->row($bot, 'initial_grid', 'sell', 200_000_000_000);
        $keep = $this->row($bot, 'initial_grid', 'buy', 190_000_000_000);

        $this->market = 200_000_000_001;            // the old sell level fills → exit buy
        $this->tick();
        $oldExit = GridOrder::findOrFail($old->fresh()->paired_order_id);

        // Rebuild around a new center: the 200B level is gone, 190B is kept.
        $this->rebuild($bot, $this->plan(185_000_000_000, [['buy', 190_000_000_000], ['buy', 180_000_000_000]]));
        $this->assertSame(1, $keep->fresh()->grid_generation);

        $this->market = (int) $oldExit->price - 1;  // the old exit fills (197B) → cycle closes, level stays dark
        $this->tick();
        $this->assertSame('filled', $oldExit->fresh()->status);
        $this->assertSame(1, CompletedTrade::where('bot_config_id', $bot->id)->count());
        $this->assertSame('skipped:OLD_GENERATION', $oldExit->fresh()->rearm_state);
        $this->assertSame(0, GridOrder::where('bot_config_id', $bot->id)->where('role', 'rearm')->where('price', '200000000000')->count());

        $this->market = 189_000_000_000;            // the kept 190B level fills → exit sell
        $this->tick();
        $keptExit = GridOrder::findOrFail($keep->fresh()->paired_order_id);
        $this->market = (int) $keptExit->price + 1; // its exit fills → re-arm, generation 1
        $this->tick();
        $rearm = GridOrder::findOrFail($keptExit->fresh()->rearm_order_id);
        $this->assertSame(['buy', 190_000_000_000, 1], [$rearm->type, (int) $rearm->price, $rearm->grid_generation]);
    }

    public function test_legacy_rows_without_a_generation_rearm_only_before_the_first_rebuild(): void
    {
        $bot  = $this->bot();
        $root = $this->row($bot, 'initial_grid', 'sell', 200_000_000_000, ['grid_generation' => null]);
        $this->market = 200_000_000_001;
        $this->tick();
        $exit = GridOrder::findOrFail($root->fresh()->paired_order_id);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $this->assertSame('placed', $exit->fresh()->rearm_state);    // current batch level, generation 0
        $this->assertSame(0, GridOrder::findOrFail($exit->fresh()->rearm_order_id)->grid_generation);

        $this->buildGridSchema();
        $bot = $this->bot();
        BotConfig::whereKey($bot->id)->update(['grid_generation' => 1]);  // a build happened since the deploy
        $root = $this->row($bot, 'initial_grid', 'sell', 200_000_000_000, ['grid_generation' => null]);
        $this->market = 200_000_000_001;
        $this->tick();
        $exit = GridOrder::findOrFail($root->fresh()->paired_order_id);
        $this->market = (int) $exit->price - 1;
        $this->tick();
        $this->assertSame('skipped:OLD_GENERATION', $exit->fresh()->rearm_state);
    }

    /* ------------------------------------------------------------------ */
    /* AdjustGridJob triggers with re-arm rows on the book                 */
    /* ------------------------------------------------------------------ */

    private function runAdjust(BotConfig $bot, int $mid, array $items): void
    {
        $planner = $this->createMock(GridPlanner::class);
        $planner->method('plan')->willReturn($this->plan($mid, $items));
        $killSwitch = $this->createMock(KillSwitchService::class);
        $killSwitch->method('checkAndTrigger')->willReturn(['triggered' => false, 'reason' => null, 'details' => []]);

        (new AdjustGridJob())->handle($planner, new GridOrderSync(), new OrderRegistry(),
            new GridOrderExecutor(new NobitexService(), new OrderRegistry()), $killSwitch);
    }

    /** A 4-level grid 196B–204B, the 198B buy re-armed and open, the 202B sell's exit open. */
    private function rearmedGrid(BotConfig $bot): array
    {
        $at = now()->subHour();
        foreach ([[196, 'buy', 'placed'], [198, 'buy', 'filled'], [202, 'sell', 'filled'], [204, 'sell', 'placed']] as [$p, $side, $st]) {
            $r = $this->row($bot, 'initial_grid', $side, $p * 1_000_000_000, ['status' => $st]);
            GridOrder::whereKey($r->id)->update(['created_at' => $at]);
        }
        $rearm = $this->row($bot, 'rearm', 'buy', 198_000_000_000);
        $exit  = $this->row($bot, 'cycle_exit', 'buy', 198_970_000_000, ['grid_generation' => null]);
        return [$rearm, $exit];
    }

    public function test_price_inside_the_range_keeps_rearms_and_the_extent_ignores_them(): void
    {
        $bot = $this->bot();
        [$rearm] = $this->rearmedGrid($bot);
        $this->assertSame(['min' => 196_000_000_000, 'max' => 204_000_000_000], (new OrderRegistry())->getGridExtentForBot($bot->id, 'BTCIRT'));

        $this->runAdjust($bot, 200_000_000_000, [['buy', 197_000_000_000]]);

        $this->assertSame(0, (int) $bot->fresh()->rebalance_count);
        $this->assertSame('placed', $rearm->fresh()->status);
    }

    public function test_price_out_of_range_still_rebuilds_and_cancels_rearms_not_exits(): void
    {
        $bot = $this->bot();
        [$rearm, $exit] = $this->rearmedGrid($bot);

        $this->runAdjust($bot, 230_000_000_000, [['buy', 226_000_000_000], ['sell', 234_000_000_000]]);

        $this->assertSame(1, (int) $bot->fresh()->rebalance_count);
        $this->assertSame('cancelled', $rearm->fresh()->status);
        $this->assertSame('placed', $exit->fresh()->status);
        $this->assertSame(1, (int) BotConfig::find($bot->id)->grid_generation);
    }

    public function test_an_empty_grid_still_rebuilds(): void
    {
        $bot = $this->bot();
        $this->row($bot, 'initial_grid', 'buy', 198_000_000_000, ['status' => 'filled']);
        $this->row($bot, 'rearm', 'buy', 198_000_000_000, ['status' => 'cancelled']);

        $this->runAdjust($bot, 200_000_000_000, [['buy', 197_000_000_000]]);

        $this->assertSame(1, (int) $bot->fresh()->rebalance_count);
        $this->assertSame(1, GridOrder::where('bot_config_id', $bot->id)->where('role', 'rebalance')->where('grid_generation', 1)->count());
    }

    public function test_rearm_is_not_a_protected_role(): void
    {
        $diff = (new GridOrderSync())->diff(['symbol' => 'BTCIRT', 'tick' => 1, 'min_order_value_irt' => 3_000_000, 'items' => []], [
            ['id' => 'R', 'side' => 'buy', 'price' => 1, 'quantity' => '1', 'role' => 'rearm', 'paired_order_id' => null],
            ['id' => 'X', 'side' => 'sell', 'price' => 2, 'quantity' => '1', 'role' => 'cycle_exit', 'paired_order_id' => 7],
            ['id' => 'M', 'side' => 'sell', 'price' => 3, 'quantity' => '1', 'role' => 'manual', 'paired_order_id' => null],
        ]);
        $this->assertSame(['R'], array_column($diff['to_cancel'], 'id'));
    }
}
