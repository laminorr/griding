<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Models\GridOrder;
use App\Services\GridOrderExecutor;
use App\Services\GridPlanner;
use App\Services\NobitexService;
use App\Support\Money;
use App\Support\OrderRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Send boundary at the real BTCIRT precision (6 dp, tick 10) and the
 * "row is what was sent" rule on the grid path. docs/market-precision.md.
 */
final class MarketPrecisionSendBoundaryTest extends TestCase
{
    use BuildsGridSchema;

    private LoggerInterface $log;

    /** @var array<int,array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();

        $seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        config([
            'trading.exchange.precision_live' => false,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 6,
            'trading.ticks.BTCIRT' => 10,
            'trading.min_order_value_irt' => 3_000_000,
            'trading.nobitex.base_url' => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_public_key' => 'my-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            'trading.nobitex.retry.times' => 1,
            'trading.nobitex.retry.sleep' => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
        ]);

        $this->sent = [];
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/market/orders/add')) {
                $this->sent[] = json_decode($request->body(), true);
                return Http::response(['status' => 'ok', 'order' => ['id' => 880000 + count($this->sent)]], 200);
            }
            return Http::response(['status' => 'failed'], 404);
        });

        $this->log = Mockery::spy(LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->andReturnUsing(
            fn ($c) => $c === 'trading' ? $this->log : Mockery::spy(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private static function decimals(string $v): int
    {
        $dot = strpos($v, '.');
        return $dot === false ? 0 : strlen($v) - $dot - 1;
    }

    // ── NobitexService::placeOrder (exit path boundary) ──────────────────

    public function test_place_order_amount_has_at_most_six_decimals_for_btcirt(): void
    {
        (new NobitexService)->placeOrder('BTCIRT', 'buy', 221949050130, '0.00004512', 'g48-279');

        $this->assertCount(1, $this->sent);
        $this->assertSame('0.000045', $this->sent[0]['amount']);
        $this->assertLessThanOrEqual(6, self::decimals($this->sent[0]['amount']));
        $this->assertSame('221949050130', $this->sent[0]['price']);
        $this->log->shouldNotHaveReceived('warning', fn ($m) => $m === 'ORDER_PRICE_ROUNDED');
    }

    public function test_place_order_off_tick_buy_is_rounded_down_and_logged(): void
    {
        (new NobitexService)->placeOrder('BTCIRT', 'buy', 221949050136, '0.000046');

        $this->assertSame('221949050130', $this->sent[0]['price']);
        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx = []) => $m === 'ORDER_PRICE_ROUNDED' && $ctx['sent'] === '221949050130' && $ctx['side'] === 'buy')
            ->once();
    }

    public function test_place_order_off_tick_sell_is_rounded_up_and_logged(): void
    {
        (new NobitexService)->placeOrder('BTCIRT', 'sell', 225278285881, '0.000045');

        $this->assertSame('225278285890', $this->sent[0]['price']);
        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx = []) => $m === 'ORDER_PRICE_ROUNDED' && $ctx['sent'] === '225278285890' && $ctx['side'] === 'sell')
            ->once();
    }

    // ── CreateOrderDto::toApiPayload (grid path boundary) ────────────────

    public function test_dto_payload_fits_amount_and_rounds_off_tick_price_side_safely(): void
    {
        $buy = (new CreateOrderDto(OrderSide::BUY, ExecutionType::LIMIT, 'btc', 'irt', '0.00004504', 221949050136))->toApiPayload();
        $this->assertSame('0.000045', $buy['amount']);
        $this->assertSame('221949050130', $buy['price']);
        $this->assertSame('rls', $buy['dstCurrency']);

        $sell = (new CreateOrderDto(OrderSide::SELL, ExecutionType::LIMIT, 'btc', 'irt', '0.00004504', 221949050136))->toApiPayload();
        $this->assertSame('221949050140', $sell['price']);

        $this->log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'ORDER_PRICE_ROUNDED')->twice();
    }

    public function test_dto_on_tick_price_is_untouched_and_not_logged(): void
    {
        $p = (new CreateOrderDto(OrderSide::SELL, ExecutionType::LIMIT, 'btc', 'irt', '0.000045', 225278285890))->toApiPayload();
        $this->assertSame('225278285890', $p['price']);
        $this->log->shouldNotHaveReceived('warning', fn ($m) => $m === 'ORDER_PRICE_ROUNDED');
    }

    // ── Grid path: row == payload ────────────────────────────────────────

    public function test_grid_executor_row_equals_payload(): void
    {
        $diff = [
            'symbol' => 'BTCIRT', 'tick' => 1, 'min_order_value_irt' => 3_000_000,
            'to_place' => [
                // bot 48: 8-dp amount, off-tick prices
                ['side' => 'buy',  'price' => 221949050136, 'quantity' => '0.00004504', 'notional' => 10_000_000],
                ['side' => 'sell', 'price' => 228711000001, 'quantity' => '0.00004504', 'notional' => 10_300_000],
            ],
        ];

        (new GridOrderExecutor(new NobitexService, new OrderRegistry))->applyForBot(48, $diff, simulation: false, role: 'initial_grid');

        $this->assertCount(2, $this->sent);
        $rows = GridOrder::orderBy('id')->get();
        $this->assertCount(2, $rows);

        foreach ($rows as $i => $row) {
            $this->assertSame('placed', $row->status);
            $this->assertSame($this->sent[$i]['amount'], Money::trimZeros((string) $row->amount), 'row amount == sent amount');
            $this->assertSame($this->sent[$i]['price'], Money::trimZeros((string) $row->price), 'row price == sent price');
        }

        $this->assertSame('0.000045', $this->sent[0]['amount']);
        $this->assertSame('221949050130', $this->sent[0]['price']);   // buy → down
        $this->assertSame('228711000010', $this->sent[1]['price']);   // sell → up

        // aligned BEFORE the row was written → the boundary safety net never fired
        $this->log->shouldNotHaveReceived('warning', fn ($m) => $m === 'ORDER_PRICE_ROUNDED');
    }

    public function test_planner_fits_fixed_qty_and_ticks_from_market_precision(): void
    {
        $plan = app(GridPlanner::class)->plan('BTCIRT', lastPrice: 225328984910, levels: 2, stepPct: 1.5, mode: 'both', fixedQty: '0.00004504');

        $this->assertSame(10, $plan['tick']);
        $this->assertSame(6, $plan['qty_decimals']);
        foreach ($plan['items'] as $it) {
            $this->assertSame('0.000045', $it['quantity']);
            $this->assertSame(0, $it['price'] % 10);
        }
    }
}
