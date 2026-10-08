<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AdjustGridJob;
use App\Models\BotConfig;
use App\Services\GridCalculatorService;
use App\Services\GridOrderExecutor;
use App\Services\GridOrderSync;
use App\Services\GridPlanner;
use App\Services\KillSwitchService;
use App\Contracts\MarketData;
use App\Services\NobitexService;
use App\Support\OrderRegistry;
use Database\Factories\BotConfigFactory;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Live audit (bot 48) — a rebalance must deploy the same ACTIVE budget the
 * initial grid does: total_capital × active_capital_percent / 100, minus the
 * capital locked in open cycles. Before the fix AdjustGridJob planned with the
 * whole total_capital (GRID_PLAN budget_irt 50,000,000 for an 80% bot).
 */
final class AdjustGridActiveBudgetTest extends TestCase
{
    use BuildsGridSchema;

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

    /** Every Log::channel(...) call lands on one spy we can assert against. */
    private function spyLogs(): \Mockery\MockInterface
    {
        $log = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->andReturn($log);

        return $log;
    }

    private function makeBot(array $attrs = []): BotConfig
    {
        return BotConfigFactory::new()->active()->create(array_merge([
            'symbol'                 => 'BTCIRT',
            'simulation'             => true,
            'mode'                   => 'both',
            'grid_levels'            => 4,
            'total_capital'          => 50_000_000,
            'active_capital_percent' => 80,
            'capital_locked_irt'     => null,
        ], $attrs));
    }

    /**
     * Run the real job with stub collaborators; return the budgetIrt the job
     * handed to GridPlanner::plan(), or null when it never planned.
     */
    private function plannedBudget(): ?int
    {
        $captured = null;

        $planner = $this->createMock(GridPlanner::class);
        $planner->method('plan')->willReturnCallback(
            function (...$args) use (&$captured) {
                // plan($symbol, $lastPrice, $levels, $stepPct, $mode, $budgetIrt, …)
                $captured = $args[5] ?? null;

                return ['symbol' => 'BTCIRT', 'mid' => 100_000_000, 'tick' => 1];
            }
        );

        $reg = $this->createMock(OrderRegistry::class);
        $reg->method('getOpenForBot')->willReturn([]);
        $sync = $this->createMock(GridOrderSync::class);
        $sync->method('diff')->willReturn(['symbol' => 'BTCIRT', 'tick' => 1, 'to_place' => [], 'to_cancel' => []]);
        $exec = $this->createMock(GridOrderExecutor::class);
        $kill = $this->createMock(KillSwitchService::class);
        $kill->method('checkAndTrigger')->willReturn(['triggered' => false, 'reason' => null, 'details' => []]);

        (new AdjustGridJob())->handle($planner, $sync, $reg, $exec, $kill);

        return $captured;
    }

    public function test_active_budget_helper_applies_the_percent_exactly(): void
    {
        $this->assertSame('40000000', $this->makeBot()->activeBudgetIrt());
        $this->assertSame('50000000', $this->makeBot(['active_capital_percent' => 100])->activeBudgetIrt());
        // BCMath, floored to whole rial: 100,000,001 × 33.33% = 33,330,000.3333 → 33,330,000.
        $this->assertSame('33330000', $this->makeBot(['total_capital' => 100_000_001, 'active_capital_percent' => '33.33'])->activeBudgetIrt());
        $this->assertSame('12345678', $this->makeBot(['total_capital' => '12345678', 'active_capital_percent' => 100])->activeBudgetIrt());
    }

    public function test_rebalance_plans_with_eighty_percent_of_total_capital(): void
    {
        $this->makeBot();

        $this->assertSame(40_000_000, $this->plannedBudget());
    }

    public function test_rebalance_deducts_locked_capital_from_the_active_budget(): void
    {
        $this->makeBot(['capital_locked_irt' => '12500000']);

        $this->assertSame(27_500_000, $this->plannedBudget());
    }

    public function test_locked_capital_above_active_budget_skips_without_planning(): void
    {
        // 45M locked < 50M total, but > 40M active: nothing left to deploy.
        $this->makeBot(['capital_locked_irt' => '45000000']);
        $log = $this->spyLogs();

        $this->assertNull($this->plannedBudget());

        $log->shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx = []) => $msg === 'REBALANCE_SKIP_NO_AVAILABLE_BUDGET'
                && $ctx['total_budget'] === '50000000'
                && $ctx['active_budget'] === '40000000'
                && $ctx['effective_budget'] === '0'
        )->once();
    }

    public function test_hundred_percent_keeps_the_previous_behaviour(): void
    {
        $this->makeBot(['active_capital_percent' => 100]);

        $this->assertSame(50_000_000, $this->plannedBudget());
    }

    public function test_effective_budget_log_carries_total_and_active_budget(): void
    {
        $this->makeBot(['capital_locked_irt' => '10000000']);
        $log = $this->spyLogs();

        $this->assertSame(30_000_000, $this->plannedBudget());

        $log->shouldHaveReceived('info')->withArgs(
            fn ($msg, $ctx = []) => $msg === 'REBALANCE_EFFECTIVE_BUDGET'
                && $ctx['total_budget'] === '50000000'
                && $ctx['active_budget'] === '40000000'
                && $ctx['locked_capital'] === '10000000'
                && $ctx['effective_budget'] === '30000000'
        )->once();
    }

    public function test_null_percent_defaults_to_hundred_with_a_warning(): void
    {
        $bot = $this->makeBot();
        // Bypass the creating() default the way a legacy NULL row would look.
        BotConfig::whereKey($bot->id)->update(['active_capital_percent' => null]);
        $log = $this->spyLogs();

        $this->assertSame(50_000_000, $this->plannedBudget());

        $log->shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx = []) => $msg === 'ACTIVE_CAPITAL_PERCENT_DEFAULTED' && $ctx['used'] === '100'
        )->atLeast()->once();
    }

    public function test_out_of_range_percent_skips_the_rebalance_like_initialize_grid_refuses(): void
    {
        $bot = $this->makeBot();
        BotConfig::whereKey($bot->id)->update(['active_capital_percent' => 150]);
        $log = $this->spyLogs();

        $this->assertNull($this->plannedBudget());

        $log->shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx = []) => $msg === 'REBALANCE_SKIP_INVALID_ACTIVE_CAPITAL_PERCENT'
        )->once();

        $this->expectException(\InvalidArgumentException::class);
        $bot->fresh()->activeBudgetIrt();
    }

    /**
     * Initial placement (GridCalculatorService::calculateOrderSize with the
     * bot's resolved percent — what initializeGrid calls) and a rebalance
     * (GridPlanner fed the job's budget) size each level identically.
     */
    public function test_initial_placement_and_rebalance_size_levels_identically(): void
    {
        $price = 10_000_000_000; // 10B rial / BTC
        $bot   = $this->makeBot();

        $nobitex = $this->createMock(NobitexService::class);
        $nobitex->method('getCurrentPrice')->willReturn((float) $price);
        $initial = (new GridCalculatorService($nobitex))->calculateOrderSize(
            (float) $bot->total_capital,
            (float) $bot->activeCapitalPercent(),
            (int) $bot->grid_levels,
            'BTCIRT'
        );
        $this->assertTrue($initial['success'], (string) ($initial['error'] ?? ''));

        $budget  = $this->plannedBudget();
        $planner = new GridPlanner($this->createMock(MarketData::class));
        $plan    = $planner->plan('BTCIRT', lastPrice: $price, levels: 4, stepPct: 1.0, mode: 'both', budgetIrt: $budget);

        $this->assertEquals(10_000_000, $initial['irt_value_per_order']);
        foreach ($plan['items'] as $item) {
            $this->assertSame(10_000_000, $item['notional'], 'rebalance per-level notional must match initial placement');
        }
        $this->assertCount(4, $plan['items']);
    }
}
