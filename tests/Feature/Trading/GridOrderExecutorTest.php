<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Models\GridOrder;
use App\Services\GridOrderExecutor;
use App\Services\NobitexService;
use App\Support\OrderRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 12 Step 1 — behaviour lock-in for GridOrderExecutor::applyForBot()
 * (GridOrderExecutor.php :49-295).
 *
 * Covers three properties that later Phase 12 steps must preserve or change
 * deliberately:
 *   1. Simulation makes ZERO outbound HTTP calls.
 *   2. The client_order_id dedup guard blocks a duplicate live submission.
 *   3. A throw AFTER the API call was attempted leaves the intent row in
 *      'submission_unknown' — the Phase-9-era guard (:257-273).
 *
 * Uses a hand-built sqlite schema (see BuildsGridSchema) because the project's
 * MySQL-only migrations cannot run on the configured :memory: test DB.
 */
final class GridOrderExecutorTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL = 'BTCIRT';
    private const BOT_ID = 1;
    private const PRICE  = 100_000_000; // aligned to tick=1, no rounding drift

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

    /**
     * A single valid buy level; notional is supplied explicitly and sits above
     * the min so the executor neither recomputes it nor skips the level.
     *
     * @return array<string,mixed>
     */
    private function buyDiff(): array
    {
        return [
            'symbol'              => self::SYMBOL,
            'tick'                => 1,
            'min_order_value_irt' => 50_000,
            'to_place'            => [[
                'side'     => 'buy',
                'price'    => self::PRICE,
                'quantity' => '0.001',
                'notional' => 100_000,
            ]],
        ];
    }

    private function expectedClientOrderId(): string
    {
        return GridOrder::buildClientOrderId(self::BOT_ID, self::SYMBOL, 'buy', self::PRICE);
    }

    /** 1. Simulation places a SIM-* row and sends NOTHING over the wire. */
    public function test_simulation_places_sim_row_without_any_http(): void
    {
        Http::fake(); // record everything; nothing should reach it

        $executor = new GridOrderExecutor(new NobitexService(), new OrderRegistry());
        $executor->applyForBot(self::BOT_ID, $this->buyDiff(), simulation: true);

        Http::assertNothingSent();

        $this->assertSame(1, GridOrder::count());
        $order = GridOrder::first();
        $this->assertSame('placed', $order->status);
        $this->assertSame('buy', $order->type);
        $this->assertStringStartsWith('SIM-', (string) $order->nobitex_order_id);
        $this->assertSame($this->expectedClientOrderId(), $order->client_order_id);
    }

    /**
     * 2. A live submission whose client_order_id already matches an ACTIVE row
     *    is dedup-skipped: the exchange createOrder() is never called and no
     *    second row is written.
     */
    public function test_live_duplicate_client_order_id_is_dedup_skipped(): void
    {
        GridOrder::create([
            'bot_config_id'   => self::BOT_ID,
            'price'           => self::PRICE,
            'amount'          => '0.001',
            'type'            => 'buy',
            'status'          => 'placed', // active → triggers the dedup guard
            'client_order_id' => $this->expectedClientOrderId(),
        ]);

        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('createOrder')->never();

        $executor = new GridOrderExecutor($svc, new OrderRegistry());
        $executor->applyForBot(self::BOT_ID, $this->buyDiff(), simulation: false);

        // Still exactly the one pre-existing row; nothing new was created.
        $this->assertSame(1, GridOrder::count());
    }

    /**
     * 3. Live submission where createOrder() throws AFTER being invoked (a
     *    timeout/dropped-response class failure): the intent row must land in
     *    'submission_unknown', NOT 'cancelled', because the exchange may have
     *    accepted the order. Locks in the Phase-9 guard at :257-273.
     */
    public function test_live_throw_after_api_call_marks_submission_unknown(): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('createOrder')
            ->once()
            ->andThrow(new RuntimeException('cURL error 28: Operation timed out'));

        $executor = new GridOrderExecutor($svc, new OrderRegistry());
        $executor->applyForBot(self::BOT_ID, $this->buyDiff(), simulation: false);

        $this->assertSame(1, GridOrder::count());
        $order = GridOrder::first();
        $this->assertSame('submission_unknown', $order->status);
        $this->assertSame($this->expectedClientOrderId(), $order->client_order_id);
    }

    /**
     * 4. End-to-end (Phase 12 Step 5): a LIVE createOrder against the REAL
     *    NobitexService whose /market/orders/add POST raises a
     *    ConnectionException. Because that endpoint now uses the non-idempotent
     *    retry policy, request() must NOT blind-retry — so exactly ONE HTTP
     *    attempt is made — and the ambiguous failure must surface (as
     *    AmbiguousOrderSubmissionException) into the executor's catch, leaving
     *    the intent row in 'submission_unknown'. This is the whole point of the
     *    step: a lost-response timeout can never silently re-place the order.
     */
    public function test_live_connection_exception_makes_one_attempt_and_marks_submission_unknown(): void
    {
        // Deterministic, fast: real service, no sleeping between (non-existent) retries.
        config([
            'trading.nobitex.base_url'    => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'     => '',
            // Order endpoints now authenticate via Ed25519 signing (PART 3), so a
            // valid keypair must be configured or signRequest() throws before send.
            'trading.nobitex.api_public_key'  => 'test-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES)), '+/', '-_'), '='),
            'trading.nobitex.retry.times' => 3,
            'trading.nobitex.retry.sleep' => 0,
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $executor = new GridOrderExecutor(new NobitexService(), new OrderRegistry());
        $executor->applyForBot(self::BOT_ID, $this->buyDiff(), simulation: false);

        // The in-closure counter is the authoritative "exactly one attempt"
        // proof: a thrown ConnectionException never produces a response, so
        // Http's recorder never logs it (assertSentCount would see 0).
        $this->assertSame(1, $calls, 'The order POST must be attempted exactly once — no blind retry.');

        $this->assertSame(1, GridOrder::count());
        $order = GridOrder::first();
        $this->assertSame('submission_unknown', $order->status);
        $this->assertSame($this->expectedClientOrderId(), $order->client_order_id);
    }

    /**
     * 5. Bug fix — SIMULATION cancel must mutate the DB, not just log.
     *
     * Before the fix, the $simulation branch of the to_cancel loop only logged
     * EXEC_SIM_CANCEL and continued, so a stale local GridOrder scheduled for
     * cancellation stayed status='placed' forever while the sim PLACE branch
     * persisted new rows — the exact reason bot 46 showed old initial_grid sells
     * coexisting with new rebalance sells. The fix mirrors the live branch's
     * local update (:89-91) keyed on the same nobitex_order_id, WITHOUT touching
     * the exchange. This test proves the targeted row flips to 'cancelled' and
     * an unrelated row is left alone (no over-cancel).
     */
    public function test_simulation_cancel_flips_matching_local_row_to_cancelled(): void
    {
        Http::fake(); // nothing should reach the wire in simulation

        // The stale order the rebalance wants gone (SIM sentinel id, as
        // getOpenForBot would surface it for a simulated bot).
        $stale = GridOrder::create([
            'bot_config_id'    => self::BOT_ID,
            'price'            => 126_000_000,
            'amount'           => '0.001',
            'type'             => 'sell',
            'status'           => 'placed',
            'role'             => 'initial_grid',
            'nobitex_order_id' => 'SIM-stale-1',
            'client_order_id'  => 'cli-stale-1',
        ]);

        // A second placed order NOT in the cancel list — must remain untouched.
        $keep = GridOrder::create([
            'bot_config_id'    => self::BOT_ID,
            'price'            => 124_000_000,
            'amount'           => '0.001',
            'type'             => 'sell',
            'status'           => 'placed',
            'role'             => 'initial_grid',
            'nobitex_order_id' => 'SIM-keep-1',
            'client_order_id'  => 'cli-keep-1',
        ]);

        // The exchange must NEVER be called in the simulation cancel path.
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('cancelOrder')->never();

        $diff = [
            'symbol'    => self::SYMBOL,
            'tick'      => 1,
            'to_cancel' => [[
                'id'    => 'SIM-stale-1',
                'price' => 126_000_000,
            ]],
            // no to_place → the bcmath-backed place loop never runs
        ];

        $executor = new GridOrderExecutor($svc, new OrderRegistry());
        $executor->applyForBot(self::BOT_ID, $diff, simulation: true, role: 'rebalance');

        Http::assertNothingSent();

        // The targeted stale row is now cancelled — it does NOT linger as 'placed'.
        $this->assertSame('cancelled', $stale->fresh()->status);
        // The unrelated row is untouched (no over-cancel).
        $this->assertSame('placed', $keep->fresh()->status);
    }
}
