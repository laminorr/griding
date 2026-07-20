<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\SubmissionReconciler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 12 Step 7 — behaviour tests for SubmissionReconciler.
 *
 * The reconciler runs against the REAL NobitexService with Http::fake(), so
 * these tests pin the wire contract (POST /market/orders/status with a
 * clientOrderId body; GET /market/orders/list?status=open) as well as the
 * resolution rules:
 *
 *   found by clientOrderId            → 'placed' + real nobitex_order_id
 *   NotFound twice + nothing open     → 'cancelled' (+ parent fill unlinked)
 *   probe error / ambiguous evidence  → unchanged, attempt recorded, logged
 *   row younger than the age guard    → untouched, no HTTP
 *   simulation bot                    → untouched, no HTTP
 *   attempts past max_attempts        → escalated to the bot health surface
 *
 * Every test asserts the SAFETY INVARIANT: zero write calls to the exchange
 * (no orders/add, no orders/update-status, nothing wallet/withdraw-shaped).
 */
final class SubmissionReconcilerTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL = 'BTCIRT';
    private const PRICE  = 101_000_000;
    private const AMOUNT = '0.00123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();

        config([
            'trading.nobitex.base_url'                  => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'                   => '',
            'trading.nobitex.retry.times'               => 1,
            'trading.nobitex.retry.sleep'               => 0,
            'trading.nobitex.rate_limit.rpm'            => 1000,
            'trading.reconcile.enabled'                 => true,
            'trading.reconcile.min_age_seconds'         => 60,
            'trading.reconcile.pending_min_age_seconds' => 60,
            'trading.reconcile.not_found_confirmations' => 2,
            'trading.reconcile.cancel_on_not_found'     => true,
            'trading.reconcile.max_attempts'            => 5,
            'trading.reconcile.max_age_hours'           => 6,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     | Fixtures
     |------------------------------------------------------------------*/

    private function makeBot(bool $simulation = false): BotConfig
    {
        return BotConfig::create([
            'name'         => 'reconcile-test',
            'symbol'       => self::SYMBOL,
            'simulation'   => $simulation,
            'is_active'    => true,
            'grid_spacing' => 1.00,
        ]);
    }

    /**
     * A parked row, aged past the reconcile age guard unless $ageSeconds says
     * otherwise. created_at is what the age guard reads.
     */
    private function makeParkedRow(
        BotConfig $bot,
        string $status = 'submission_unknown',
        int $ageSeconds = 3600,
        array $overrides = []
    ): GridOrder {
        $row = GridOrder::create(array_merge([
            'bot_config_id'   => $bot->id,
            'price'           => self::PRICE,
            'amount'          => self::AMOUNT,
            'type'            => 'sell',
            'status'          => $status,
            'client_order_id' => GridOrder::buildClientOrderId($bot->id, self::SYMBOL, 'sell', self::PRICE),
        ], $overrides));

        DB::table('grid_orders')->where('id', $row->id)->update([
            'created_at' => now()->subSeconds($ageSeconds),
        ]);

        return $row->fresh();
    }

    private function runReconciler(?int $botId = null): array
    {
        return app(SubmissionReconciler::class)->run($botId);
    }

    /** SAFETY INVARIANT: the reconciler never mutates anything on Nobitex. */
    private function assertNoExchangeWrites(): void
    {
        Http::assertNotSent(function (Request $request) {
            $url = $request->url();

            return str_contains($url, '/market/orders/add')
                || str_contains($url, '/market/orders/update-status')
                || str_contains($url, '/positions/')
                || str_contains($url, 'withdraw')
                || str_contains($url, 'address_book');
        });
    }

    private function fakeStatusNotFound(): array
    {
        return ['status' => 'failed', 'code' => 'NotFound', 'message' => 'Order not found'];
    }

    /* -----------------------------------------------------------------
     | Tests
     |------------------------------------------------------------------*/

    /** 1. Confirmed present via clientOrderId lookup → 'placed' + real id. */
    public function test_row_found_by_client_order_id_is_resolved_to_placed(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response([
                'status' => 'ok',
                'order'  => [
                    'id'     => 999888,
                    'type'   => 'sell',
                    'price'  => (string) self::PRICE,
                    'amount' => self::AMOUNT,
                    'status' => 'Active',
                ],
            ]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot);

        $summary = $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('placed', $fresh->status);
        $this->assertSame('999888', $fresh->nobitex_order_id);
        $this->assertSame(1, (int) $fresh->reconcile_attempts);
        $this->assertSame(1, $summary['placed']);

        // The probe must be keyed by the row's deterministic client id.
        Http::assertSent(function (Request $request) use ($row) {
            return str_contains($request->url(), '/market/orders/status')
                && ($request->data()['clientOrderId'] ?? null) === $row->client_order_id;
        });

        $this->assertNoExchangeWrites();
    }

    /**
     * 2. Confirmed absent: NotFound by clientOrderId on two consecutive runs
     * with no matching open order → 'cancelled', and the parent fill's
     * paired_order_id back-link is cleared so the level can be re-paired.
     */
    public function test_not_found_twice_resolves_to_cancelled_and_unlinks_fill(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response($this->fakeStatusNotFound()),
            'apiv2.nobitex.ir/market/orders/list*'   => Http::response(['status' => 'ok', 'orders' => []]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();

        $fill = GridOrder::create([
            'bot_config_id'   => $bot->id,
            'price'           => 100_000_000,
            'amount'          => self::AMOUNT,
            'type'            => 'buy',
            'status'          => 'filled',
            'client_order_id' => 'seed-fill',
        ]);
        $row = $this->makeParkedRow($bot, overrides: ['paired_order_id' => $fill->id]);
        $fill->update(['paired_order_id' => $row->id]);

        // Run 1: absence noted but NOT yet acted on (needs 2 confirmations).
        $this->runReconciler();
        $afterFirst = $row->fresh();
        $this->assertSame('submission_unknown', $afterFirst->status, 'One NotFound answer must not cancel yet.');
        $this->assertSame(1, (int) $afterFirst->reconcile_not_found_count);

        // Run 2: absence confirmed → cancelled + fill unlinked.
        $summary = $this->runReconciler();
        $fresh = $row->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->nobitex_order_id);
        $this->assertNull(
            $fill->fresh()->paired_order_id,
            'Cancelling the pair intent must free the fill for re-pairing.'
        );
        $this->assertSame(1, $summary['cancelled']);

        $this->assertNoExchangeWrites();
    }

    /** 3. Probe failure (5xx) → unchanged, attempt recorded, warning logged. */
    public function test_probe_failure_leaves_row_unchanged_and_logs(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        Log::shouldReceive('channel')->andReturnSelf()->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'RECONCILE_PROBE_FAILED'))
            ->atLeast()->once();
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $level) {
            Log::shouldReceive($level)->zeroOrMoreTimes();
        }

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot);

        $summary = $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('submission_unknown', $fresh->status);
        $this->assertNull($fresh->nobitex_order_id);
        $this->assertSame(1, (int) $fresh->reconcile_attempts);
        $this->assertSame(0, (int) $fresh->reconcile_not_found_count);
        $this->assertNotNull($fresh->reconcile_last_attempt_at);
        $this->assertSame(1, $summary['unresolved']);

        $this->assertNoExchangeWrites();
    }

    /** 4. A row younger than the age guard is not touched — and no HTTP happens. */
    public function test_too_young_row_is_skipped_without_http(): void
    {
        Http::fake();

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot, ageSeconds: 5);

        $summary = $this->runReconciler();

        Http::assertNothingSent();
        $fresh = $row->fresh();
        $this->assertSame('submission_unknown', $fresh->status);
        $this->assertSame(0, (int) $fresh->reconcile_attempts);
        $this->assertSame(1, $summary['skipped_young']);
    }

    /** 5. Simulation bots are skipped entirely — no probes, no row changes. */
    public function test_simulation_bot_is_skipped_without_http(): void
    {
        Http::fake();

        $bot = $this->makeBot(simulation: true);
        $row = $this->makeParkedRow($bot);

        $summary = $this->runReconciler();

        Http::assertNothingSent();
        $this->assertSame('submission_unknown', $row->fresh()->status);
        $this->assertSame(0, (int) $row->fresh()->reconcile_attempts);
        $this->assertSame(1, $summary['skipped_sim']);
    }

    /**
     * 6. Escalation: a row already at max_attempts that stays unresolved is
     * logged at error level and surfaced on the bot's Phase 11 health columns.
     */
    public function test_unresolved_row_past_max_attempts_escalates_to_bot_health(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        Log::shouldReceive('channel')->andReturnSelf()->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'RECONCILE_STUCK'))
            ->atLeast()->once();
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $level) {
            Log::shouldReceive($level)->zeroOrMoreTimes();
        }

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot);
        // Already at the limit; this run's attempt pushes it past it.
        $row->forceFill(['reconcile_attempts' => 5])->save();

        $summary = $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('submission_unknown', $fresh->status, 'Escalation must not change the row status.');
        $this->assertSame(6, (int) $fresh->reconcile_attempts);
        $this->assertSame(1, $summary['unresolved']);

        $freshBot = $bot->fresh();
        $this->assertSame('RECONCILE_STUCK', $freshBot->last_error_code);
        $this->assertStringContainsString((string) $row->id, (string) $freshBot->last_error_message);

        $this->assertNoExchangeWrites();
    }

    /**
     * 7. clientOrderId lookup NotFound, but ONE unclaimed open order carries
     * the exact price+side+amount fingerprint → adopted as 'placed'. Covers
     * the case where the exchange never indexed our client_ref tag.
     */
    public function test_unique_open_order_fingerprint_is_adopted_as_placed(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response($this->fakeStatusNotFound()),
            'apiv2.nobitex.ir/market/orders/list*'   => Http::response([
                'status' => 'ok',
                'orders' => [
                    ['id' => 777001, 'type' => 'sell', 'price' => (string) self::PRICE, 'amount' => self::AMOUNT, 'status' => 'Active'],
                    // Different price — must not be considered.
                    ['id' => 777002, 'type' => 'sell', 'price' => '102000000', 'amount' => self::AMOUNT, 'status' => 'Active'],
                ],
            ]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot);

        $summary = $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('placed', $fresh->status);
        $this->assertSame('777001', $fresh->nobitex_order_id);
        $this->assertSame(1, $summary['placed']);

        $this->assertNoExchangeWrites();
    }

    /**
     * 8. Ambiguous evidence — several indistinguishable unclaimed open orders
     * at the row's price/side — is never guessed at: unchanged, and the
     * NotFound streak resets (a lookalike is live, absence is not proven).
     */
    public function test_ambiguous_open_orders_are_never_guessed(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response($this->fakeStatusNotFound()),
            'apiv2.nobitex.ir/market/orders/list*'   => Http::response([
                'status' => 'ok',
                'orders' => [
                    ['id' => 777001, 'type' => 'sell', 'price' => (string) self::PRICE, 'amount' => self::AMOUNT, 'status' => 'Active'],
                    ['id' => 777002, 'type' => 'sell', 'price' => (string) self::PRICE, 'amount' => self::AMOUNT, 'status' => 'Active'],
                ],
            ]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot);
        $row->forceFill(['reconcile_not_found_count' => 1])->save();

        $summary = $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('submission_unknown', $fresh->status);
        $this->assertNull($fresh->nobitex_order_id);
        $this->assertSame(
            0,
            (int) $fresh->reconcile_not_found_count,
            'A live lookalike must reset the absence streak — cancellation needs uncontradicted NotFound answers.'
        );
        $this->assertSame(1, $summary['unresolved']);

        $this->assertNoExchangeWrites();
    }

    /**
     * 9. Stale 'pending' rows (process died mid-placement) ride the same
     * ladder: here the clientOrderId lookup finds the order → 'placed'.
     */
    public function test_stale_pending_row_is_swept_and_resolved(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response([
                'status' => 'ok',
                'order'  => [
                    'id'     => 555444,
                    'type'   => 'sell',
                    'price'  => (string) self::PRICE,
                    'amount' => self::AMOUNT,
                    'status' => 'Active',
                ],
            ]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot, status: 'pending');

        $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('placed', $fresh->status);
        $this->assertSame('555444', $fresh->nobitex_order_id);

        $this->assertNoExchangeWrites();
    }

    /**
     * 10. An exchange order found under our clientOrderId whose price/side
     * CONTRADICT the local row (id collision / corruption) is never adopted
     * and never cancelled — parked for humans.
     */
    public function test_identity_mismatch_is_left_unresolved(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response([
                'status' => 'ok',
                'order'  => [
                    'id'     => 313131,
                    'type'   => 'buy', // local row is a sell
                    'price'  => '90000000',
                    'amount' => self::AMOUNT,
                    'status' => 'Active',
                ],
            ]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();
        $row = $this->makeParkedRow($bot);

        $summary = $this->runReconciler();

        $fresh = $row->fresh();
        $this->assertSame('submission_unknown', $fresh->status);
        $this->assertNull($fresh->nobitex_order_id);
        $this->assertSame(1, $summary['unresolved']);

        $this->assertNoExchangeWrites();
    }

    /**
     * 11. Open orders already claimed by another local row can never satisfy
     * a parked row's fingerprint — with nothing else open, the second
     * NotFound confirmation resolves the row to 'cancelled'.
     */
    public function test_claimed_open_orders_are_not_adopted(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/market/orders/status*' => Http::response($this->fakeStatusNotFound()),
            'apiv2.nobitex.ir/market/orders/list*'   => Http::response([
                'status' => 'ok',
                'orders' => [
                    ['id' => 424242, 'type' => 'sell', 'price' => (string) self::PRICE, 'amount' => self::AMOUNT, 'status' => 'Active'],
                ],
            ]),
            '*' => Http::response(['status' => 'failed'], 500),
        ]);

        $bot = $this->makeBot();

        // Another local row already owns exchange order 424242.
        GridOrder::create([
            'bot_config_id'    => $bot->id,
            'price'            => self::PRICE,
            'amount'           => self::AMOUNT,
            'type'             => 'sell',
            'status'           => 'placed',
            'client_order_id'  => 'other-row',
            'nobitex_order_id' => '424242',
        ]);

        $row = $this->makeParkedRow($bot);
        $row->forceFill(['reconcile_not_found_count' => 1])->save();

        $summary = $this->runReconciler();

        $this->assertSame(
            'cancelled',
            $row->fresh()->status,
            'A claimed open order is no evidence of presence; confirmed NotFound must cancel.'
        );
        $this->assertSame(1, $summary['cancelled']);

        $this->assertNoExchangeWrites();
    }
}
