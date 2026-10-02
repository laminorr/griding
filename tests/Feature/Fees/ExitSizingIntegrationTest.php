<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\MarketDataLayer;
use App\Services\NobitexService;
use App\Services\SubmissionReconciler;
use App\Support\Money;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 4 — the exit sizing is applied on every pairing path
 * (W4 single-order, minute poller, simulation) and the dust ledger is kept
 * consistent (reserve on pairing, settle on a sell-first close, revert on a
 * cancelled-and-unlinked intent).
 */
final class ExitSizingIntegrationTest extends TestCase
{
    use BuildsGridSchema;

    private const PRICE = '111939999980';

    /** @var array<int,array{side:string,price:int,amount:string}> */
    private array $placed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->placed = [];
        config([
            'trading.fees.buy_fee_bps'  => '25',
            'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency'  => 'base',
            'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.fee_scale' => 10,
            'trading.fees.restore_inventory_on_buy_exit' => true,
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

    private function bot(bool $simulation = false, string $dust = '0'): BotConfig
    {
        $bot = BotConfig::create([
            'name' => 'exit-sizing', 'symbol' => 'BTCIRT', 'simulation' => $simulation,
            'is_active' => true, 'grid_spacing' => 1.50,
        ]);
        BotConfig::whereKey($bot->id)->update(['base_dust' => $dust]);
        return $bot->fresh();
    }

    private function order(BotConfig $bot, array $over = []): GridOrder
    {
        return GridOrder::create(array_merge([
            'bot_config_id' => $bot->id, 'price' => self::PRICE, 'amount' => '0.0003',
            'original_amount' => '0.0003', 'type' => 'buy', 'status' => 'placed',
            'client_order_id' => 'seed-' . uniqid(), 'nobitex_order_id' => '5566181467',
        ], $over));
    }

    private static function row(array $over = []): array
    {
        return array_merge([
            'type' => 'buy', 'execution' => 'Limit', 'price' => self::PRICE, 'amount' => '0.0003',
            'matchedAmount' => '0.0003', 'id' => 5566181467, 'status' => 'Done',
            'fee' => '0.00000075', 'averagePrice' => self::PRICE, 'totalPrice' => '33581999.994',
        ], $over);
    }

    /** @param array<string,array> $rows */
    private function mockNobitex(array &$rows): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(function (array $ids) use (&$rows) {
            return array_values(array_map(fn ($id) => OrderStatusDto::fromApi($rows[$id]), array_filter($ids, fn ($id) => isset($rows[$id]))));
        });
        $svc->shouldReceive('placeOrder')->andReturnUsing(function ($symbol, $side, $price, $amount) {
            $this->placed[] = ['side' => $side, 'price' => $price, 'amount' => $amount];
            return ['status' => 'ok', 'order' => ['id' => 990000 + count($this->placed)]];
        });
        $this->app->instance(NobitexService::class, $svc);
    }

    private static function d($v): string
    {
        return Money::trimZeros((string) $v);
    }

    // ── live buy-first ─────────────────────────────────────────────────────

    public function test_w4_buy_fill_places_the_fee_net_exit_sell(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        $rows = ['5566181467' => self::row()];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        // V1/V4: 0.0003 − 0.00000075 = 0.00029925 (not the gross 0.0003)
        $this->assertCount(1, $this->placed);
        $this->assertSame('sell', $this->placed[0]['side']);
        $this->assertSame('0.00029925', $this->placed[0]['amount']);
        $this->assertSame(113619099980, $this->placed[0]['price']); // 111,939,999,980 × 1.015, half-up

        $exit = GridOrder::find($order->fresh()->paired_order_id);
        $this->assertSame('0.00029925', self::d($exit->amount));
        $this->assertSame('0', self::d(BotConfig::find($bot->id)->base_dust));
    }

    public function test_poller_path_sizes_identically_and_books_the_remainder_as_dust(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot, ['amount' => '0.000223', 'original_amount' => '0.000223']);
        $rows = ['5566181467' => self::row([
            'amount' => '0.000223', 'matchedAmount' => '0.000223', 'fee' => '0.0000005575', 'totalPrice' => '24962619.99554',
        ])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->handle();

        $this->assertSame('0.00022244', $this->placed[0]['amount']);
        $this->assertSame('0.0000000025', self::d(BotConfig::find($bot->id)->base_dust));
        $exit = GridOrder::find($order->fresh()->paired_order_id);
        $this->assertSame('0.0000000025', self::d($exit->exit_dust_delta));
    }

    public function test_accumulated_dust_is_folded_into_the_next_exit_sell(): void
    {
        $bot = $this->bot(dust: '0.0000000076');
        $order = $this->order($bot, ['amount' => '0.000057', 'original_amount' => '0.000057']);
        $rows = ['5566181467' => self::row([
            'amount' => '0.000057', 'matchedAmount' => '0.000057', 'fee' => '0.0000001425', 'totalPrice' => '6380579.99886',
        ])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        // credited 0.0000568575 + dust 0.0000000076 → 0.00005686, dust 0.0000000051
        $this->assertSame('0.00005686', $this->placed[0]['amount']);
        $this->assertSame('0.0000000051', self::d(BotConfig::find($bot->id)->base_dust));
    }

    // ── live sell-first ────────────────────────────────────────────────────

    public function test_sell_first_exit_buy_restores_inventory_and_settles_dust_on_fill(): void
    {
        $bot  = $this->bot();
        $sell = $this->order($bot, ['type' => 'sell', 'amount' => '0.00578704', 'original_amount' => '0.00578704', 'price' => '216000000000']);
        $rows = ['5566181467' => self::row([
            'type' => 'sell', 'price' => '216000000000', 'averagePrice' => '216000000000',
            'amount' => '0.00578704', 'matchedAmount' => '0.00578704',
            'totalPrice' => '1250000640', 'fee' => '3125001.6', // 0.25% in rial
        ])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($sell, $bot);

        // audit C2: ceil8(0.00578704 / 0.9975) = 0.00580155 at 216e9 × 0.985
        $this->assertSame('buy', $this->placed[0]['side']);
        $this->assertSame('0.00580155', $this->placed[0]['amount']);
        $this->assertSame(212760000000, $this->placed[0]['price']);

        // The exit buy fills: fee 0.00580155 × 0.0025 = 0.000014503875 BTC.
        $exitBuy = GridOrder::find($sell->fresh()->paired_order_id);
        $exitBuy->update(['nobitex_order_id' => '7001']);
        $rows['7001'] = self::row([
            'id' => 7001, 'type' => 'buy', 'price' => '212760000000', 'averagePrice' => '212760000000',
            'amount' => '0.00580155', 'matchedAmount' => '0.00580155',
            'totalPrice' => '1234337778', 'fee' => '0.000014503875',
        ]);
        (new CheckTradesJob())->processSingleOrder($exitBuy, $bot);

        // credited 0.005787046125 − sold 0.00578704 = +0.000000006125 (audit C2)
        $this->assertSame('0.000000006125', self::d(BotConfig::find($bot->id)->base_dust));
        $this->assertSame('0.000000006125', self::d($exitBuy->fresh()->exit_dust_delta));
        $this->assertSame(1, CompletedTrade::count());

        // Idempotent: settling again changes nothing.
        app(\App\Services\ExitSizer::class)->settleExitBuyFill($exitBuy->fresh(), BotConfig::find($bot->id));
        $this->assertSame('0.000000006125', self::d(BotConfig::find($bot->id)->base_dust));
    }

    // ── revert on cancel + unlink ──────────────────────────────────────────

    public function test_reconciler_cancel_and_unlink_reverts_the_dust_reservation_once(): void
    {
        $bot  = $this->bot(dust: '0.0000000025');
        $fill = $this->order($bot, ['status' => 'filled', 'nobitex_order_id' => '1']);
        $exit = GridOrder::create([
            'bot_config_id' => $bot->id, 'price' => '113619099980', 'amount' => '0.00022244', 'type' => 'sell',
            'status' => 'submission_unknown', 'client_order_id' => 'x', 'paired_order_id' => $fill->id,
            'role' => 'cycle_exit', 'exit_dust_delta' => '0.0000000025',
        ]);
        $fill->update(['paired_order_id' => $exit->id]);

        $rec = new SubmissionReconciler(Mockery::mock(NobitexService::class));
        $m   = new ReflectionMethod($rec, 'cancelRowAndUnlink');
        $m->invoke($rec, $exit, $fill->id);

        $this->assertNull($fill->fresh()->paired_order_id);
        $this->assertSame('0', self::d(BotConfig::find($bot->id)->base_dust));
        $this->assertSame('0', self::d($exit->fresh()->exit_dust_delta));

        app(\App\Services\ExitSizer::class)->revertDust($exit->fresh()); // no double revert
        $this->assertSame('0', self::d(BotConfig::find($bot->id)->base_dust));
    }

    // ── simulation ─────────────────────────────────────────────────────────

    public function test_simulation_uses_the_same_exit_sizing(): void
    {
        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->andReturn(111_000_000_000);
        $this->app->instance(MarketDataLayer::class, $md);

        $bot = $this->bot(simulation: true);
        $order = $this->order($bot, ['nobitex_order_id' => 'SIM-1']);

        (new CheckTradesJob())->handle();

        $exit = GridOrder::find($order->fresh()->paired_order_id);
        $this->assertSame('sell', $exit->type);
        $this->assertSame('placed', $exit->status);
        $this->assertSame('0.00029925', self::d($exit->amount)); // estimated fee, same function
    }
}
