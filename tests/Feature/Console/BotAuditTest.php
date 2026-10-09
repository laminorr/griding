<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\ExitSizer;
use App\Services\FeeModel;
use App\Support\MarketPrecision;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * bot:audit on a seeded bot modelled on bot 48 (BTCIRT, 50M total, 80% active,
 * spacing 1.5%, 2+2 levels): 12 fills, 6 booked cycles, 2 open orders, one
 * rebalance, one fee-rate-drift fill (32 bps), one duplicate-exit trap, and
 * gz + plain fixture logs. Every derived row (fee fields, exit price, exit
 * size, dust, cycle booking) is produced by the PRODUCTION helpers
 * (FeeModel::fillFields, MarketPrecision::roundPrice, ExitSizer::compute,
 * CompletedTrade::createFromOrders) so the audit's independent recomputation
 * is checked against what the bot itself would have written.
 */
final class BotAuditTest extends TestCase
{
    use BuildsGridSchema;

    private const START_RLS = '126387058';
    private const START_BTC = '0.000299721';
    private const TO = '2026-10-09 12:00:00';

    private BotConfig $bot;
    private string $logDir;
    private string $dust = '0';
    /** @var list<string> log lines (production format) */
    private array $logLines = [];
    /** @var array<string,array> nobitex id → exchange payload */
    private array $exchange = [];
    private array $wallet = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        Sleep::fake();

        $seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        config([
            'app.timezone' => 'UTC',
            'trading.exchange.precision_live' => false,
            'trading.nobitex.base_url' => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_public_key' => 'my-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            'trading.nobitex.retry.times' => 1,
            'trading.nobitex.retry.sleep' => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
        ]);

        $this->logDir = storage_path('framework/testing/bot-audit-logs-' . uniqid());
        File::ensureDirectoryExists($this->logDir);

        $this->seedBot48();
        $this->writeLogs();
        $this->fakeExchange();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDir);
        File::deleteDirectory(storage_path('app/audits'));
        $this->dropGridSchema();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function seedBot48(): void
    {
        $this->bot = new BotConfig();
        $this->bot->forceFill([
            'id' => 48, 'name' => 'bot48', 'symbol' => 'BTCIRT', 'simulation' => false, 'is_active' => true,
            'total_capital' => '50000000', 'active_capital_percent' => 80, 'grid_spacing' => 1.50, 'grid_levels' => 4, 'levels' => 4,
            'started_at' => '2026-10-02 22:41:00', 'base_dust' => '0',
        ])->save();
        // A second bot whose log lines must be filtered out.
        (new BotConfig())->forceFill(['id' => 49, 'name' => 'other', 'symbol' => 'BTCIRT', 'simulation' => true])->save();

        $t0 = '2026-10-02 22:41:00';
        $this->log('2026-10-02 22:40:59', 'INFO', 'GRID_PLAN', ['symbol' => 'BTCIRT', 'mid' => 225000000000, 'levels' => 4, 'budget_irt' => 40000000, 'step_pct' => 1.5, 'estimated_notional' => 39900000, 'below_min_orders' => 0]);
        $this->order(101, 'initial_grid', 'buy', '221620000000', '0.000045', $t0);
        $this->order(102, 'initial_grid', 'buy', '218290000000', '0.000045', $t0);
        $this->order(103, 'initial_grid', 'sell', '228380000000', '0.000044', $t0);
        $this->order(104, 'initial_grid', 'sell', '231800000000', '0.00004504', $t0); // off-step, historic, synced

        $this->fill(101, '2026-10-03 03:10:00');
        $this->exit(109, 101, '2026-10-03 03:10:02', w4LatencyMs: 850);
        $this->fill(109, '2026-10-03 09:30:00');
        $this->book(101, 109);

        $this->fill(103, '2026-10-03 14:00:00');
        $this->exit(110, 103, '2026-10-03 14:00:01');
        $this->fill(110, '2026-10-04 02:00:00');
        $this->book(110, 103);

        $this->fill(102, '2026-10-04 08:00:00');
        $this->exit(111, 102, '2026-10-04 08:00:03');
        $this->fill(111, '2026-10-04 20:00:00');
        $this->book(102, 111);

        // Rebalance: the remaining initial sell is cancelled, a new 2+2 grid is placed.
        DB::table('grid_orders')->where('id', 104)->update(['status' => 'cancelled', 'updated_at' => '2026-10-05 09:59:59']);
        $this->log('2026-10-05 09:59:57', 'INFO', 'REBALANCE_EFFECTIVE_BUDGET', ['bot_id' => 48, 'total_budget' => '50000000', 'active_budget' => '40000000', 'locked_capital' => '0', 'effective_budget' => '40000000']);
        $this->log('2026-10-05 09:59:58', 'INFO', 'GRID_PLAN', ['symbol' => 'BTCIRT', 'mid' => 220000000000, 'levels' => 4, 'budget_irt' => 40000000, 'step_pct' => 1.5, 'estimated_notional' => 40100000, 'below_min_orders' => 0]);
        $this->log('2026-10-05 10:00:30', 'INFO', 'ADJUST_GRID_BOT_COMPLETE', ['bot_id' => 48, 'symbol' => 'BTCIRT', 'rebalance_applied' => true]);
        $reb = '2026-10-05 10:00:00';
        $this->order(105, 'rebalance', 'buy', '216700000000', '0.000046', $reb);
        $this->order(106, 'rebalance', 'buy', '213450000000', '0.000047', $reb);
        $this->order(107, 'rebalance', 'sell', '223300000000', '0.000045', $reb);
        $this->order(108, 'rebalance', 'sell', '226650000000', '0.000044', $reb); // stays open

        $this->fill(105, '2026-10-05 18:00:00');
        $this->exit(112, 105, '2026-10-05 18:00:02', w4LatencyMs: 1200);
        // Duplicate-exit trap: a second live exit for the same fill.
        $this->exit(116, 105, '2026-10-05 18:00:05', duplicateOf: 112);
        $this->fill(112, '2026-10-06 04:00:00');
        $this->book(105, 112);

        // Fee-rate drift: this buy was charged 32 bps (configured 25).
        $this->fill(106, '2026-10-06 12:00:00', feeBps: '32');
        $this->exit(113, 106, '2026-10-06 12:00:04');
        $this->fill(113, '2026-10-07 01:00:00', w4LatencyMs: 400);
        $this->book(106, 113);

        $this->fill(107, '2026-10-07 09:00:00');
        $this->exit(114, 107, '2026-10-07 09:00:02');
        $this->fill(114, '2026-10-08 03:00:00');
        $this->book(114, 107);

        DB::table('bot_configs')->where('id', 48)->update(['base_dust' => $this->dust]);
        $this->bot->refresh();

        $this->log('2026-10-06 00:00:01', 'INFO', 'PRECISION_ROW_SYNCED', ['bot_id' => 48, 'grid_order_id' => 104, 'nobitex_order_id' => '9000104', 'side' => 'sell', 'amount_before' => '0.00004504', 'amount_after' => '0.000045', 'price_before' => '231800000000', 'price_after' => '231800000000']);
    }

    private function order(int $id, string $role, string $side, string $price, string $amount, string $at, ?int $parent = null): void
    {
        (new GridOrder())->forceFill([
            'id' => $id, 'bot_config_id' => 48, 'type' => $side, 'price' => $price, 'amount' => $amount,
            'original_amount' => $amount, 'status' => 'placed', 'role' => $role, 'paired_order_id' => $parent,
            'nobitex_order_id' => (string) (9000000 + $id), 'client_order_id' => "g48-{$id}",
            'created_at' => $at, 'updated_at' => $at,
        ])->save();
        $this->exchange[(string) (9000000 + $id)] = $this->exPayload(GridOrder::findOrFail($id));
    }

    private function fill(int $id, string $at, string $feeBps = '25', ?int $w4LatencyMs = null): void
    {
        $o      = GridOrder::findOrFail($id);
        $amount = Money::trimZeros((string) $o->amount);
        $price  = (string) $o->price;
        $fee    = $o->type === 'buy'
            ? Money::mul($amount, Money::div($feeBps, '10000'))
            : Money::mul(Money::mul($amount, $price), Money::div($feeBps, '10000'));
        $fields = app(FeeModel::class)->fillFields($this->bot, (string) $o->type, 'BTCIRT', $amount, $price, $price, $fee, Money::mul($amount, $price));

        $o->forceFill(['status' => 'filled', 'filled_amount' => $amount, 'remaining_amount' => '0', 'filled_at' => $at, 'last_fill_at' => $at, 'updated_at' => $at] + $fields)->save();

        // A sell-first cycle closes here: book the exit buy's remainder into dust (settleExitBuyFill).
        if ($o->role === 'cycle_exit' && $o->type === 'buy') {
            $parent = GridOrder::findOrFail($o->paired_order_id);
            $delta  = Money::add((string) $o->net_base_delta, (string) $parent->net_base_delta);
            $this->dust = Money::add($this->dust, $delta);
            $o->forceFill(['exit_dust_delta' => $delta])->save();
        }

        if ($w4LatencyMs !== null) {
            $this->log($at, 'INFO', 'WS_EVENT_ACTED', ['grid_order_id' => $id, 'bot_id' => 48, 'event_status' => 'Done', 'api_status_after' => 'FILLED',
                'outcome' => 'processed', 'local_status' => 'filled', 'pair_attempted' => $o->role !== 'cycle_exit', 'recheck' => 0, 'latency_ms' => $w4LatencyMs]);
        }
        $this->exchange[(string) $o->nobitex_order_id] = $this->exPayload($o->fresh());
    }

    private function exit(int $id, int $parentId, string $at, ?int $w4LatencyMs = null, ?int $duplicateOf = null): void
    {
        $p       = GridOrder::findOrFail($parentId);
        $side    = $p->type === 'buy' ? 'sell' : 'buy';
        $spacing = Money::div('1.5', '100');
        $raw     = $side === 'sell' ? Money::mul((string) $p->price, Money::add('1', $spacing)) : Money::mul((string) $p->price, Money::sub('1', $spacing));
        $price   = (string) MarketPrecision::roundPrice($raw, 'BTCIRT', $side);

        $sizing = app(ExitSizer::class)->compute($this->bot, 'BTCIRT', (string) $p->type, Money::trimZeros((string) $p->filled_amount),
            (string) $p->fee_amount, (string) $p->fee_currency, (string) $p->avg_fill_price, $price, $this->dust);
        $this->dust = $sizing['dust_after'];

        $this->order($id, 'cycle_exit', $side, $price, $sizing['amount'], $at, $parentId);
        GridOrder::whereKey($id)->update(['exit_dust_delta' => $side === 'sell' ? $sizing['dust_delta'] : null]);
        if ($duplicateOf === null) {
            DB::table('grid_orders')->where('id', $parentId)->update(['paired_order_id' => $id]);
        }

        $this->log($at, 'INFO', 'EXIT_SIZED', ['bot_id' => 48, 'filled_order_id' => $parentId, 'exit_side' => $side, 'mode' => $sizing['mode'],
            'amount' => $sizing['amount'], 'dust_before' => $sizing['dust_before'], 'dust_after' => $sizing['dust_after'], 'fee' => $sizing['fee'], 'deferred' => false]);
        if ($w4LatencyMs !== null) {
            $this->log($at, 'INFO', 'WS_EVENT_ACTED', ['grid_order_id' => $parentId, 'bot_id' => 48, 'event_status' => 'Done', 'api_status_after' => 'FILLED',
                'outcome' => 'processed', 'local_status' => 'filled', 'pair_attempted' => true, 'recheck' => 0, 'latency_ms' => $w4LatencyMs]);
        }
    }

    private function book(int $buyId, int $sellId): void
    {
        CompletedTrade::createFromOrders(GridOrder::findOrFail($buyId), GridOrder::findOrFail($sellId));
    }

    private function exPayload(GridOrder $o): array
    {
        $filled = $o->filled_amount !== null ? Money::trimZeros((string) $o->filled_amount) : '0';
        return [
            'id' => (int) $o->nobitex_order_id, 'type' => $o->type, 'execution' => 'Limit',
            'status' => match ($o->status) { 'filled' => 'Done', 'cancelled' => 'Canceled', default => 'Active' },
            'price' => (string) $o->price, 'amount' => Money::trimZeros((string) $o->amount), 'matchedAmount' => $filled,
            'fee' => $o->fee_amount !== null ? Money::trimZeros((string) $o->fee_amount) : '0',
            'averagePrice' => $o->avg_fill_price !== null ? Money::trimZeros((string) $o->avg_fill_price) : '0',
        ];
    }

    private function log(string $ts, string $level, string $msg, array $ctx = [], string $env = 'production'): void
    {
        $this->logLines[] = "[{$ts}] {$env}.{$level}: {$msg} " . json_encode($ctx, JSON_UNESCAPED_SLASHES);
    }

    private function writeLogs(): void
    {
        // Infra + noise.
        $this->log('2026-10-04 11:00:00', 'WARNING', '[WS-PRIVATE] Connection dropped; reconnecting', ['error' => 'silence']);
        $this->log('2026-10-04 11:05:00', 'WARNING', '[WS-PRIVATE] Connection dropped; reconnecting', ['error' => 'eof']);
        $this->log('2026-10-04 11:06:00', 'WARNING', 'WS_PRIVATE_FLAPPING', ['reconnects_last_hour' => 11]);
        $this->log('2026-10-06 12:00:01', 'WARNING', 'FEE_RATE_DRIFT', ['bot_id' => 48, 'side' => 'buy', 'configured_bps' => '25', 'effective_bps' => '32']);
        $this->log('2026-10-06 13:00:00', 'ERROR', 'CheckTradesJob: Error handling filled order 999: boom');
        $this->log('2026-10-06 13:00:01', 'ERROR', 'should be ignored', [], 'testing');
        $this->log('2026-10-06 14:00:00', 'INFO', 'EXIT_SIZED', ['bot_id' => 49, 'filled_order_id' => 105, 'amount' => '1', 'dust_before' => '9', 'dust_after' => '9']);
        $this->log('2026-10-01 10:00:00', 'ERROR', 'before the window');

        usort($this->logLines, fn ($a, $b) => strcmp(substr($a, 0, 21), substr($b, 0, 21)));
        $byDay = [];
        foreach ($this->logLines as $l) {
            $byDay[substr($l, 1, 10)][] = $l;
        }
        foreach ($byDay as $day => $lines) {
            $body = implode("\n", $lines) . "\n#0 /var/www/stack/trace continuation line\n";
            if ($day <= '2026-10-04') {
                file_put_contents("{$this->logDir}/trading-{$day}.log.gz", gzencode($body));
            } else {
                file_put_contents("{$this->logDir}/trading-{$day}.log", $body);
            }
        }
    }

    private function fakeExchange(): void
    {
        // Historic diffs the report must show: the synced row 104 (exchange kept 0.000045)
        // and a price diff on the open row 108.
        $this->exchange['9000104']['amount'] = '0.000045';
        $this->exchange['9000104']['status'] = 'Canceled';
        $this->exchange['9000108']['price']  = '226650000010';

        // Wallet = start + Σ fill cash flows (computed here from the stored fee fields).
        $rls = self::START_RLS;
        $btc = self::START_BTC;
        $lockedBtc = '0';
        foreach (GridOrder::where('bot_config_id', 48)->get() as $o) {
            if ($o->status === 'filled') {
                $q = Money::trimZeros((string) $o->filled_amount);
                $n = Money::mul($q, (string) $o->avg_fill_price);
                $f = Money::trimZeros((string) $o->fee_amount);
                if ($o->type === 'buy') {
                    $rls = Money::sub($rls, $n);
                    $btc = Money::add($btc, Money::sub($q, $f));
                } else {
                    $rls = Money::add($rls, Money::sub($n, $f));
                    $btc = Money::sub($btc, $q);
                }
            } elseif ($o->status === 'placed' && $o->type === 'sell') {
                $lockedBtc = Money::add($lockedBtc, Money::trimZeros((string) $o->amount));
            }
        }
        $this->wallet = ['rls' => $rls, 'btc' => $btc, 'locked_btc' => $lockedBtc];

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/market/orders/status')) {
                $id = (string) (json_decode($request->body(), true)['id'] ?? '');
                return isset($this->exchange[$id])
                    ? Http::response(['status' => 'ok', 'order' => $this->exchange[$id]], 200)
                    : Http::response(['status' => 'failed', 'code' => 'NotFound'], 404);
            }
            if (str_contains($url, '/users/wallets/list')) {
                return Http::response(['status' => 'ok', 'wallets' => [
                    ['currency' => 'rls', 'balance' => $this->wallet['rls'], 'blockedBalance' => '0', 'activeBalance' => $this->wallet['rls']],
                    ['currency' => 'btc', 'balance' => $this->wallet['btc'], 'blockedBalance' => $this->wallet['locked_btc'],
                        'activeBalance' => Money::sub($this->wallet['btc'], $this->wallet['locked_btc'])],
                ]], 200);
            }
            if (str_contains($url, '/market/udf/history')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                return Http::response(self::candles((int) $q['from'], (int) $q['to']), 200);
            }
            return Http::response(['status' => 'failed', 'message' => 'unexpected call'], 500);
        });
    }

    /** Hourly TOMAN candles: a 36h triangle wave between 21.55e9 and 22.95e9 toman. */
    private static function candles(int $from, int $to): array
    {
        $out = ['s' => 'ok', 't' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
        $px = function (int $t): int {
            $phase = intdiv($t, 3600) % 36;
            $k = $phase < 18 ? $phase : 36 - $phase;
            return 21550000000 + $k * 77777770;
        };
        for ($t = intdiv($from, 3600) * 3600; $t <= $to; $t += 3600) {
            if ($t < $from) {
                continue;
            }
            $o = $px($t);
            $c = $px($t + 3600);
            $out['t'][] = $t;
            $out['o'][] = (string) $o;
            $out['c'][] = (string) $c;
            $out['h'][] = (string) (max($o, $c) + 20000000);
            $out['l'][] = (string) (min($o, $c) - 20000000);
            $out['v'][] = '1.5';
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function runAudit(array $extra = []): array
    {
        $this->artisan('bot:audit', ['botId' => 48, '--to' => self::TO, '--log-dir' => $this->logDir,
            '--start-rls' => self::START_RLS, '--start-btc' => self::START_BTC] + $extra)
            ->expectsOutputToContain('Report: ')
            ->assertExitCode(0);

        $dirs = File::directories(storage_path('app/audits'));
        $this->assertCount(1, $dirs);
        foreach (['report.md', 'data.json', 'orders.csv', 'cycles.csv', 'events.csv'] as $f) {
            $this->assertFileExists($dirs[0] . '/' . $f);
        }

        return json_decode((string) file_get_contents($dirs[0] . '/data.json'), true);
    }

    private static function codes(array $A, string $severity = null): array
    {
        return array_values(array_unique(array_column(array_filter($A['anomalies'], fn ($a) => $severity === null || $a['severity'] === $severity), 'code')));
    }

    private static function anomaly(array $A, string $code): array
    {
        return array_values(array_filter($A['anomalies'], fn ($a) => $a['code'] === $code));
    }

    private function snapshot(): array
    {
        return [
            GridOrder::orderBy('id')->get()->map(fn ($o) => $o->getAttributes())->all(),
            CompletedTrade::orderBy('id')->get()->map(fn ($o) => $o->getAttributes())->all(),
            DB::table('bot_configs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('bot_activity_logs')->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Tests                                                               */
    /* ------------------------------------------------------------------ */

    public function test_fixture_shape_matches_bot_48(): void
    {
        $fills = GridOrder::where('status', 'filled')->count();
        $this->assertSame(12, $fills);
        $this->assertSame(6, CompletedTrade::count());
        $this->assertSame(2, GridOrder::where('status', 'placed')->count());
    }

    public function test_ledger_counts_paths_and_window(): void
    {
        $A = $this->runAudit();

        $this->assertSame('2026-10-02 22:41:00', $A['meta']['window']['from']);
        $this->assertSame(15, $A['orders']['counts']['total']);
        $this->assertSame(12, $A['orders']['counts']['fills']);
        $this->assertSame(2, $A['orders']['counts']['open']);
        $this->assertSame(3, $A['orders']['counts']['fill_paths']['W4']);
        $this->assertSame(9, $A['orders']['counts']['fill_paths']['poller']);

        $rows = array_column($A['orders']['rows'], null, 'id');
        $this->assertSame(850, $rows[101]['w4_latency_ms']);
        $this->assertSame(2, $rows[109]['exit_delay_s']);
        $this->assertSame('2026-10-03 03:10:02', $rows[109]['exit_sized_at']);
        $this->assertSame(22798, $rows[109]['time_to_fill_s']); // 03:10:02 → 09:30:00

        // Two builds: the initial grid and the rebalance, each with its budget evidence.
        $builds = $A['timeline']['builds'];
        $this->assertCount(2, $builds);
        $this->assertSame(['initial_grid', 'rebalance'], array_column($builds, 'role'));
        $this->assertSame(40000000, $builds[0]['grid_plan']['budget_irt']);
        $this->assertSame('40000000', $builds[1]['effective_budget']['effective_budget']);
        $this->assertSame('40000000', $A['timeline']['active_budget_irt']);
        $this->assertSame(['2026-10-05 10:00:30'], $A['timeline']['rebalances_applied_logged']);
    }

    public function test_every_order_check_fires_correctly(): void
    {
        $A = $this->runAudit();
        $rows = array_column($A['orders']['rows'], null, 'id');

        // (a)(c)(e)(g) clean on every row; recomputed exit prices equal the stored ones.
        foreach ($rows as $id => $r) {
            $this->assertSame('ok', $r['checks']['a_tick'], "row {$id} tick");
            $this->assertContains($r['checks']['g_sell_above_buy'], ['ok', 'n/a'], "row {$id} sell>buy");
            if ($r['role'] === 'cycle_exit') {
                $this->assertSame('ok', $r['checks']['c_exit_price'], "row {$id} exit price");
                $this->assertSame($r['price'], $r['expected_exit_price']);
            }
        }
        $this->assertSame('224944300000', $rows[109]['expected_exit_price']); // 221620000000 × 1.015, up to tick
        $this->assertSame('224954300000', $rows[110]['expected_exit_price']); // 228380000000 × 0.985, down to tick

        // (b) the historic off-step row 104 — info because it is cancelled and synced.
        $this->assertSame('off-step (6 dp)', $rows[104]['checks']['b_step']);
        $offStep = self::anomaly($A, 'AMOUNT_OFF_STEP');
        $this->assertCount(1, $offStep);
        $this->assertSame('info', $offStep[0]['severity']);
        $this->assertTrue($rows[104]['synced_by_sync_precision']);

        // (d) duplicate-exit trap.
        $dup = self::anomaly($A, 'DUPLICATE_EXIT');
        $this->assertCount(1, $dup);
        $this->assertSame('critical', $dup[0]['severity']);
        $this->assertSame([105, 112, 116], $dup[0]['rows']);
        $this->assertStringStartsWith('DUPLICATE', $rows[105]['checks']['d_one_exit']);
        $this->assertSame('ok (exit 109)', $rows[101]['checks']['d_one_exit']);

        // (e) fee currency by side.
        $this->assertSame('ok', $rows[101]['checks']['e_fee_currency']);
        $this->assertSame('ok', $rows[109]['checks']['e_fee_currency']);
        $this->assertSame('base', $rows[101]['fee_currency']);
        $this->assertSame('quote', $rows[109]['fee_currency']);

        // (f) fee-rate drift on 106 only (32 vs 25 bps).
        $drift = self::anomaly($A, 'FEE_RATE_DRIFT');
        $this->assertCount(1, $drift);
        $this->assertSame([106], $drift[0]['rows']);
        $this->assertSame('32', $rows[106]['effective_fee_bps']);
        $this->assertSame('25', $rows[101]['effective_fee_bps']);

        foreach (['SELL_NOT_ABOVE_BUY', 'EXIT_PRICE_MISMATCH', 'FEE_CURRENCY', 'FILL_WITHOUT_EXIT', 'PRICE_OFF_TICK', 'EXIT_SAME_SIDE'] as $code) {
            $this->assertSame([], self::anomaly($A, $code), $code);
        }
    }

    public function test_cycles_are_recomputed_independently_and_match(): void
    {
        $A = $this->runAudit();
        $c = $A['cycles'];

        $this->assertSame(6, $c['totals']['count']);
        $this->assertSame([], self::anomaly($A, 'CYCLE_RECOMPUTE_DIFF'));
        $this->assertSame([], self::anomaly($A, 'CYCLE_NOT_BOOKED'));
        $this->assertSame([], self::anomaly($A, 'CYCLE_LOSS'));

        $storedNet = '0';
        foreach (CompletedTrade::all() as $t) {
            $storedNet = Money::add($storedNet, Money::trimZeros((string) $t->net_profit));
            $row = collect($c['rows'])->firstWhere('id', $t->id);
            $this->assertLessThanOrEqual(0, Money::compare(Money::abs(Money::sub($row['net'], (string) $t->net_profit)), '1'));
            $this->assertSame(0, Money::compare($row['base_residual'], Money::trimZeros((string) $t->base_residual)));
        }
        $this->assertLessThanOrEqual(0, Money::compare(Money::abs(Money::sub($c['totals']['net'], $storedNet)), '1'));

        // First cycle by hand: buy 0.000045 @ 221620000000 (fee 0.0000001125 BTC), sell 0.000044 @ 224944300000.
        $first = collect($c['rows'])->firstWhere('buy_id', 101);
        $this->assertSame('-75351', FeeModel::roundHalfUp($first['gross_cash'], 0)); // 9897549.2 − 9972900
        $this->assertSame('0.0000008875', $first['base_residual']);
        $this->assertSame(22800, $first['duration_s']); // buy fill 03:10 → sell fill 09:30
        $this->assertSame(['buy' => 4, 'sell' => 2], $c['totals']['by_first_leg']);
        $this->assertSame(6, $c['totals']['duration_s']['n']);

        // Sanity: ~100k rial net per cycle on a ~10M rial level at 1.5% spacing.
        $this->assertSame(1, Money::compare($c['totals']['net'], '400000'));
        $this->assertSame(-1, Money::compare($c['totals']['net'], '900000'));
    }

    public function test_a_tampered_booking_is_flagged(): void
    {
        $t = CompletedTrade::orderBy('id')->first();
        DB::table('completed_trades')->where('id', $t->id)->update(['net_profit' => Money::add((string) $t->net_profit, '50')]);

        $A = $this->runAudit(['--no-exchange' => true]);

        $diff = self::anomaly($A, 'CYCLE_RECOMPUTE_DIFF');
        $this->assertCount(1, $diff);
        $this->assertStringContainsString('net Δ -50', $diff[0]['message']);
    }

    public function test_a_dust_ledger_drift_is_flagged(): void
    {
        DB::table('bot_configs')->where('id', 48)->update(['base_dust' => Money::add($this->dust, '0.000001')]);
        DB::table('grid_orders')->where('id', 113)->update(['exit_dust_delta' => '0']);

        $A = $this->runAudit(['--no-exchange' => true]);

        $this->assertCount(1, self::anomaly($A, 'DUST_LEDGER_MISMATCH'));
        $this->assertSame('-0.000001', $A['dust']['diff']);
        $mm = self::anomaly($A, 'DUST_DELTA_MISMATCH');
        $this->assertCount(1, $mm);
        $this->assertSame([113], $mm[0]['rows']);
    }

    public function test_dust_replay_matches_the_bot_ledger(): void
    {
        $A = $this->runAudit();
        $d = $A['dust'];

        $this->assertSame(0, Money::compare($d['replayed'], $this->dust));
        $this->assertSame(0, Money::compare($d['bot_config_base_dust'], $this->dust));
        $this->assertSame('0', $d['diff']);
        $this->assertSame([], self::anomaly($A, 'DUST_LEDGER_MISMATCH'));
        $this->assertSame([], self::anomaly($A, 'DUST_DELTA_MISMATCH'));
        // What the bot logged (EXIT_SIZED dust_before/after) equals the replay at every sized exit,
        // including the duplicate 116 (matched to its own log line by time).
        $this->assertSame([], $d['log_divergences']);
        $s116 = collect($d['steps'])->firstWhere('row', 116);
        $this->assertSame($s116['dust_before'], $s116['log_dust_before']);
        // 5 exit sells sized (incl. the duplicate) + 2 exit buys settled.
        $kinds = array_count_values(array_column($d['steps'], 'kind'));
        $this->assertSame(['exit_sell_sized' => 5, 'exit_buy_settled' => 2], $kinds);
        $this->assertSame('EXIT_SIZED log', $d['steps'][0]['exit_amount_source']);
    }

    public function test_exchange_reconciliation_and_balances(): void
    {
        $A = $this->runAudit();
        $x = $A['exchange'];

        $this->assertFalse($x['skipped']);
        $this->assertSame(15, $x['checked']);
        $mm = collect($x['mismatches'])->map(fn ($m) => "{$m['id']}:{$m['field']}:{$m['severity']}")->all();
        $this->assertEqualsCanonicalizing(['104:amount:info', '108:price:warning'], $mm);

        // 15 order-status reads + 1 wallet + candle chunks, each pause >= 300 ms.
        $this->assertGreaterThanOrEqual(17, $A['meta']['http_calls']);
        Sleep::assertSleptTimes($A['meta']['http_calls'] - 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/market/orders/add') || str_contains($r->url(), 'cancel') || str_contains($r->url(), 'update-status'));

        $b = $A['balance'];
        $this->assertSame(12, $b['fills_counted']);
        $this->assertSame('0', $b['residual']['rls']);
        $this->assertSame('0', $b['residual']['btc']);
        $this->assertSame('0', $b['locked_vs_open_orders']['btc']);
        $this->assertStringContainsString('TOTAL', $b['start_nature']['verdict']);
        $this->assertSame([], self::anomaly($A, 'BALANCE_RESIDUAL'));
    }

    public function test_position_market_and_infra(): void
    {
        $A = $this->runAudit();

        // One open long cycle (the duplicate exit 116) and grid-sell inventory 108.
        $p = $A['position'];
        $this->assertCount(1, $p['open_long_cycles']);
        $this->assertSame(116, $p['open_long_cycles'][0]['exit_id']);
        $this->assertSame([108], $p['grid_sell_inventory_on_book']['rows']);
        $this->assertNotNull($p['unrealized']);
        $this->assertNotNull($p['benchmark']['bot_minus_hold_ledger']);
        $this->assertStringContainsString('last candle close', $p['price_now_source']);

        $m = $A['market'];
        $this->assertFalse($m['skipped']);
        $this->assertGreaterThan(100, $m['stats']['candles']);
        $this->assertSame('ESTIMATE', $m['label']);
        $sim = $m['simulation'];
        $this->assertSame(6, $sim['actual_cycles']);
        $this->assertGreaterThan(0, $sim['bot_rule']['cycles']);
        $this->assertGreaterThanOrEqual($sim['bot_rule']['cycles'], $sim['classic_rearming']['cycles']);

        $i = $A['infra'];
        $this->assertSame(2, $i['totals']['WS_PRIVATE_RECONNECT']);
        $this->assertSame(1, $i['totals']['WS_PRIVATE_FLAPPING']);
        $this->assertSame(1, $i['totals']['ERROR']);            // testing.* and pre-window lines ignored
        $this->assertSame(1, $i['totals']['FEE_RATE_DRIFT']);
        $this->assertSame(3, $i['w4_latency_ms']['n']);
        $this->assertSame('850', $i['w4_latency_ms']['median']);
        $this->assertSame('400', $i['w4_latency_ms']['min']);
        $this->assertGreaterThanOrEqual(1, $A['meta']['log_lines_testing_skipped']);
        $this->assertArrayHasKey('trading-2026-10-03.log.gz', $A['meta']['log_files']);
        $this->assertArrayHasKey('trading-2026-10-05.log', $A['meta']['log_files']);
        $this->assertSame([104], $A['meta']['synced_rows']);

        // The other bot's EXIT_SIZED line for "105" must not leak into bot 48's dust chain.
        foreach ($A['dust']['steps'] as $s) {
            $this->assertNotSame('9', $s['log_dust_before'] ?? null);
        }

        // Report shape.
        $md = (string) file_get_contents(File::directories(storage_path('app/audits'))[0] . '/report.md');
        foreach (['## 1. Bot & timeline', '## 2. Order ledger', '## 3. Exchange reconciliation', '## 4. Cycles', '## 5. Base-dust ledger',
            '## 6. Balance reconciliation', '## 7. Open position', '## 8. Infrastructure health', '## 9. Market context', '## 10. Anomalies', '## 11. Opinion',
            'ESTIMATE', 'DUPLICATE_EXIT'] as $needle) {
            $this->assertStringContainsString($needle, $md);
        }
        $this->assertCount(15, $A['summary']);
    }

    public function test_no_exchange_makes_zero_http_calls_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        $A = $this->runAudit(['--no-exchange' => true]);

        Http::assertNothingSent();
        Sleep::assertNeverSlept();
        $this->assertSame(0, $A['meta']['http_calls']);
        $this->assertTrue($A['exchange']['skipped']);
        $this->assertTrue($A['market']['skipped']);
        $this->assertNull($A['balance']['residual']);
        $this->assertSame($before, $this->snapshot());

        // The DB-only sections still run in full.
        $this->assertSame(6, $A['cycles']['totals']['count']);
        $this->assertCount(1, self::anomaly($A, 'DUPLICATE_EXIT'));
        $this->assertStringContainsString('last fill price', $A['position']['price_now_source']);
    }

    public function test_with_exchange_the_database_is_unchanged(): void
    {
        $before = $this->snapshot();
        $this->runAudit();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_input_validation(): void
    {
        $this->artisan('bot:audit', ['botId' => 999, '--no-exchange' => true, '--log-dir' => $this->logDir])->assertExitCode(1);
        $this->artisan('bot:audit', ['botId' => 48, '--start-rls' => '1e9', '--start-btc' => '0'])->assertExitCode(1);
        $this->artisan('bot:audit', ['botId' => 48, '--start-rls' => '100'])->assertExitCode(1);
        Http::assertNothingSent();
    }
}
