<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BotMonitoring;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase P2 Fix 1 — the side-metrics grouped by a non-existent `side` column.
 *
 * grid_orders has no `side` column; the buy/sell dimension lives in `type`
 * (enum buy/sell — see the create_grid_orders migration and GridOrder). Every
 * metric that split orders by side (grid health, capital concentration, grid
 * drift) filtered on `where('side', ...)`, which matched nothing, so the buy
 * and sell buckets were always empty even with real orders.
 *
 * P4-final note: these metrics were relocated verbatim from the former
 * BotIntelDashboard into BotMonitoring when the two status pages were merged
 * into one «مانیتورینگ زنده». The assertions are unchanged — only the class
 * under test moved with the code.
 *
 * This test builds a bot with real buy + sell placed grid_orders and asserts
 * the type-based metrics now bucket them correctly. Against the pre-fix
 * `side` query every count below would be 0 and the drift would read as if the
 * grid held no buy orders — so this fails before the fix and passes after it.
 */
final class BotIntelDashboardSideMetricsTest extends TestCase
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

    public function test_capital_concentration_and_drift_bucket_orders_by_type(): void
    {
        $bot = BotConfigFactory::new()->create();

        // Two buy + one sell order, all 'placed' (an active status the metrics count).
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
        GridOrderFactory::new()->sell()->placed()->create(['bot_config_id' => $bot->id]);

        $page = new BotMonitoring();
        $page->selectedBot = $bot->fresh();

        // Capital concentration must see 2 buys and 1 sell — proof the `type`
        // filter matches. With the old `side` query both counts would be 0.
        $concentration = $page->getCapitalConcentration();
        $this->assertSame(2, $concentration['buy']['count'], 'Buy orders must bucket by type=buy.');
        $this->assertSame(1, $concentration['sell']['count'], 'Sell orders must bucket by type=sell.');

        // Grid drift is derived from the buy proportion (2 of 3 = ~66.7%). It
        // must report a real trading zone, not the empty-grid 'No Data' branch,
        // and the position must reflect a buy-heavy (lower-of-grid) skew.
        $drift = $page->getGridDrift();
        $this->assertNotSame('No Data', $drift['status'], 'Drift must compute from type-bucketed orders.');
        $this->assertLessThan(50, $drift['position'], 'A buy-heavy grid sits in the lower half.');
    }
}
