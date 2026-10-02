<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Exceptions\AmbiguousOrderSubmissionException;
use App\Exceptions\DefinitiveOrderRejection;
use App\Exceptions\InsufficientBalanceRejection;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\GridOrderExecutor;
use App\Services\NobitexService;
use App\Services\SubmissionReconciler;
use App\Support\Money;
use App\Support\OrderRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 5 — definitive rejections vs ambiguity (audit D2/E2) and
 * the exit-sell self-heal. Drives the REAL NobitexService against Http::fake
 * so the exchange's {status:"failed", code:...} body goes through the real
 * request()/throwDomainError() path.
 */
final class DefinitiveRejectionTest extends TestCase
{
    use BuildsGridSchema;

    private const BUY_PRICE  = '111939999980';
    private const EXIT_PRICE = 113619099980; // × 1.015, half-up

    /** @var array<int,array> bodies sent to /market/orders/add */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->orders = [];
        config([
            'trading.nobitex.base_url'        => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'         => '',
            'trading.nobitex.api_public_key'  => 'test-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES)), '+/', '-_'), '='),
            'trading.nobitex.retry.times'     => 3,
            'trading.nobitex.retry.sleep'     => 0,
            'trading.nobitex.rate_limit.rpm'  => 1000,
            'trading.fees.buy_fee_bps'  => '25',
            'trading.fees.sell_fee_bps' => '25',
            'trading.fees.self_heal_min_ratio' => '0.98',
            'trading.min_order_value_irt' => 3_000_000,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function bot(): BotConfig
    {
        return BotConfig::create([
            'name' => 'rejections', 'symbol' => 'BTCIRT', 'simulation' => false,
            'is_active' => true, 'grid_spacing' => 1.50,
        ]);
    }

    /** A filled live buy of 0.0003 BTC with its actual BTC fee (V1). */
    private function fill(BotConfig $bot): GridOrder
    {
        return GridOrder::create([
            'bot_config_id' => $bot->id, 'price' => self::BUY_PRICE, 'amount' => '0.0003',
            'original_amount' => '0.0003', 'filled_amount' => '0.0003', 'type' => 'buy',
            'status' => 'filled', 'client_order_id' => 'seed-buy', 'nobitex_order_id' => '1',
            'fee_amount' => '0.00000075', 'fee_currency' => 'base', 'fee_source' => 'actual',
            'avg_fill_price' => self::BUY_PRICE, 'net_base_delta' => '0.00029925',
        ]);
    }

    /**
     * Route Nobitex calls: each /market/orders/add consumes the next scripted
     * response (array → JSON body, Throwable → thrown); /users/wallets/list
     * returns the given free BTC.
     *
     * @param array<int,array|\Throwable> $addResponses
     */
    private function fakeExchange(array $addResponses, string $freeBtc = '0'): void
    {
        Http::fake(function ($request) use (&$addResponses, $freeBtc) {
            if (str_contains($request->url(), '/users/wallets/list')) {
                return Http::response(['status' => 'ok', 'wallets' => [
                    ['currency' => 'btc', 'balance' => $freeBtc, 'blockedBalance' => '0', 'activeBalance' => $freeBtc],
                ]], 200);
            }
            if (str_contains($request->url(), '/market/orders/add')) {
                $this->orders[] = json_decode($request->body(), true);
                $next = array_shift($addResponses) ?? ['status' => 'ok', 'order' => ['id' => 4242]];
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return Http::response($next, 200);
            }
            return Http::response(['status' => 'failed', 'code' => 'NotFound'], 200);
        });
    }

    private static function failed(string $code): array
    {
        return ['status' => 'failed', 'code' => $code, 'message' => "{$code} from exchange"];
    }

    private function pair(GridOrder $fill, BotConfig $bot): void
    {
        $job = new CheckTradesJob();
        $m   = new ReflectionMethod($job, 'createPairOrderLocked');
        $m->invoke($job, $fill, $bot);
    }

    private function exitRow(GridOrder $fill): GridOrder
    {
        return GridOrder::where('role', 'cycle_exit')->where('paired_order_id', $fill->id)->latest('id')->firstOrFail();
    }

    // ── exception classification ───────────────────────────────────────────

    public function test_definitive_codes_keep_their_old_types_and_messages(): void
    {
        $this->fakeExchange([self::failed('InsufficientBalance'), self::failed('SmallOrder'), self::failed('BadPrice'),
            self::failed('MarketClosed'), self::failed('InvalidMarketPair'), self::failed('DuplicateOrder')]);
        $svc = new NobitexService();

        $cases = [
            ['InsufficientBalance', \RuntimeException::class, 'Insufficient balance', true],
            ['SmallOrder', \InvalidArgumentException::class, 'Order below market minimum', true],
            ['BadPrice', \InvalidArgumentException::class, 'Bad price', true],
            ['MarketClosed', \RuntimeException::class, 'Market closed', true],
            ['InvalidMarketPair', \DomainException::class, 'Invalid market symbol pair', true],
            // An identical order may already exist — NOT definitive.
            ['DuplicateOrder', \RuntimeException::class, 'Duplicate order in last 10s', false],
        ];
        foreach ($cases as [$code, $type, $message, $definitive]) {
            try {
                $svc->placeOrder('BTCIRT', 'sell', self::EXIT_PRICE, '0.00029925', 'cid');
                $this->fail("{$code}: expected an exception");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($type, $e, $code);
                $this->assertSame($message, $e->getMessage(), $code);
                $this->assertSame($definitive, $e instanceof DefinitiveOrderRejection, $code);
                if ($definitive) {
                    $this->assertSame($code, $e->errorCode());
                }
            }
        }
        $this->assertCount(6, $this->orders, 'a definitive failed answer is never retried');
    }

    public function test_timeout_stays_ambiguous(): void
    {
        $this->fakeExchange([new ConnectionException('cURL error 28: Operation timed out')]);
        try {
            (new NobitexService())->placeOrder('BTCIRT', 'sell', self::EXIT_PRICE, '0.00029925', 'cid');
            $this->fail('expected AmbiguousOrderSubmissionException');
        } catch (AmbiguousOrderSubmissionException $e) {
            $this->assertNotInstanceOf(DefinitiveOrderRejection::class, $e);
        }
    }

    // ── exit pairing: block instead of looping ─────────────────────────────

    public function test_insufficient_balance_without_enough_free_btc_blocks_the_fill_and_stops_the_loop(): void
    {
        $log = Mockery::spy(LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->andReturn($log);

        $bot  = $this->bot();
        $fill = $this->fill($bot);
        // Free BTC 0.0002 < 98% of 0.00029925 → no self-heal.
        $this->fakeExchange([self::failed('InsufficientBalance')], freeBtc: '0.0002');

        $this->pair($fill, $bot);

        $exit = $this->exitRow($fill);
        $this->assertSame('cancelled', $exit->status, 'definitive → cancelled, never submission_unknown');
        $this->assertSame('InsufficientBalance', $exit->last_error_code);

        $fill->refresh();
        $this->assertNull($fill->paired_order_id);
        $this->assertSame('blocked', $fill->exit_state);
        $this->assertStringContainsString('InsufficientBalance', (string) $fill->exit_blocked_reason);
        $this->assertNotNull($fill->exit_blocked_at);
        $this->assertSame('EXIT_BLOCKED', BotConfig::find($bot->id)->last_error_code);
        $log->shouldHaveReceived('critical')->withArgs(fn ($m) => $m === 'EXIT_BLOCKED')->once();

        // The reconciler never sees it, and processBot does NOT re-pair it.
        $this->assertCount(0, (new SubmissionReconciler(new NobitexService()))->parkedRows());
        $before = count($this->orders);
        (new CheckTradesJob())->handle();
        $this->assertSame($before, count($this->orders), 'blocked fill must not be re-placed every minute');
        $this->assertSame('blocked', $fill->fresh()->exit_state);
        // Dust reservation reverted.
        $this->assertSame('0', Money::trimZeros((string) BotConfig::find($bot->id)->base_dust));
    }

    public function test_other_definitive_codes_block_without_self_heal(): void
    {
        $bot  = $this->bot();
        $fill = $this->fill($bot);
        $this->fakeExchange([self::failed('SmallOrder')], freeBtc: '1');

        $this->pair($fill, $bot);

        $this->assertSame('cancelled', $this->exitRow($fill)->status);
        $this->assertSame('SmallOrder', $this->exitRow($fill)->last_error_code);
        $this->assertSame('blocked', $fill->fresh()->exit_state);
        $this->assertCount(1, $this->orders, 'no retry for non-balance rejections');
    }

    public function test_self_heal_retries_once_with_the_free_balance_and_records_the_shortfall(): void
    {
        $bot  = $this->bot();
        $fill = $this->fill($bot);
        // Free 0.0002990 >= 98% of 0.00029925 → retry at 0.000299.
        $this->fakeExchange([self::failed('InsufficientBalance'), ['status' => 'ok', 'order' => ['id' => 777]]], freeBtc: '0.000299');

        $this->pair($fill, $bot);

        $this->assertCount(2, $this->orders);
        $this->assertSame('0.00029925', $this->orders[0]['amount']);
        $this->assertSame('0.000299', $this->orders[1]['amount']);
        $this->assertSame($this->orders[0]['clientOrderId'], $this->orders[1]['clientOrderId']);

        $exit = $this->exitRow($fill);
        $this->assertSame('placed', $exit->status);
        $this->assertSame('777', (string) $exit->nobitex_order_id);
        $this->assertSame('0.000299', Money::trimZeros((string) $exit->amount));
        $this->assertSame($exit->id, (int) $fill->fresh()->paired_order_id);
        $this->assertNull($fill->fresh()->exit_state);
        // Shortfall 0.00000025 recorded as negative dust.
        $this->assertSame('-0.00000025', Money::trimZeros((string) BotConfig::find($bot->id)->base_dust));
        $this->assertSame('-0.00000025', Money::trimZeros((string) $exit->exit_dust_delta));
    }

    public function test_self_heal_retry_rejected_again_blocks(): void
    {
        $bot  = $this->bot();
        $fill = $this->fill($bot);
        $this->fakeExchange([self::failed('InsufficientBalance'), self::failed('InsufficientBalance')], freeBtc: '0.000299');

        $this->pair($fill, $bot);

        $this->assertCount(2, $this->orders, 'retried exactly once');
        $this->assertSame('cancelled', $this->exitRow($fill)->status);
        $this->assertSame('blocked', $fill->fresh()->exit_state);
        // Shortfall reservation reverted together with the row.
        $this->assertSame('0', Money::trimZeros((string) BotConfig::find($bot->id)->base_dust));
    }

    public function test_ambiguous_timeout_still_parks_submission_unknown_and_keeps_the_link(): void
    {
        $bot  = $this->bot();
        $fill = $this->fill($bot);
        $this->fakeExchange([new ConnectionException('cURL error 28: Operation timed out')]);

        $this->pair($fill, $bot);

        $exit = $this->exitRow($fill);
        $this->assertSame('submission_unknown', $exit->status);
        $this->assertNull($exit->last_error_code);
        $this->assertSame($exit->id, (int) $fill->fresh()->paired_order_id);
        $this->assertNull($fill->fresh()->exit_state);
    }

    // ── grid orders (initial / rebalance) ──────────────────────────────────

    public function test_grid_order_definitive_rejection_is_cancelled_with_code(): void
    {
        $this->fakeExchange([self::failed('InsufficientBalance')]);
        $exec = new GridOrderExecutor(new NobitexService(), new OrderRegistry());
        $exec->applyForBot(1, [
            'symbol' => 'BTCIRT', 'tick' => 1, 'min_order_value_irt' => 50_000,
            'to_place' => [['side' => 'sell', 'price' => 100_000_000, 'quantity' => '0.001', 'notional' => 100_000]],
        ], simulation: false);

        $o = GridOrder::firstOrFail();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('InsufficientBalance', $o->last_error_code);
    }

    // ── operator command ───────────────────────────────────────────────────

    public function test_exit_blocked_command_lists_retries_and_clears(): void
    {
        $bot  = $this->bot();
        $fill = $this->fill($bot);
        $this->fakeExchange([self::failed('InsufficientBalance')], freeBtc: '0');
        $this->pair($fill, $bot);
        $this->assertSame('blocked', $fill->fresh()->exit_state);

        $this->assertSame(0, Artisan::call('grid:exit-blocked'));
        $this->assertStringContainsString((string) $fill->id, Artisan::output());

        // Retry: un-blocked → next run re-pairs it (this time the exchange accepts).
        $this->assertSame(0, Artisan::call('grid:exit-blocked', ['--retry' => (string) $fill->id]));
        $this->assertNull($fill->fresh()->exit_state);
        $this->assertNull(BotConfig::find($bot->id)->last_error_code);
        (new CheckTradesJob())->handle();
        $this->assertNotNull($fill->fresh()->paired_order_id);
        $this->assertSame('placed', $this->exitRow($fill)->status);

        // Clear: a second blocked fill marked resolved by hand is never re-paired.
        $fill2 = GridOrder::create(array_merge($fill->only(['bot_config_id', 'price', 'amount', 'original_amount', 'filled_amount', 'type', 'fee_amount', 'fee_currency', 'avg_fill_price']), [
            'status' => 'filled', 'client_order_id' => 'seed-buy-2', 'exit_state' => 'blocked', 'exit_blocked_reason' => 'x',
        ]));
        $this->assertSame(0, Artisan::call('grid:exit-blocked', ['--clear' => (string) $fill2->id]));
        $this->assertSame('cleared', $fill2->fresh()->exit_state);
        $before = count($this->orders);
        (new CheckTradesJob())->handle();
        $this->assertSame($before, count($this->orders));
    }
}
