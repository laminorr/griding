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
 * Cleanup 6a, Fix 3: AdjustGridJob's symbol whitelist must come from
 * config('trading.adjust_grid.allowed_symbols'), not a direct env() read (which
 * returns null under `php artisan config:cache` and collapses the whitelist).
 *
 * These tests bind a config value and assert the job HONOURS it: the symbol
 * whitelist check runs at the top of the per-bot loop, BEFORE the Kill Switch
 * and the planner/executor, so a bot whose symbol is excluded is skipped without
 * KillSwitchService::checkAndTrigger ever being called. That gate is the
 * observable proof the job read the config list.
 */
final class AdjustGridAllowedSymbolsTest extends TestCase
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
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Run AdjustGridJob::handle() with stub collaborators. Only the Kill Switch
     * is a real expectation target; the rest are never reached on the paths
     * under test (whitelist skip happens first; a triggered kill switch
     * short-circuits before planner/executor).
     */
    private function runJob(KillSwitchService $killSwitch): void
    {
        (new AdjustGridJob())->handle(
            $this->createMock(GridPlanner::class),
            $this->createMock(GridOrderSync::class),
            $this->createMock(OrderRegistry::class),
            $this->createMock(GridOrderExecutor::class),
            $killSwitch,
        );
    }

    public function test_bot_is_skipped_when_its_symbol_is_not_in_the_config_whitelist(): void
    {
        // Config excludes BTCIRT — the active bot's symbol.
        config(['trading.adjust_grid.allowed_symbols' => ['ETHIRT']]);

        BotConfigFactory::new()->active()->create(['symbol' => 'BTCIRT']);

        // Proof the whitelist blocked the bot: the Kill Switch is never reached.
        $killSwitch = $this->createMock(KillSwitchService::class);
        $killSwitch->expects($this->never())->method('checkAndTrigger');

        $this->runJob($killSwitch);
    }

    public function test_bot_is_processed_when_its_symbol_is_in_the_config_whitelist(): void
    {
        // Config includes BTCIRT — the active bot's symbol.
        config(['trading.adjust_grid.allowed_symbols' => ['BTCIRT']]);

        BotConfigFactory::new()->active()->create(['symbol' => 'BTCIRT']);

        // The bot clears the whitelist, so the Kill Switch IS consulted. Return
        // triggered=true to short-circuit before any planner/executor work.
        $killSwitch = $this->createMock(KillSwitchService::class);
        $killSwitch->expects($this->once())
            ->method('checkAndTrigger')
            ->willReturn(['triggered' => true, 'reason' => 'test', 'details' => []]);

        $this->runJob($killSwitch);
    }

    /**
     * The config key must be populated from the env-backed default the job
     * historically used (TRADING_ALLOWED_SYMBOLS, default 'BTCIRT') so behaviour
     * is unchanged when nothing overrides it.
     */
    public function test_config_key_defaults_to_btcirt(): void
    {
        $this->assertSame(['BTCIRT'], config('trading.adjust_grid.allowed_symbols'));
    }
}
