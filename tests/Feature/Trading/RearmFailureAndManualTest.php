<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\GridRearmer;
use App\Services\MarketDataLayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Hotfix for bot 49 / exit 294: a re-arm that fails on every attempt must
 * not stay silent for the whole fill-age window (REARM_STUCK, REARM_ABANDONED,
 * bot:audit), and an operator can restore the dark level by hand
 * (`grid:rearm {exit} [--apply]`) through the normal GridRearmer path.
 */
final class RearmFailureAndManualTest extends TestCase
{
    use BuildsGridSchema;

    private const LEVEL = 200_000_000_000;
    private const QTY   = '0.001';
    private const TRUNCATED = "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'role' at row 1";

    private int $market = 0;
    private bool $failRearmInsert = false;
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
            'trading.rearm.max_fill_age_minutes' => 60,
        ]);

        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->andReturnUsing(fn () => $this->market);
        $this->app->instance(MarketDataLayer::class, $md);

        $this->log = Mockery::spy(LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->andReturn($this->log);

        // What production's ENUM did to every role='rearm' insert.
        GridOrder::creating(function (GridOrder $o) {
            if ($this->failRearmInsert && $o->role === GridOrder::ROLE_REARM) {
                throw new \RuntimeException(self::TRUNCATED);
            }
        });
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    private function bot(array $over = []): BotConfig
    {
        return BotConfig::create(array_merge([
            'name' => 'rearm', 'symbol' => 'BTCIRT', 'simulation' => true,
            'is_active' => true, 'grid_spacing' => 1.50, 'rearm_exits' => true,
        ], $over));
    }

    private function root(BotConfig $bot, string $side, int $price = self::LEVEL, array $over = []): GridOrder
    {
        static $n = 0;
        $n++;
        return GridOrder::create(array_merge([
            'bot_config_id' => $bot->id, 'type' => $side, 'status' => 'placed', 'price' => (string) $price,
            'amount' => self::QTY, 'original_amount' => self::QTY, 'role' => 'initial_grid', 'grid_generation' => 0,
            'client_order_id' => "root-{$n}", 'nobitex_order_id' => "SIM-ROOT-{$n}",
        ], $over));
    }

    private function tick(): void
    {
        (new CheckTradesJob())->handle();
    }

    /** Buy-first level (like bot 49's 213201187160): root buy fills, exit sell fills. Returns the filled exit. */
    private function closedBuyFirstCycle(BotConfig $bot): GridOrder
    {
        $root = $this->root($bot, 'buy');
        $this->market = self::LEVEL - 1;
        $this->tick();
        $exit = GridOrder::findOrFail($root->fresh()->paired_order_id);
        $this->market = (int) $exit->price + 1;
        $this->tick();
        $exit = $exit->fresh();
        $this->assertSame(['sell', 'cycle_exit', 'filled'], [$exit->type, $exit->role, $exit->status]);
        return $exit;
    }

    private function rearms(BotConfig $bot)
    {
        return GridOrder::where('bot_config_id', $bot->id)->where('role', 'rearm')->get();
    }

    /** How many times $event was logged at $level (optionally matching its context). */
    private function logged(string $level, string $event, ?callable $ctx = null): int
    {
        $n = 0;
        foreach ($this->calls($level) as [$m, $c]) {
            if ($m === $event && ($ctx === null || $ctx($c))) {
                $n++;
            }
        }
        return $n;
    }

    /** @return list<array{0:string,1:array}> */
    private function calls(string $level): array
    {
        $out = [];
        try {
            $this->log->shouldHaveReceived($level)->withArgs(function ($m, $c = []) use (&$out) {
                $out[] = [$m, $c];
                return true;
            });
        } catch (\Mockery\Exception\InvalidCountException) {
            return [];   // never called at this level
        }
        return $out;
    }

    private function snapshot(): array
    {
        return [
            GridOrder::orderBy('id')->get()->map(fn ($o) => $o->getAttributes())->all(),
            DB::table('bot_configs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('completed_trades')->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* E. louder failure                                                   */
    /* ------------------------------------------------------------------ */

    public function test_repeated_intent_failures_log_one_stuck_then_one_abandoned(): void
    {
        $bot = $this->bot();
        $this->failRearmInsert = true;
        $exit = $this->closedBuyFirstCycle($bot);   // first attempt(s) fail inside this tick

        $rearmer = app(GridRearmer::class);
        for ($i = 0; $i < 6; $i++) {
            $rearmer->sweep($bot);
        }

        $exit = $exit->fresh();
        [$failures, $kind] = GridRearmer::parseRetryState((string) $exit->rearm_state);
        $this->assertGreaterThanOrEqual(6, $failures);
        $this->assertSame('INTENT_FAILED', $kind);
        $this->assertNull($exit->rearm_order_id);
        $this->assertCount(0, $this->rearms($bot), 'no partial rows');
        $this->assertSame($failures, $this->logged('error', 'REARM_INTENT_FAILED'));

        // ONE critical, at the 3rd failure, with the error.
        $this->assertSame(1, $this->logged('critical', 'REARM_STUCK'));
        $this->assertSame(1, $this->logged('critical', 'REARM_STUCK', fn ($c) => $c['bot_id'] === $bot->id
            && $c['exit_order_id'] === $exit->id && $c['error'] === self::TRUNCATED && $c['failures'] === 3));
        $this->assertSame(0, $this->logged('error', 'REARM_ABANDONED'));

        // The window passes without a success → ONE abandoned, no more attempts.
        GridOrder::whereKey($exit->id)->update(['filled_at' => now()->subMinutes(61)]);
        $rearmer->sweep($bot);
        $rearmer->sweep($bot);
        $this->tick();

        $this->assertSame('abandoned:INTENT_FAILED', $exit->fresh()->rearm_state);
        $this->assertSame(1, $this->logged('error', 'REARM_ABANDONED', fn ($c) => $c['exit_order_id'] === $exit->id
            && $c['failures'] === $failures && $c['last_failure'] === 'INTENT_FAILED'));
        $this->assertSame(1, $this->logged('error', 'REARM_ABANDONED'));
        $this->assertSame($failures, $this->logged('error', 'REARM_INTENT_FAILED'), 'not retried after the window');
        $this->assertSame(1, $this->logged('critical', 'REARM_STUCK'));

        // bot:audit: a simulation bot → warning.
        $A = $this->audit($bot);
        $ab = array_values(array_filter($A['anomalies'], fn ($a) => $a['code'] === 'REARM_ABANDONED'));
        $this->assertCount(1, $ab);
        $this->assertSame('warning', $ab[0]['severity']);
        $this->assertSame([$exit->id], $ab[0]['rows']);
        $this->assertSame([$exit->id], $A['orders']['rearm']['abandoned']);

        // …and the operator can still restore it once the cause is fixed.
        $this->failRearmInsert = false;
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])->assertExitCode(0);
        $this->assertSame('placed', $exit->fresh()->rearm_state);
        $this->assertCount(1, $this->rearms($bot));
    }

    public function test_a_success_before_the_third_failure_logs_no_stuck(): void
    {
        $bot = $this->bot();
        $this->failRearmInsert = true;
        $exit = $this->closedBuyFirstCycle($bot);
        [$failures] = GridRearmer::parseRetryState((string) $exit->fresh()->rearm_state);
        $this->assertGreaterThanOrEqual(1, $failures);
        $this->assertLessThan(3, $failures);

        $this->failRearmInsert = false;
        app(GridRearmer::class)->sweep($bot);

        $exit = $exit->fresh();
        $this->assertSame('placed', $exit->rearm_state);
        $this->assertCount(1, $this->rearms($bot));
        $this->assertSame(0, $this->logged('critical', 'REARM_STUCK'));
        GridOrder::whereKey($exit->id)->update(['filled_at' => now()->subMinutes(61)]);
        app(GridRearmer::class)->sweep($bot);
        $this->assertSame(0, $this->logged('error', 'REARM_ABANDONED'));
    }

    public function test_audit_flags_abandoned_and_stuck_as_critical_on_a_live_bot(): void
    {
        $bot  = $this->bot(['simulation' => false]);
        $root = $this->root($bot, 'buy', self::LEVEL, ['status' => 'filled', 'filled_at' => now()->subHours(3)]);
        $mk = fn (string $cid, string $state, int $price) => GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'sell', 'status' => 'filled', 'price' => (string) $price, 'amount' => self::QTY,
            'role' => 'cycle_exit', 'paired_order_id' => $root->id, 'client_order_id' => $cid, 'rearm_state' => $state,
            'filled_at' => now()->subHours(2),
        ]);
        $abandoned = $mk('ab', 'abandoned:INTENT_FAILED', self::LEVEL + 3_000_000_000);
        $stuck     = $mk('st', 'retry:4:INTENT_FAILED', self::LEVEL + 3_010_000_000);

        $A = $this->audit($bot, [
            '[' . now()->subMinutes(5)->format('Y-m-d H:i:s') . '] production.CRITICAL: REARM_STUCK '
                . json_encode(['bot_id' => $bot->id, 'exit_order_id' => $stuck->id, 'error' => self::TRUNCATED, 'failures' => 3]),
        ]);

        $by = [];
        foreach ($A['anomalies'] as $a) {
            $by[$a['code']] = $a;
        }
        $this->assertSame('critical', $by['REARM_ABANDONED']['severity']);
        $this->assertSame([$abandoned->id], $by['REARM_ABANDONED']['rows']);
        $this->assertSame('critical', $by['REARM_STUCK']['severity']);
        $this->assertSame([$stuck->id], $by['REARM_STUCK']['rows']);
        $this->assertStringContainsString('Data truncated', $by['REARM_STUCK']['message']);
    }

    /* ------------------------------------------------------------------ */
    /* D. grid:rearm                                                       */
    /* ------------------------------------------------------------------ */

    /** Exit 294's situation: filled long ago, no re-arm decision, window expired. */
    private function darkLevelOutsideTheWindow(BotConfig $bot): GridOrder
    {
        BotConfig::whereKey($bot->id)->update(['rearm_exits' => false]);
        $exit = $this->closedBuyFirstCycle($bot);
        GridOrder::whereKey($exit->id)->update(['filled_at' => now()->subHours(3)]);
        BotConfig::whereKey($bot->id)->update(['rearm_exits' => true]);
        $this->tick();   // the sweep leaves it alone: outside the window, never failed
        $exit = $exit->fresh();
        $this->assertNull($exit->rearm_state);
        $this->assertNull($exit->rearm_order_id);
        return $exit;
    }

    public function test_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $bot  = $this->bot();
        $exit = $this->darkLevelOutsideTheWindow($bot);
        $before = $this->snapshot();

        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id])
            // (one expectation per output line: Mockery matches a write once)
            ->expectsOutputToContain('min ago; sweep window 60 min — outside it, bypassed by this command')
            ->expectsOutputToContain('Would place: buy 0.001 @ ' . self::LEVEL)
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $this->logged('warning', 'REARM_MANUAL'));
        $this->assertSame(0, $this->logged('info', 'REARM_PLACED'));

        $p = app(GridRearmer::class)->preview($exit, $bot);
        $this->assertTrue($p['would_place']);
        $this->assertSame(['buy', (string) self::LEVEL, '0.001'], [$p['side'], $p['price'], \App\Support\Money::trimZeros($p['amount'])]);
        $this->assertSame(['bot' => 0, 'level' => 0, 'ok' => true], $p['generation']);
        $this->assertFalse($p['level_busy']);
        $this->assertTrue($p['outside_window']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_places_exactly_one_rearm_through_the_normal_path(): void
    {
        $bot  = $this->bot();
        $exit = $this->darkLevelOutsideTheWindow($bot);
        $root = GridOrder::findOrFail($exit->paired_order_id);

        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])
            ->expectsOutputToContain('Placed re-arm')
            ->assertExitCode(0);

        $exit = $exit->fresh();
        $rearms = $this->rearms($bot);
        $this->assertCount(1, $rearms);
        $r = $rearms->first();
        $this->assertSame(['buy', 'rearm', 'placed'], [$r->type, $r->role, $r->status]);
        $this->assertSame(0, \App\Support\Money::compare((string) $r->price, (string) self::LEVEL));
        $this->assertSame([$exit->id, $root->id, 0], [$r->rearm_exit_order_id, $r->rearm_root_order_id, $r->grid_generation]);
        $this->assertSame('g' . $bot->id . '-' . $r->id, $r->client_order_id);
        $this->assertSame([$r->id, 'placed'], [$exit->rearm_order_id, $exit->rearm_state]);
        $this->assertSame(1, $this->logged('info', 'REARM_PLACED'));
        $this->assertSame(1, $this->logged('warning', 'REARM_MANUAL', fn ($c) => $c['exit_order_id'] === $exit->id
            && $c['result'] === 'placed' && $c['rearm_order_id'] === $r->id));

        // A second --apply is refused: one exit → one re-arm.
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])
            ->expectsOutputToContain('Refused')
            ->assertExitCode(1);
        $this->assertCount(1, $this->rearms($bot));

        // The re-armed level is an ordinary grid order: its fill gets an exit.
        $this->market = self::LEVEL - 1;
        $this->tick();
        $this->assertNotNull($r->fresh()->paired_order_id);
    }

    public function test_refusals_write_nothing(): void
    {
        $bot  = $this->bot();
        $exit = $this->darkLevelOutsideTheWindow($bot);

        // Unknown row / not a filled exit.
        $this->artisan('grid:rearm', ['exitOrderId' => 999999])->assertExitCode(1);
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->paired_order_id, '--apply' => true])
            ->expectsOutputToContain('not a filled cycle_exit')->assertExitCode(1);

        // Flag off.
        BotConfig::whereKey($bot->id)->update(['rearm_exits' => false]);
        $before = $this->snapshot();
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])
            ->expectsOutputToContain('rearm_exits OFF')->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
        BotConfig::whereKey($bot->id)->update(['rearm_exits' => true]);

        // A decision already recorded.
        foreach (['placed', 'skipped:LEVEL_BUSY'] as $state) {
            GridOrder::whereKey($exit->id)->update(['rearm_state' => $state]);
            $before = $this->snapshot();
            $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])
                ->expectsOutputToContain('already has a re-arm or a final decision')->assertExitCode(1);
            $this->assertSame($before, $this->snapshot(), $state);
        }
        GridOrder::whereKey($exit->id)->update(['rearm_state' => null, 'rearm_order_id' => 12345]);
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])->assertExitCode(1);
        GridOrder::whereKey($exit->id)->update(['rearm_order_id' => null]);

        // A gate that refuses: the level is busy → nothing written, even with --apply.
        $this->root($bot, 'buy', self::LEVEL, ['role' => 'rebalance', 'client_order_id' => 'busy']);
        $before = $this->snapshot();
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id, '--apply' => true])
            ->expectsOutputToContain('Would NOT place: LEVEL_BUSY')->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($exit->fresh()->rearm_state);

        // Inactive bot (and the dry-run never trips anything).
        GridOrder::where('client_order_id', 'busy')->delete();
        BotConfig::whereKey($bot->id)->update(['is_active' => false]);
        $before = $this->snapshot();
        $this->artisan('grid:rearm', ['exitOrderId' => $exit->id])
            ->expectsOutputToContain('Would NOT place: BOT_INACTIVE')->assertExitCode(1);
        $this->assertSame($before, $this->snapshot());
        $this->assertCount(0, $this->rearms($bot));
    }

    /* ------------------------------------------------------------------ */

    private function audit(BotConfig $bot, array $logLines = []): array
    {
        $dir = storage_path('framework/testing/rearm-fail-audit-' . uniqid());
        File::ensureDirectoryExists($dir);
        if ($logLines !== []) {
            file_put_contents($dir . '/trading-' . now()->format('Y-m-d') . '.log', implode("\n", $logLines) . "\n");
        }
        try {
            $A = app(\App\Services\Audit\BotAuditor::class)->audit($bot->id, [
                'from' => null, 'to' => null, 'start_rls' => null, 'start_btc' => null, 'no_exchange' => true,
                'candles' => '1h', 'synced' => [], 'log_dir' => $dir, 'rate_ms' => 300,
            ]);
            $md = app(\App\Services\Audit\AuditReportRenderer::class)->write($A, $dir . '/out')['report.md'];
            $this->assertStringContainsString('Abandoned exits:', (string) file_get_contents($md));
            return $A;
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
