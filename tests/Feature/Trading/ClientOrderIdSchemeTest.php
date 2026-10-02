<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\DTOs\CreateOrderDto;
use App\DTOs\CreateOrderResponse;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Exceptions\DefinitiveInvalidArgumentRejection;
use App\Exceptions\DefinitiveOrderRejection;
use App\Exceptions\DuplicateClientOrderIdException;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\GridOrderExecutor;
use App\Services\NobitexService;
use App\Services\SubmissionReconciler;
use App\Support\OrderRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * clientOrderId v2 — "g{botId}-{gridOrderRowId}", one id per order intent.
 *
 *  F3 regression : a price that was used before (by any row, any status) no
 *                  longer DEDUP_SKIPs a new exit / grid order or collides with
 *                  the global UNIQUE index on grid_orders.client_order_id.
 *  Idempotency   : an ambiguous placement leaves the intent row with the id
 *                  that was sent; the reconciler resolves it by that same id.
 *  Intent dedup  : a second ACTIVE order for the same bot+side+price is still
 *                  refused; filled/cancelled rows do not block.
 *  Send boundary : an id Nobitex would reject never leaves the process.
 *  Duplicate id  : the exchange's duplicate-clientOrderId answer routes to
 *                  submission_unknown → reconciler, never to a new id.
 *
 * Drives the REAL NobitexService against Http::fake where the exchange body
 * matters (same harness as DefinitiveRejectionTest / SubmissionReconcilerTest).
 */
final class ClientOrderIdSchemeTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL     = 'BTCIRT';
    private const BUY_PRICE  = '111939999980';
    private const EXIT_PRICE = '113619099980'; // × 1.015, half-up
    private const GRID_PRICE = 100_000_000;

    /** @var array<int,array> bodies sent to /market/orders/add */
    private array $orders = [];

    /** @var array<string,array> clientOrderId → order the fake exchange holds */
    private array $book = [];

    /** @var array<int,string> */
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->orders = [];
        $this->book = [];
        $this->messages = [];

        config([
            'trading.nobitex.base_url'        => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'         => '',
            'trading.nobitex.api_public_key'  => 'test-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES)), '+/', '-_'), '='),
            'trading.nobitex.retry.times'     => 3,
            'trading.nobitex.retry.sleep'     => 0,
            'trading.nobitex.rate_limit.rpm'  => 1000,
            'trading.fees.buy_fee_bps'        => '25',
            'trading.fees.sell_fee_bps'       => '25',
            'trading.min_order_value_irt'     => 3_000_000,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
            'trading.reconcile.enabled'                 => true,
            'trading.reconcile.min_age_seconds'         => 60,
            'trading.reconcile.pending_min_age_seconds' => 60,
            'trading.reconcile.not_found_confirmations' => 2,
            'trading.reconcile.cancel_on_not_found'     => true,
            'trading.reconcile.max_attempts'            => 5,
            'trading.reconcile.max_age_hours'           => 6,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->messages[] = $m->message;
        });
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function bot(bool $simulation): BotConfig
    {
        return BotConfig::create([
            'name' => 'cid-v2', 'symbol' => self::SYMBOL, 'simulation' => $simulation,
            'is_active' => true, 'grid_spacing' => 1.50,
        ]);
    }

    /** A filled buy at BUY_PRICE — every such fill's exit lands on EXIT_PRICE. */
    private function fill(BotConfig $bot, int $n): GridOrder
    {
        return GridOrder::create([
            'bot_config_id' => $bot->id, 'price' => self::BUY_PRICE, 'amount' => '0.0003',
            'original_amount' => '0.0003', 'filled_amount' => '0.0003', 'type' => 'buy',
            'status' => 'filled', 'client_order_id' => "seed-buy-{$n}", 'nobitex_order_id' => (string) (100 + $n),
            'fee_amount' => '0.00000075', 'fee_currency' => 'base', 'fee_source' => 'actual',
            'avg_fill_price' => self::BUY_PRICE, 'net_base_delta' => '0.00029925',
        ]);
    }

    private function pair(GridOrder $fill, BotConfig $bot): void
    {
        $job = new CheckTradesJob();
        (new ReflectionMethod($job, 'createPairOrderLocked'))->invoke($job, $fill, $bot);
    }

    private function exitRow(GridOrder $fill): ?GridOrder
    {
        return GridOrder::where('role', 'cycle_exit')->where('paired_order_id', $fill->id)->latest('id')->first();
    }

    private function assertV2Id(GridOrder $row): void
    {
        $this->assertSame('g' . $row->bot_config_id . '-' . $row->id, $row->client_order_id);
        $this->assertTrue(GridOrder::isValidNobitexClientOrderId((string) $row->client_order_id));
    }

    /**
     * Fake Nobitex. /market/orders/add consumes the next scripted response
     * (array → JSON body, Throwable → thrown; default: accepted). An add the
     * exchange "accepted" — including one whose response was then lost —
     * is put in $book under its clientOrderId, which /market/orders/status
     * answers by clientOrderId.
     *
     * @param array<int,array{0:array|\Throwable,1:bool}> $addScript [response, exchangeKeepsOrder]
     */
    private function fakeExchange(array $addScript = []): void
    {
        $nextId = 9000;
        Http::fake(function (Request $request) use (&$addScript, &$nextId) {
            $url = $request->url();
            if (str_contains($url, '/market/orders/add')) {
                $body = json_decode($request->body(), true);
                $this->orders[] = $body;
                [$resp, $keeps] = array_shift($addScript) ?? [null, true];
                $id = ++$nextId;
                if ($keeps) {
                    $this->book[(string) $body['clientOrderId']] = [
                        'id' => $id, 'type' => $body['type'], 'price' => $body['price'],
                        'amount' => $body['amount'], 'status' => 'Active', 'clientOrderId' => $body['clientOrderId'],
                    ];
                }
                if ($resp instanceof \Throwable) {
                    throw $resp;
                }
                return Http::response($resp ?? ['status' => 'ok', 'order' => ['id' => $id]], 200);
            }
            if (str_contains($url, '/market/orders/status')) {
                $cid = (string) ($request->data()['clientOrderId'] ?? '');
                return isset($this->book[$cid])
                    ? Http::response(['status' => 'ok', 'order' => $this->book[$cid]], 200)
                    : Http::response(['status' => 'failed', 'code' => 'NotFound', 'message' => 'nf'], 200);
            }
            if (str_contains($url, '/market/orders/list')) {
                return Http::response(['status' => 'ok', 'orders' => array_values($this->book)], 200);
            }
            return Http::response(['status' => 'failed', 'code' => 'NotFound'], 200);
        });
    }

    private function ageAll(): void
    {
        DB::table('grid_orders')->update(['created_at' => now()->subHour()]);
    }

    private function gridDiff(string $side = 'buy'): array
    {
        return [
            'symbol' => self::SYMBOL, 'tick' => 1, 'min_order_value_irt' => 50_000,
            'to_place' => [['side' => $side, 'price' => self::GRID_PRICE, 'quantity' => '0.001', 'notional' => 100_000]],
        ];
    }

    /** Mocked service that accepts every createOrder and records the clientRef sent. */
    private function acceptingService(array &$sent): NobitexService
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('createOrder')->andReturnUsing(function (CreateOrderDto $dto) use (&$sent) {
            $sent[] = $dto->clientRef;
            return new CreateOrderResponse(ok: true, orderId: (string) (7000 + count($sent)));
        });

        return $svc;
    }

    // ── F3 regression: pair exits ──────────────────────────────────────────

    public function test_two_fills_whose_exits_land_on_the_same_price_both_get_exits_live(): void
    {
        $this->fakeExchange();
        $bot   = $this->bot(simulation: false);
        $fill1 = $this->fill($bot, 1);
        $fill2 = $this->fill($bot, 2);

        $this->pair($fill1, $bot);
        $this->pair($fill2, $bot);

        $exit1 = $this->exitRow($fill1);
        $exit2 = $this->exitRow($fill2);
        $this->assertNotNull($exit1);
        $this->assertNotNull($exit2, 'The second fill must get its own exit at the same price (no DEDUP_SKIP).');
        $this->assertSame(self::EXIT_PRICE, (string) $exit1->price);
        $this->assertSame(self::EXIT_PRICE, (string) $exit2->price);
        $this->assertSame('placed', $exit1->status);
        $this->assertSame('placed', $exit2->status);
        $this->assertV2Id($exit1);
        $this->assertV2Id($exit2);
        $this->assertNotSame($exit1->client_order_id, $exit2->client_order_id);

        $this->assertCount(2, $this->orders);
        $this->assertSame($exit1->client_order_id, $this->orders[0]['clientOrderId']);
        $this->assertSame($exit2->client_order_id, $this->orders[1]['clientOrderId']);
        $this->assertNotContains('DEDUP_SKIP', $this->messages);
        $this->assertSame($exit2->id, (int) $fill2->fresh()->paired_order_id);
    }

    public function test_exit_at_a_price_whose_previous_exit_already_filled_is_placed_in_simulation(): void
    {
        Http::fake();
        $bot   = $this->bot(simulation: true);
        $fill1 = $this->fill($bot, 1);
        $this->pair($fill1, $bot);

        // The first cycle closed: its exit at EXIT_PRICE filled.
        $exit1 = $this->exitRow($fill1);
        $exit1->update(['status' => 'filled']);

        $fill2 = $this->fill($bot, 2);
        $this->pair($fill2, $bot);

        $exit2 = $this->exitRow($fill2);
        $this->assertNotNull($exit2, 'A filled exit at the same price must not block the next cycle.');
        $this->assertSame('placed', $exit2->status);
        $this->assertSame(self::EXIT_PRICE, (string) $exit2->price);
        $this->assertStringStartsWith('SIM-', (string) $exit2->nobitex_order_id);
        $this->assertV2Id($exit2);
        $this->assertNotSame($exit1->client_order_id, $exit2->client_order_id);
        $this->assertNotContains('DEDUP_SKIP', $this->messages);
        Http::assertNothingSent();
    }

    // ── F3 regression: initial grid / rebalance ────────────────────────────

    /** @return array<string,array{0:string,1:bool}> */
    public static function retiredLevels(): array
    {
        return [
            'filled, simulation'    => ['filled', true],
            'cancelled, simulation' => ['cancelled', true],
            'filled, live'          => ['filled', false],
            'cancelled, live'       => ['cancelled', false],
        ];
    }

    #[DataProvider('retiredLevels')]
    public function test_grid_level_can_be_placed_again_after_its_order_is_retired(string $retiredStatus, bool $simulation): void
    {
        Http::fake();
        $bot  = $this->bot($simulation);
        $sent = [];
        $executor = new GridOrderExecutor($this->acceptingService($sent), new OrderRegistry());

        $executor->applyForBot($bot->id, $this->gridDiff(), $simulation, 'initial_grid');
        $first = GridOrder::sole();
        $first->update(['status' => $retiredStatus]);

        $executor->applyForBot($bot->id, $this->gridDiff(), $simulation, 'rebalance');

        $rows = GridOrder::orderBy('id')->get();
        $this->assertCount(2, $rows, 'A retired level must be re-placeable (no DEDUP_SKIP, no UNIQUE violation).');
        $second = $rows[1];
        $this->assertSame('placed', $second->status);
        $this->assertSame((string) self::GRID_PRICE, (string) $second->price);
        $this->assertV2Id($first);
        $this->assertV2Id($second);
        $this->assertNotSame($first->client_order_id, $second->client_order_id);
        $this->assertSame($retiredStatus, $first->fresh()->status);

        if ($simulation) {
            $this->assertSame([], $sent);
        } else {
            $this->assertSame([$first->client_order_id, $second->client_order_id], $sent);
        }
    }

    // ── intent dedup ───────────────────────────────────────────────────────

    /** @return array<string,array{0:string,1:bool}> */
    public static function activeStatuses(): array
    {
        // Spelled out (not read from GridOrderExecutor::ACTIVE_STATUSES) so
        // dropping a status from the guard fails here instead of silently
        // dropping its test case.
        $out = [];
        foreach (['pending', 'placed', 'partially_filled', 'submission_unknown'] as $status) {
            $out["{$status}, simulation"] = [$status, true];
            $out["{$status}, live"]       = [$status, false];
        }

        return $out;
    }

    #[DataProvider('activeStatuses')]
    public function test_second_active_order_for_same_bot_side_price_is_still_prevented(string $status, bool $simulation): void
    {
        Http::fake();
        $bot = $this->bot($simulation);
        GridOrder::createIntent([
            'bot_config_id' => $bot->id, 'price' => self::GRID_PRICE, 'amount' => '0.001',
            'type' => 'buy', 'status' => $status,
        ]);

        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('createOrder')->never();
        (new GridOrderExecutor($svc, new OrderRegistry()))->applyForBot($bot->id, $this->gridDiff(), $simulation);

        $this->assertSame(1, GridOrder::count());
        $this->assertContains('DEDUP_SKIP', $this->messages);
        Http::assertNothingSent();
    }

    public function test_active_order_on_the_other_side_or_another_bot_does_not_block(): void
    {
        $bot   = $this->bot(simulation: false);
        $other = $this->bot(simulation: false);
        GridOrder::createIntent(['bot_config_id' => $bot->id, 'price' => self::GRID_PRICE, 'amount' => '0.001', 'type' => 'sell', 'status' => 'placed']);
        GridOrder::createIntent(['bot_config_id' => $other->id, 'price' => self::GRID_PRICE, 'amount' => '0.001', 'type' => 'buy', 'status' => 'placed']);

        $sent = [];
        (new GridOrderExecutor($this->acceptingService($sent), new OrderRegistry()))->applyForBot($bot->id, $this->gridDiff('buy'), false);

        $this->assertCount(1, $sent);
        $this->assertSame(3, GridOrder::count());
    }

    /**
     * Per-row ids mean the UNIQUE index no longer collides two concurrent
     * runs placing the same level; the per-level lock does. A level another
     * run is placing right now is skipped, and nothing is sent for it.
     */
    public function test_level_being_placed_by_a_concurrent_run_is_skipped(): void
    {
        $bot  = $this->bot(simulation: false);
        $sent = [];
        $executor = new GridOrderExecutor($this->acceptingService($sent), new OrderRegistry());

        $other = Cache::lock("grid-level:{$bot->id}:buy:" . self::GRID_PRICE, 10);
        $this->assertTrue($other->get());

        $executor->applyForBot($bot->id, $this->gridDiff(), false);
        $this->assertSame([], $sent);
        $this->assertSame(0, GridOrder::count());
        $this->assertContains('DEDUP_LOCK_BUSY', $this->messages);

        $other->release();
        $executor->applyForBot($bot->id, $this->gridDiff(), false);
        $this->assertCount(1, $sent);

        // The lock is released after the intent row commits (before the
        // exchange call) and stays free once the run is done.
        $probe = Cache::lock("grid-level:{$bot->id}:buy:" . self::GRID_PRICE, 10);
        $this->assertTrue($probe->get());
        $probe->release();

        // And a third run is refused by the intent dedup, not the lock.
        $executor->applyForBot($bot->id, $this->gridDiff(), false);
        $this->assertCount(1, $sent);
        $this->assertContains('DEDUP_SKIP', $this->messages);
    }

    // ── idempotency: same intent → same id → reconciler ────────────────────

    public function test_ambiguous_pair_placement_is_resolved_by_the_reconciler_under_the_same_id(): void
    {
        // The exchange accepts the order but the response is lost (timeout).
        $this->fakeExchange([[new ConnectionException('cURL error 28: Operation timed out'), true]]);
        $bot  = $this->bot(simulation: false);
        $fill = $this->fill($bot, 1);

        $this->pair($fill, $bot);

        $exit = $this->exitRow($fill);
        $this->assertSame('submission_unknown', $exit->status);
        $this->assertV2Id($exit);
        $this->assertCount(1, $this->orders);
        $this->assertSame($exit->client_order_id, $this->orders[0]['clientOrderId']);

        // A re-entry for the same fill never re-sends (the intent row owns it).
        $this->pair($fill->fresh(), $bot);
        $this->assertCount(1, $this->orders);

        $this->ageAll();
        $summary = app(SubmissionReconciler::class)->run();

        $exit->refresh();
        $this->assertSame(1, $summary['placed']);
        $this->assertSame('placed', $exit->status);
        $this->assertSame((string) $this->book[$exit->client_order_id]['id'], (string) $exit->nobitex_order_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/market/orders/status')
            && ($r->data()['clientOrderId'] ?? null) === $exit->client_order_id);
        // The id never changed across the whole lifecycle.
        $this->assertV2Id($exit);
        $this->assertCount(1, $this->orders, 'Reconciliation never re-sends.');
    }

    public function test_ambiguous_grid_placement_keeps_its_id_and_is_resolved_by_it(): void
    {
        $this->fakeExchange([[new ConnectionException('cURL error 28: Operation timed out'), true]]);
        $bot = $this->bot(simulation: false);
        $executor = new GridOrderExecutor(new NobitexService(), new OrderRegistry());

        $executor->applyForBot($bot->id, $this->gridDiff(), false, 'initial_grid');
        $row = GridOrder::sole();
        $this->assertSame('submission_unknown', $row->status);
        $this->assertSame($row->client_order_id, $this->orders[0]['clientOrderId']);

        // Re-running the same plan finds the unresolved intent and does not
        // send again (no second id, no second order).
        $executor->applyForBot($bot->id, $this->gridDiff(), false, 'initial_grid');
        $this->assertCount(1, $this->orders);
        $this->assertSame(1, GridOrder::count());

        $this->ageAll();
        app(SubmissionReconciler::class)->run();
        $this->assertSame('placed', $row->fresh()->status);
        $this->assertSame($row->client_order_id, $row->fresh()->client_order_id);
    }

    // ── duplicate-clientOrderId answer → reconciler path ───────────────────

    /** @return array<string,array{0:string}> */
    public static function duplicateCodes(): array
    {
        return ['spot' => ['DuplicateClientOrderId'], 'margin docs casing' => ['duplicateClientOrderId']];
    }

    #[DataProvider('duplicateCodes')]
    public function test_duplicate_client_order_id_code_is_ambiguous_not_definitive(string $code): void
    {
        $svc = new NobitexService();
        $m   = new ReflectionMethod($svc, 'throwDomainError');

        try {
            $m->invoke($svc, ['status' => 'failed', 'code' => $code, 'message' => 'dup']);
            $this->fail('throwDomainError must throw.');
        } catch (DuplicateClientOrderIdException $e) {
            $this->assertNotInstanceOf(DefinitiveOrderRejection::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[DataProvider('duplicateCodes')]
    public function test_duplicate_client_order_id_on_pair_exit_routes_to_reconciler(string $code): void
    {
        // An earlier attempt of this same intent is already on the book.
        $this->fakeExchange([[['status' => 'failed', 'code' => $code, 'message' => 'duplicate'], true]]);
        $bot  = $this->bot(simulation: false);
        $fill = $this->fill($bot, 1);

        $this->pair($fill, $bot);

        $exit = $this->exitRow($fill);
        $this->assertSame('submission_unknown', $exit->status, 'Duplicate id must never be treated as a definitive rejection.');
        $this->assertNull($exit->last_error_code);
        $this->assertSame($exit->id, (int) $fill->fresh()->paired_order_id, 'Fill stays linked — no re-pair under a new id.');
        $this->assertNull($fill->fresh()->exit_state);
        $this->assertCount(1, $this->orders, 'No re-send, under this or any other id.');

        $this->ageAll();
        app(SubmissionReconciler::class)->run();

        $exit->refresh();
        $this->assertSame('placed', $exit->status);
        $this->assertSame($this->orders[0]['clientOrderId'], $exit->client_order_id);
        $this->assertNotNull($exit->nobitex_order_id);
    }

    public function test_duplicate_client_order_id_on_grid_order_routes_to_submission_unknown(): void
    {
        $this->fakeExchange([[['status' => 'failed', 'code' => 'DuplicateClientOrderId', 'message' => 'duplicate'], true]]);
        $bot = $this->bot(simulation: false);

        (new GridOrderExecutor(new NobitexService(), new OrderRegistry()))->applyForBot($bot->id, $this->gridDiff(), false);

        $row = GridOrder::sole();
        $this->assertSame('submission_unknown', $row->status);
        $this->assertNull($row->last_error_code);
        $this->assertCount(1, $this->orders);
    }

    // ── send-boundary validation ───────────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public static function invalidIds(): array
    {
        return [
            'legacy colon format' => ['grid:47:BTCIRT:sell:1'],
            '33 chars'            => [str_repeat('a', 33)],
            'legacy 33-char id'   => ['grid:47:BTCIRT:sell:216389999960'],
            'trailing newline'    => ["g47-1\n"],
            'empty'               => [''],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_place_order_rejects_invalid_client_order_id_before_any_http(string $id): void
    {
        Http::fake();

        try {
            (new NobitexService())->placeOrder(self::SYMBOL, 'sell', 101_000_000, '0.001', $id);
            $this->fail('placeOrder must refuse an invalid clientOrderId.');
        } catch (DefinitiveInvalidArgumentRejection $e) {
            $this->assertSame('LocalValidation', $e->errorCode());
        }

        Http::assertNothingSent();
    }

    #[DataProvider('invalidIds')]
    public function test_create_order_rejects_invalid_client_order_id_before_any_http(string $id): void
    {
        Http::fake();

        try {
            (new NobitexService())->createOrder(new CreateOrderDto(
                side: OrderSide::SELL, execution: ExecutionType::LIMIT, srcCurrency: 'btc', dstCurrency: 'irt',
                amountBase: '0.001', priceIRT: 101_000_000, clientRef: $id,
            ));
            $this->fail('createOrder must refuse an invalid clientOrderId.');
        } catch (DefinitiveInvalidArgumentRejection $e) {
            $this->assertSame('LocalValidation', $e->errorCode());
        }

        Http::assertNothingSent();
    }

    // ── storage invariants ─────────────────────────────────────────────────

    public function test_unique_index_allows_many_null_ids_but_no_duplicate_id(): void
    {
        $bot = $this->bot(simulation: true);
        $base = ['bot_config_id' => $bot->id, 'price' => self::GRID_PRICE, 'amount' => '0.001', 'type' => 'buy', 'status' => 'cancelled'];

        GridOrder::create($base + ['client_order_id' => null]);
        GridOrder::create($base + ['client_order_id' => null]);
        $this->assertSame(2, GridOrder::whereNull('client_order_id')->count());

        GridOrder::create($base + ['client_order_id' => 'g1-1']);
        $this->expectException(QueryException::class);
        GridOrder::create($base + ['client_order_id' => 'g1-1']);
    }

    public function test_create_intent_stamps_the_id_atomically_and_nests_in_a_caller_transaction(): void
    {
        $bot = $this->bot(simulation: true);
        $attrs = ['bot_config_id' => $bot->id, 'price' => self::GRID_PRICE, 'amount' => '0.001', 'type' => 'buy', 'status' => 'pending'];

        // A caller-supplied id is ignored: the id always comes from the row.
        $row = GridOrder::createIntent($attrs + ['client_order_id' => 'grid:1:BTCIRT:buy:1']);
        $this->assertV2Id($row);
        $this->assertSame($row->client_order_id, GridOrder::whereKey($row->id)->value('client_order_id'));

        // Rolled back with the caller's transaction (the pair path's case).
        DB::beginTransaction();
        $inner = GridOrder::createIntent($attrs);
        DB::rollBack();
        $this->assertFalse(GridOrder::whereKey($inner->id)->exists());
        $this->assertSame(0, GridOrder::whereNull('client_order_id')->count());
    }

    public function test_legacy_rows_keep_their_ids(): void
    {
        $this->fakeExchange();
        $bot  = $this->bot(simulation: false);
        $legacyId = 'grid:' . $bot->id . ':BTCIRT:sell:' . self::EXIT_PRICE;
        $legacy = GridOrder::create([
            'bot_config_id' => $bot->id, 'price' => self::EXIT_PRICE, 'amount' => '0.0003', 'type' => 'sell',
            'status' => 'filled', 'role' => 'cycle_exit', 'client_order_id' => $legacyId,
        ]);

        $fill = $this->fill($bot, 1);
        $this->pair($fill, $bot);

        $this->assertSame($legacyId, $legacy->fresh()->client_order_id);
        $this->assertSame('placed', $this->exitRow($fill)->status);
    }
}
