<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AdjustGridJob;
use App\Services\GridOrderExecutor;
use App\Services\GridOrderSync;
use App\Services\GridPlanner;
use App\Services\KillSwitchService;
use App\Support\OrderRegistry;
use Database\Factories\BotConfigFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Bug fix — rebalance bookkeeping (rebalance_count / last_rebalance_at).
 *
 * Before the fix, AdjustGridJob placed rebalance orders but NEVER persisted the
 * two bot-level counters:
 *   • rebalance_count had no increment anywhere in the codebase (stuck at 0),
 *   • last_rebalance_at was written only on the initial-grid path
 *     (TradingEngineService), so it stayed equal to started_at forever.
 *
 * These tests drive the real AdjustGridJob::handle() with stub collaborators up
 * to the applyForBot(role:'rebalance') call, controlling ONLY the diff, and
 * assert the counters move exactly once — and, critically, do NOT move on a
 * no-op tick (empty diff). The GridOrderSync::diff() return value is the single
 * lever that decides whether a real rebalance happened.
 *
 * NOTE (bcmath): handle() runs its available-budget check through App\Support\
 * Money (ext-bcmath). A container without bcmath will error these tests the same
 * way MoneyTest errors there — that is a polyfill artifact, not a regression.
 * The host verifier has bcmath.
 */
final class AdjustGridBookkeepingTest extends TestCase
{
    use BuildsGridSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        // The bot's symbol must clear the job's whitelist so the run reaches the
        // planner/diff/executor stage where bookkeeping happens.
        config(['trading.adjust_grid.allowed_symbols' => ['BTCIRT']]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Build an active simulation bot with a positive available budget (no locked
     * capital) and a last_rebalance_at planted in the past so we can assert it
     * advances. Kill Switch inputs are sane so checkAndTrigger (mocked here) is
     * the only thing that could stop the run.
     */
    private function makeBot(\DateTimeInterface $lastRebalanceAt): \App\Models\BotConfig
    {
        return BotConfigFactory::new()->active()->create([
            'symbol'             => 'BTCIRT',
            'simulation'         => true,
            'mode'               => 'both',
            'total_capital'      => 50_000_000, // positive effective budget (locked = 0)
            'capital_locked_irt' => null,
            'rebalance_count'    => 0,
            'last_rebalance_at'  => $lastRebalanceAt,
        ]);
    }

    /** Kill Switch that lets the bot through (nothing tripped). */
    private function passthroughKillSwitch(): KillSwitchService
    {
        $killSwitch = $this->createMock(KillSwitchService::class);
        $killSwitch->method('checkAndTrigger')
            ->willReturn(['triggered' => false, 'reason' => null, 'details' => []]);

        return $killSwitch;
    }

    /**
     * @param array<string,mixed> $diff  the value GridOrderSync::diff() returns
     */
    private function runJobWithDiff(array $diff): void
    {
        $planner = $this->createMock(GridPlanner::class);
        $planner->method('plan')->willReturn([
            'symbol' => 'BTCIRT',
            'mid'    => 100_000_000,
            'tick'   => 1,
        ]);

        // No existing open orders → the price-in-range gate is skipped, so the
        // run always reaches the diff/apply stage; the diff itself decides.
        $reg = $this->createMock(OrderRegistry::class);
        $reg->method('getOpenForBot')->willReturn([]);

        $sync = $this->createMock(GridOrderSync::class);
        $sync->method('diff')->willReturn($diff);

        // The executor is a stub — we are testing the JOB's bookkeeping, not the
        // order placement (covered separately in GridOrderExecutorTest).
        $exec = $this->createMock(GridOrderExecutor::class);

        (new AdjustGridJob())->handle($planner, $sync, $reg, $exec, $this->passthroughKillSwitch());
    }

    /**
     * BUG 1 & 2: a rebalance whose diff actually places/cancels orders bumps
     * rebalance_count by exactly 1 and advances last_rebalance_at to ~now.
     */
    public function test_real_rebalance_increments_count_and_advances_timestamp(): void
    {
        $past = now()->subDays(3);
        $bot  = $this->makeBot($past);

        $this->runJobWithDiff([
            'symbol'   => 'BTCIRT',
            'tick'     => 1,
            'to_place' => [[
                'side'     => 'buy',
                'price'    => 99_000_000,
                'quantity' => '0.001',
                'notional' => 99_000,
            ]],
        ]);

        $bot->refresh();
        $this->assertSame(1, (int) $bot->rebalance_count, 'rebalance_count must go 0 → 1 on a real rebalance');
        $this->assertNotNull($bot->last_rebalance_at);
        $this->assertTrue(
            $bot->last_rebalance_at->greaterThan($past),
            'last_rebalance_at must advance past the planted initial value'
        );
        $this->assertTrue(
            $bot->last_rebalance_at->greaterThan(now()->subMinute()),
            'last_rebalance_at must be ~now'
        );
    }

    /**
     * Guard for the "only on a real rebalance" rule: a no-op tick (empty diff —
     * nothing to place or cancel) must NOT touch rebalance_count or move
     * last_rebalance_at, so idle 10-minute ticks cannot inflate the counter.
     */
    public function test_noop_tick_with_empty_diff_leaves_bookkeeping_unchanged(): void
    {
        $bot = $this->makeBot(now()->subDays(3));

        // Read back the DB-canonical stored value (avoids any datetime-precision
        // mismatch between the in-memory Carbon and the persisted column).
        $before = $bot->fresh()->last_rebalance_at;

        $this->runJobWithDiff([
            'symbol'    => 'BTCIRT',
            'tick'      => 1,
            'to_place'  => [],
            'to_cancel' => [],
        ]);

        $bot->refresh();
        $this->assertSame(0, (int) $bot->rebalance_count, 'a no-op tick must not increment rebalance_count');
        $this->assertTrue(
            $bot->last_rebalance_at->equalTo($before),
            'a no-op tick must not move last_rebalance_at'
        );
    }
}
