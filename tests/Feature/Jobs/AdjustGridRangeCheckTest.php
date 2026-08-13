<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AdjustGridJob;
use App\Models\BotConfig;
use App\Services\GridOrderExecutor;
use App\Services\GridOrderSync;
use App\Services\GridPlanner;
use App\Services\KillSwitchService;
use App\Support\OrderRegistry;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Bug fix — early rebalance: the range check must measure the grid range from
 * the FULL current grid (filled + open), not the shrunken open-order band.
 *
 * Before the fix, AdjustGridJob's "is price still within grid range" decision
 * took min/max from getOpenForBot() (open orders only). Once one side of the
 * grid fills, that band shrinks and one-sides, so a price still INSIDE the
 * original grid looks "outside" and fires a spurious rebalance. Proved against
 * bot 46: open band 124.60B/126.47B fired at ~121B, but the full grid
 * 119.10B/126.47B correctly skips.
 *
 * These tests drive the REAL AdjustGridJob::handle() with a real OrderRegistry
 * (so getGridExtentForBot / getOpenForBot hit the DB) and stub the rest. The
 * single observable is the bot's rebalance_count: the range check `continue`s
 * BEFORE the diff/apply stage on a SKIP (count stays 0), and only a run that
 * reaches the (non-empty) diff bumps it to 1 on a FIRE.
 *
 * NOTE (bcmath): handle() runs its available-budget check through App\Support\
 * Money (ext-bcmath). A container without bcmath will error these tests the same
 * way MoneyTest errors there — a polyfill artifact, not a regression. The host
 * verifier has bcmath. Money values are kept integer to avoid bcmath edge cases.
 */
final class AdjustGridRangeCheckTest extends TestCase
{
    use BuildsGridSchema;

    // Bot 46 real grid (IRT).
    private const BUY_LOW   = 119_104_716_200; // grid floor  (fills first)
    private const BUY_HIGH  = 120_918_493_600;
    private const SELL_LOW  = 124_601_290_370;
    private const SELL_HIGH = 126_470_309_730; // grid ceiling

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        config(['trading.adjust_grid.allowed_symbols' => ['BTCIRT']]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Active bot with a positive available budget (locked = 0). total_capital is
     * set well above BTCIRT price magnitude so the effective-budget gate never
     * pre-empts the range check we are exercising.
     */
    private function makeBot(string $mode = 'both'): BotConfig
    {
        return BotConfigFactory::new()->active()->create([
            'symbol'             => 'BTCIRT',
            'simulation'         => true,
            'mode'               => $mode,
            'grid_levels'        => 4,
            'grid_spacing'       => 1.00,
            'total_capital'      => 1_000_000_000_000_000, // >> price, budget always positive
            'capital_locked_irt' => 0,
            'rebalance_count'    => 0,
        ]);
    }

    /**
     * Run AdjustGridJob against a real OrderRegistry with the market price
     * ($mid) injected via the (mocked) planner. The (mocked) diff is non-empty,
     * so IF the run reaches the diff/apply stage it registers as a real
     * rebalance (rebalance_count → 1); a SKIP leaves it at 0.
     */
    private function runJobAtPrice(int $mid): void
    {
        $planner = $this->createMock(GridPlanner::class);
        $planner->method('plan')->willReturn([
            'symbol' => 'BTCIRT',
            'mid'    => $mid,
            'tick'   => 1,
        ]);

        $sync = $this->createMock(GridOrderSync::class);
        $sync->method('diff')->willReturn([
            'symbol'    => 'BTCIRT',
            'tick'      => 1,
            'to_place'  => [[
                'side'     => 'buy',
                'price'    => $mid,
                'quantity' => '0.001',
                'notional' => 3_000_000,
            ]],
            'to_cancel' => [],
        ]);

        $exec = $this->createMock(GridOrderExecutor::class);

        $killSwitch = $this->createMock(KillSwitchService::class);
        $killSwitch->method('checkAndTrigger')
            ->willReturn(['triggered' => false, 'reason' => null, 'details' => []]);

        // REAL registry — the whole point is to exercise the DB-backed extent.
        $reg = new OrderRegistry();

        (new AdjustGridJob())->handle($planner, $sync, $reg, $exec, $killSwitch);
    }

    private function assertSkipped(BotConfig $bot, string $msg): void
    {
        $this->assertSame(0, (int) $bot->fresh()->rebalance_count, 'SKIP expected: ' . $msg);
    }

    private function assertRebalanced(BotConfig $bot, string $msg): void
    {
        $this->assertSame(1, (int) $bot->fresh()->rebalance_count, 'REBALANCE expected: ' . $msg);
    }

    /** Bot 46's initial grid: both buys FILLED, both sells still OPEN. */
    private function seedBot46Grid(BotConfig $bot): void
    {
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => self::BUY_LOW,
        ]);
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => self::BUY_HIGH,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => self::SELL_LOW,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => self::SELL_HIGH,
        ]);
    }

    /**
     * THE CORE BUG (bot 46). Both buys filled → the open band is just the two
     * sells (124.60B/126.47B, threshold 0.93B). At ~121B the OLD code fired.
     * With the fix the range spans the full grid (119.10B/126.47B, threshold
     * 3.68B) and ~121B is still inside → SKIP.
     */
    public function test_price_inside_full_grid_after_one_side_filled_does_not_rebalance(): void
    {
        $bot = $this->makeBot();
        $this->seedBot46Grid($bot);

        // ~121B: distanceFromBottom = 121B - 119.10B = +1.895B > -3.683B, and
        // distanceFromTop = 126.47B - 121B = +5.470B > -3.683B → SKIP.
        $this->runJobAtPrice(121_000_000_000);

        $this->assertSkipped($bot, 'price 121B is inside the full grid 119.10B/126.47B');
    }

    /**
     * Genuine breakout BELOW the full grid still rebalances. 115.0B is below the
     * lower fire edge (~115.42B = 119.10B - 3.683B).
     */
    public function test_breakout_below_full_grid_triggers_rebalance(): void
    {
        $bot = $this->makeBot();
        $this->seedBot46Grid($bot);

        $this->runJobAtPrice(115_000_000_000);

        $this->assertRebalanced($bot, 'price 115.0B is more than half a grid below the floor');
    }

    /**
     * Genuine breakout ABOVE the full grid still rebalances. 130.2B is above the
     * upper fire edge (~130.15B = 126.47B + 3.683B).
     */
    public function test_breakout_above_full_grid_triggers_rebalance(): void
    {
        $bot = $this->makeBot();
        $this->seedBot46Grid($bot);

        $this->runJobAtPrice(130_200_000_000);

        $this->assertRebalanced($bot, 'price 130.2B is more than half a grid above the ceiling');
    }

    /**
     * Second-generation correctness. After a rebalance, the current grid is the
     * 'rebalance' batch — the trigger must measure THAT, not the stale original
     * 'initial_grid'. We pick a price where the two grids DISAGREE:
     *   • new rebalance grid 110B/122B (threshold 6B) → skip zone [104B, 128B]
     *   • stale initial grid 119.10B/126.47B (threshold 3.683B) → skip zone
     *     [115.42B, 130.15B]
     * At 112B the new grid SKIPs but the stale grid would FIRE, so a SKIP proves
     * the new generation is measured.
     */
    public function test_second_generation_measures_new_grid_not_stale_original(): void
    {
        $bot = $this->makeBot();

        // Stale original grid (superseded): open leg cancelled at the rebalance.
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => self::BUY_LOW,
        ]);
        GridOrderFactory::new()->sell()->cancelled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => self::SELL_HIGH,
        ]);

        // New live grid (role='rebalance'), centered lower after a downtrend.
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 110_000_000_000,
        ]);
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 116_000_000_000,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 122_000_000_000,
        ]);

        $this->runJobAtPrice(112_000_000_000);

        $this->assertSkipped($bot, '112B is inside the new grid 110B/122B (would fire against the stale grid)');
    }

    /**
     * Second generation, genuine breakout: below the NEW grid's lower fire edge
     * (104B = 110B - 6B) still fires.
     */
    public function test_second_generation_breakout_below_new_grid_triggers(): void
    {
        $bot = $this->makeBot();

        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 110_000_000_000,
        ]);
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 116_000_000_000,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 122_000_000_000,
        ]);

        $this->runJobAtPrice(100_000_000_000);

        $this->assertRebalanced($bot, '100B is more than half the new grid below its floor of 110B');
    }

    /**
     * One-sided (buy-only) grid must not hair-trigger after fills. Full buy
     * extent 119B/125B (threshold 3B) → skip zone [116B, 128B]. After the two
     * LOW buys fill, the open band collapses to [123B, 125B] (threshold 1B), so
     * the OLD code would fire at 120B; the fix keeps the full extent and SKIPs.
     */
    public function test_one_sided_buy_grid_does_not_hair_trigger_after_fills(): void
    {
        $bot = $this->makeBot('buy');

        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 119_000_000_000,
        ]);
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 121_000_000_000,
        ]);
        GridOrderFactory::new()->buy()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 123_000_000_000,
        ]);
        GridOrderFactory::new()->buy()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 125_000_000_000,
        ]);

        $this->runJobAtPrice(120_000_000_000);

        $this->assertSkipped($bot, '120B is inside the full buy extent 119B/125B despite the low buys filling');
    }

    /**
     * Reconstruction fallback. When no current-generation grid rows survive
     * (here: the only open order is role='manual', so getGridExtentForBot()
     * returns null), the job reconstructs the planned bounds from
     * grid_center_price ± half-width. With center 120B, 4 levels @ 1% the
     * reconstructed extent is 117.612B/122.412B → 120B SKIPs. Under the OLD
     * open-order band (min=max=130B, range 0) the same tick would FIRE, so a
     * SKIP proves the reconstruction was used rather than the collapsing band.
     */
    public function test_reconstruction_fallback_used_when_no_persisted_grid_rows(): void
    {
        $bot = $this->makeBot();
        $bot->update([
            'grid_center_price' => 120_000_000_000,
            'grid_levels'       => 4,
            'grid_spacing'      => 1.00,
        ]);

        // Open order with a role that does NOT define a grid generation.
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'manual', 'price' => 130_000_000_000,
        ]);

        $this->runJobAtPrice(120_000_000_000);

        $this->assertSkipped($bot, '120B is inside the reconstructed extent 117.612B/122.412B');
    }

    /**
     * Exact reconstructed bounds for a known center/levels/spacing/tick, asserted
     * directly against the private reconstructGridExtent() (mirrors GridPlanner
     * geometry: pow(1 ± step, perSide) with perSide = intdiv(levels,2)).
     */
    public function test_reconstructed_bounds_match_known_geometry(): void
    {
        $bot = $this->makeBot();
        $bot->update([
            'grid_center_price' => 120_000_000_000,
            'grid_levels'       => 4,      // perSide = 2
            'grid_spacing'      => 1.00,   // step = 0.01
        ]);

        $method = new \ReflectionMethod(AdjustGridJob::class, 'reconstructGridExtent');
        $method->setAccessible(true);

        $extent = $method->invoke(new AdjustGridJob(), $bot, ['mid' => 120_000_000_000, 'tick' => 1]);

        // 120B × 0.99^2 = 117,612,000,000 ; 120B × 1.01^2 = 122,412,000,000.
        $this->assertSame(117_612_000_000, $extent['min']);
        $this->assertSame(122_412_000_000, $extent['max']);
    }
}
