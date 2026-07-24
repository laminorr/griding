<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\BotConfig;
use App\Models\GridOrder;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 13 Step 5 — the single behaviour test this step is allowed.
 *
 * Its whole purpose is to PROVE the BuildsGridSchema extension worked: that the
 * globally-registered GridOrderObserver can now actually write
 * open_cycles_count on the owning bot instead of hitting a missing column and
 * having the write silently swallowed by its try/catch(\Throwable).
 *
 * Before this step the bot_configs table built by the trait had no
 * open_cycles_count / capital_locked_irt column, so the observer's
 * BotConfig::update() threw "no such column", the catch logged a warning, and
 * the assertion below would have seen the count stay NULL — a FALSE GREEN for
 * any Step-10 observer test. If this test fails, the trait is still wrong; do
 * not weaken the assertion to match.
 */
final class GridOrderObserverInventoryTest extends TestCase
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

    public function test_creating_a_cycle_exit_order_updates_open_cycles_count_on_the_bot(): void
    {
        $bot = BotConfigFactory::new()->create();

        // "Not yet computed" is the migration's documented meaning of NULL, and
        // nothing has touched the counter yet.
        $this->assertNull(
            $bot->open_cycles_count,
            'Pre-condition: open_cycles_count starts NULL (not yet computed).'
        );

        // A cycle_exit order sitting at 'placed' is, by the observer's
        // definition, one open cycle.
        GridOrderFactory::new()
            ->cycleExit()
            ->placed()
            ->buy()
            ->create(['bot_config_id' => $bot->id]);

        // The observer's saved() hook fired on that insert and recomputed the
        // counter. The persisted bot must now reflect exactly one open cycle —
        // proof the column exists and the write landed.
        $this->assertSame(
            1,
            (int) $bot->fresh()->open_cycles_count,
            'The observer must have written open_cycles_count = 1 after the cycle_exit order was placed.'
        );

        // And it tracks state changes: flipping the only open cycle to 'filled'
        // drops the count back to zero (0 = computed-and-empty, distinct from
        // the NULL not-yet-computed we started at).
        $order = GridOrder::query()->where('bot_config_id', $bot->id)->firstOrFail();
        $order->update(['status' => 'filled']);

        $this->assertSame(
            0,
            (int) $bot->fresh()->open_cycles_count,
            'Filling the cycle_exit order must recompute open_cycles_count back to 0.'
        );
    }
}
