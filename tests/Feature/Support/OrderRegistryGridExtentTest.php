<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\OrderRegistry;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * OrderRegistry::getGridExtentForBot — the range-check source added for the
 * early-rebalance fix. It returns min/max PRICE of the bot's CURRENT grid
 * generation over ALL statuses (filled + open), where the generation is:
 *   • 'initial_grid'  when the bot has never rebalanced,
 *   • the newest 'rebalance' batch once it has.
 *
 * These are the properties the AdjustGridJob range check relies on.
 */
final class OrderRegistryGridExtentTest extends TestCase
{
    use BuildsGridSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    private function bot(): \App\Models\BotConfig
    {
        return BotConfigFactory::new()->active()->create(['symbol' => 'BTCIRT']);
    }

    /**
     * The extent spans the FULL initial grid even after one side has filled —
     * the exact case the open-order band got wrong for bot 46.
     */
    public function test_extent_spans_full_initial_grid_including_filled_orders(): void
    {
        $bot = $this->bot();

        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 119_104_716_200,
        ]);
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 120_918_493_600,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 124_601_290_370,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 126_470_309_730,
        ]);

        $extent = (new OrderRegistry())->getGridExtentForBot($bot->id, 'BTCIRT');

        $this->assertSame(119_104_716_200, $extent['min'], 'min must include the filled buy floor');
        $this->assertSame(126_470_309_730, $extent['max']);
    }

    /**
     * Once a rebalance exists, its batch supersedes the (now stale) initial grid.
     */
    public function test_rebalance_generation_supersedes_initial_grid(): void
    {
        $bot = $this->bot();

        // Stale original grid.
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 119_000_000_000,
        ]);
        GridOrderFactory::new()->sell()->cancelled()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'price' => 126_000_000_000,
        ]);

        // New live grid.
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 110_000_000_000,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 122_000_000_000,
        ]);

        $extent = (new OrderRegistry())->getGridExtentForBot($bot->id, 'BTCIRT');

        $this->assertSame(110_000_000_000, $extent['min'], 'must measure the rebalance grid, not the stale initial one');
        $this->assertSame(122_000_000_000, $extent['max']);
    }

    /**
     * With multiple rebalances, only the NEWEST placement batch is measured —
     * the older rebalance generation (created a cadence earlier) is dropped by
     * the created_at clustering.
     */
    public function test_multiple_rebalances_measure_only_newest_batch(): void
    {
        $bot = $this->bot();

        // First rebalance generation — created 20 minutes ago (>> clustering
        // window; the default cadence is 600s → window 300s).
        $old = now()->subMinutes(20);
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 130_000_000_000, 'created_at' => $old,
        ]);
        GridOrderFactory::new()->sell()->cancelled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 140_000_000_000, 'created_at' => $old,
        ]);

        // Second (newest) rebalance generation — just now.
        GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 110_000_000_000, 'created_at' => now(),
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'rebalance', 'price' => 122_000_000_000, 'created_at' => now(),
        ]);

        $extent = (new OrderRegistry())->getGridExtentForBot($bot->id, 'BTCIRT');

        $this->assertSame(110_000_000_000, $extent['min'], 'stale first-generation floor must be excluded');
        $this->assertSame(122_000_000_000, $extent['max'], 'stale first-generation ceiling (140B) must be excluded');
    }

    /** No grid orders → null, so the caller keeps its no-rebalance-on-empty guard. */
    public function test_returns_null_when_no_grid_orders(): void
    {
        $bot = $this->bot();

        $this->assertNull((new OrderRegistry())->getGridExtentForBot($bot->id, 'BTCIRT'));
    }

    /** cycle_exit / manual orders never define the grid extent → null. */
    public function test_cycle_exit_and_manual_orders_do_not_define_extent(): void
    {
        $bot = $this->bot();

        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'cycle_exit', 'price' => 130_000_000_000,
        ]);
        GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'role' => 'manual', 'price' => 131_000_000_000,
        ]);

        $this->assertNull((new OrderRegistry())->getGridExtentForBot($bot->id, 'BTCIRT'));
    }
}
