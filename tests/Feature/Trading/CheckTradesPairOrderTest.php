<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\NobitexService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 12 — behaviour tests for CheckTradesJob::createPairOrderLocked().
 *
 * Step 1 pinned the ORIGINAL asymmetry between the two order-placement paths:
 * the executor wrote its intent row outside any transaction and downgraded it
 * to 'submission_unknown' on a post-call throw, whereas the pair-order path
 * wrapped its intent row in a DB transaction that ROLLED BACK on error — so
 * the row vanished with no reconciliation trace.
 *
 * Step 6 removed that asymmetry. The contract under test now:
 *   - the pairing linkage (lockForUpdate re-read + 'pending' intent row +
 *     the fill's paired_order_id back-link) commits BEFORE the exchange call;
 *   - a throw AFTER the API call was attempted parks the committed row in
 *     'submission_unknown' and keeps the link (reconciliation is Step 7);
 *   - a throw BEFORE the API call cancels the row and unlinks the fill so a
 *     later run may pair again;
 *   - the client_order_id dedup guard also matches 'submission_unknown', so a
 *     re-entry (queue retry with $tries = 3, next scheduler tick) can never
 *     re-send an order whose first submission is unresolved.
 *
 * createPairOrderLocked() is private and needs no CheckTradesJob constructor
 * args, so it is invoked directly via reflection with a hand-built filled order
 * and bot. Schema is the sqlite-friendly one from BuildsGridSchema (the real
 * MySQL-only migrations cannot run on :memory:).
 */
final class CheckTradesPairOrderTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL     = 'BTCIRT';
    private const BUY_PRICE  = 100_000_000;
    // sell continuation = buy_price * (1 + grid_spacing/100), grid_spacing = 1.00
    private const SELL_PRICE = 101_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(bool $simulation): BotConfig
    {
        return BotConfig::create([
            'name'         => 'pair-test',
            'symbol'       => self::SYMBOL,
            'simulation'   => $simulation,
            'is_active'    => true,
            'grid_spacing' => 1.00,
        ]);
    }

    private function makeFilledBuy(BotConfig $bot): GridOrder
    {
        return GridOrder::create([
            'bot_config_id'   => $bot->id,
            'price'           => self::BUY_PRICE,
            'amount'          => '0.001',
            'filled_amount'   => '0.001',
            'type'            => 'buy',
            'status'          => 'filled',
            'client_order_id' => 'seed-buy-' . self::BUY_PRICE,
            'paired_order_id' => null,
        ]);
    }

    private function invokePairOrder(GridOrder $filled, BotConfig $bot): void
    {
        $job = new CheckTradesJob();
        $ref = new ReflectionMethod($job, 'createPairOrderLocked');
        $ref->setAccessible(true);
        $ref->invoke($job, $filled, $bot);
    }

    private function expectedPairClientOrderId(BotConfig $bot): string
    {
        return GridOrder::buildClientOrderId($bot->id, self::SYMBOL, 'sell', self::SELL_PRICE);
    }

    /**
     * Pin the HTTP-layer config exactly as NobitexRequestRetryTest does and
     * route every request on the Nobitex host through a counting fake that
     * always dies with a transport timeout — the ambiguous case: the order
     * may or may not sit on the exchange's book. The REAL NobitexService then
     * runs its Step 5 non-idempotent policy, so the attempt count proves no
     * transport-level retry happens on the pair-order path either.
     *
     * @return callable():int returns the number of HTTP attempts made so far
     */
    private function fakeAmbiguousTransportFailure(): callable
    {
        config([
            'trading.nobitex.base_url'        => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'         => '',
            'trading.nobitex.retry.times'     => 3,
            'trading.nobitex.retry.sleep'     => 0,
            'trading.nobitex.rate_limit.rpm'  => 1000,
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        return static function () use (&$calls): int {
            return $calls;
        };
    }

    /**
     * 1. FLIPPED in Phase 12 Step 6 (was test_known_bug_pair_intent_row_lost_on_rollback).
     *
     * The 'pending' intent row and the fill's paired_order_id back-link now
     * commit BEFORE placeOrder() runs. When the exchange call throws, the
     * catch downgrades the committed row to 'submission_unknown' instead of
     * rolling it back — the reconciliation breadcrumb the executor path has
     * kept since Step 3, and the record that lets the dedup guard block a
     * duplicate send on any later attempt.
     */
    public function test_pair_intent_row_survives_ambiguous_failure_as_submission_unknown(): void
    {
        $bot    = $this->makeBot(simulation: false);
        $filled = $this->makeFilledBuy($bot);

        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('placeOrder')
            ->once()
            ->andThrow(new RuntimeException('cURL error 28: Operation timed out'));
        $this->app->instance(NobitexService::class, $svc);

        $this->invokePairOrder($filled, $bot);

        $pair = GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->first();
        $this->assertNotNull($pair, 'The pair intent row must survive an ambiguous placement failure.');
        $this->assertSame('submission_unknown', $pair->status);
        $this->assertNull($pair->nobitex_order_id);
        $this->assertSame($filled->id, $pair->paired_order_id);
        // The back-link stays: paired_order_id records "a continuation intent
        // exists", which keeps processBot()'s whereNull(paired_order_id) query
        // from re-selecting this fill on a queue retry. CompletedTrade booking
        // is unaffected — it requires the partner to reach status 'filled'.
        $this->assertSame($pair->id, $filled->fresh()->paired_order_id);
    }

    /**
     * 2. End-to-end through the REAL NobitexService: a transport timeout on
     * the order-creating POST makes EXACTLY ONE HTTP attempt (Step 5's
     * non-idempotent policy — no blind resend), surfaces as
     * AmbiguousOrderSubmissionException, and the committed intent row is
     * parked as 'submission_unknown' with the fill linked to it.
     */
    public function test_live_ambiguous_transport_failure_makes_one_attempt_and_parks_row(): void
    {
        $attempts = $this->fakeAmbiguousTransportFailure();

        $bot    = $this->makeBot(simulation: false);
        $filled = $this->makeFilledBuy($bot);

        $this->invokePairOrder($filled, $bot);

        $this->assertSame(1, $attempts(), 'An ambiguous order POST must never be retried at the transport layer.');

        $pair = GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->first();
        $this->assertNotNull($pair, 'The intent row must survive the ambiguous transport failure.');
        $this->assertSame('submission_unknown', $pair->status);
        $this->assertSame($pair->id, $filled->fresh()->paired_order_id);
    }

    /**
     * 3. Re-entry guard — the $tries = 3 safety proof. After an ambiguous
     * failure, a queue retry (or the next scheduler tick) re-entering
     * createPairOrderLocked() for the SAME fill must NOT send a second order:
     * the client_order_id dedup guard now matches the surviving
     * 'submission_unknown' row (and the paired_order_id short-circuit backs
     * it up), so the HTTP attempt count stays at 1 and no second row appears.
     */
    public function test_reentry_after_ambiguous_failure_does_not_place_a_second_order(): void
    {
        $attempts = $this->fakeAmbiguousTransportFailure();

        $bot    = $this->makeBot(simulation: false);
        $filled = $this->makeFilledBuy($bot);

        $this->invokePairOrder($filled, $bot);
        $this->assertSame(1, $attempts());

        // Second entry with a fresh model instance, as a re-run job would load it.
        $this->invokePairOrder($filled->fresh(), $bot);

        $this->assertSame(1, $attempts(), 'Re-entry must not re-send an order whose first submission is unresolved.');
        $this->assertSame(
            1,
            GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->count(),
            'Exactly one intent row must exist for the pair after re-entry.'
        );
        $this->assertSame('submission_unknown', GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->value('status'));
    }

    /**
     * 4. Failure BEFORE the API call was attempted (here: NobitexService
     * cannot even be resolved) → nothing can exist on the exchange, so the
     * committed intent row is downgraded to 'cancelled' and the fill is
     * UNLINKED, leaving it eligible for pairing on the next run. Mirrors the
     * executor's $apiCallAttempted distinction.
     */
    public function test_failure_before_api_call_cancels_row_and_unlinks_fill(): void
    {
        Http::fake();

        $this->app->bind(NobitexService::class, function (): NobitexService {
            throw new RuntimeException('service resolution failed before any API call');
        });

        $bot    = $this->makeBot(simulation: false);
        $filled = $this->makeFilledBuy($bot);

        $this->invokePairOrder($filled, $bot);

        Http::assertNothingSent();

        $pair = GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->first();
        $this->assertNotNull($pair, 'The intent row is kept (as an audit trail) even for a pre-API failure.');
        $this->assertSame('cancelled', $pair->status);
        $this->assertNull(
            $filled->fresh()->paired_order_id,
            'A pre-API failure must unlink the fill so pairing can be retried.'
        );
    }

    /** 5. Simulation assigns a SIM-* id, commits the row, and sends nothing. */
    public function test_simulation_pair_order_assigns_sim_id_without_http(): void
    {
        Http::fake();

        $bot    = $this->makeBot(simulation: true);
        $filled = $this->makeFilledBuy($bot);

        $this->invokePairOrder($filled, $bot);

        Http::assertNothingSent();

        $pair = GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->first();
        $this->assertNotNull($pair, 'Simulation must persist the pair order row.');
        $this->assertSame('placed', $pair->status);
        $this->assertSame('sell', $pair->type);
        $this->assertStringStartsWith('SIM-', (string) $pair->nobitex_order_id);
        // Simulation completes the pairing back-reference on the filled order.
        $this->assertSame($pair->id, $filled->fresh()->paired_order_id);
    }

    /**
     * 6. Concurrency guard intact: a fill that is ALREADY paired (another
     * process won the race after this one loaded it) short-circuits inside
     * the locked re-read — no new row is created and nothing is sent.
     */
    public function test_already_paired_filled_order_short_circuits_without_placing(): void
    {
        Http::fake();

        $bot    = $this->makeBot(simulation: false);
        $filled = $this->makeFilledBuy($bot);

        // Another process already created a continuation and linked the fill.
        // Its client_order_id deliberately differs from the deterministic one
        // so the SECOND guard (paired_order_id re-read) is what must trip.
        $existingPair = GridOrder::create([
            'bot_config_id'   => $bot->id,
            'price'           => self::SELL_PRICE,
            'amount'          => '0.001',
            'type'            => 'sell',
            'status'          => 'placed',
            'client_order_id' => 'other-process-pair',
            'nobitex_order_id'=> '424242',
            'paired_order_id' => $filled->id,
        ]);
        $filled->update(['paired_order_id' => $existingPair->id]);

        $this->invokePairOrder($filled->fresh(), $bot);

        Http::assertNothingSent();
        $this->assertFalse(
            GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->exists(),
            'An already-paired fill must not spawn another intent row.'
        );
        $this->assertSame($existingPair->id, $filled->fresh()->paired_order_id);
    }
}
