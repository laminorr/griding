<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\ExitSizer;
use App\Services\FeeModel;
use App\Services\NobitexService;
use App\Support\Money;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Bot 48 (BTCIRT, live) at the REAL market precision: qty step 6 dp, tick 10.
 * See docs/market-precision.md.
 *
 *   sell 0.000045 filled @ 225328984910, fee 25349.510802375 IRT (quote)
 *   grid_spacing 1.50
 *   exit buy price  = floor_tick(225328984910 × 0.985 = 221949050136.35) = 221949050130
 *   restore amount  = ceil6(0.000045 / 0.9975 = 0.0000451127…)        = 0.000046
 *   exit buy fills: fee 0.000046 × 0.0025 = 0.000000115 BTC
 *   credited 0.000045885 − sold 0.000045 → base_dust +0.000000885
 *
 * Before the fix: price …136 (half-up to the rial, off-tick; Nobitex stored
 * …140) and amount 0.00004512 (8 dp; Nobitex truncated to 0.000045, so the
 * restore was SHORT of the 0.000045 target).
 */
final class MarketPrecisionExitTest extends TestCase
{
    use BuildsGridSchema;

    private const SELL_PRICE = '225328984910';
    private const SELL_FEE   = '25349.510802375';

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
            'trading.exchange.precision_live' => false,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 6,
            'trading.ticks.BTCIRT' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function bot(string $dust = '0'): BotConfig
    {
        $bot = BotConfig::create([
            'name' => 'bot48-like', 'symbol' => 'BTCIRT', 'simulation' => false,
            'is_active' => true, 'grid_spacing' => 1.50,
        ]);
        BotConfig::whereKey($bot->id)->update(['base_dust' => $dust]);
        return $bot->fresh();
    }

    /** @param array<string,array> $rows */
    private function mockNobitex(array &$rows): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(function (array $ids) use (&$rows) {
            return array_values(array_map(fn ($id) => OrderStatusDto::fromApi($rows[$id]), array_filter($ids, fn ($id) => isset($rows[$id]))));
        });
        // Records what reaches the exchange boundary (after placeOrder's own
        // fitting would run — here the args ARE the payload values).
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

    public function test_exit_sizer_restore_buy_at_six_decimals_is_never_short(): void
    {
        $s = (new ExitSizer(new FeeModel()))->compute(
            null, 'BTCIRT', 'sell', '0.000045', self::SELL_FEE, 'quote', self::SELL_PRICE, '221949050130', '0',
        );

        $this->assertSame('buy', $s['side']);
        $this->assertSame('restore_buy', $s['mode']);
        $this->assertSame('0.000046', $s['amount']);
        // net BTC after the 0.25% BTC buy fee covers the sold 0.000045
        $net = Money::sub($s['amount'], Money::mul($s['amount'], '0.0025'));
        $this->assertGreaterThanOrEqual(0, Money::compare($net, '0.000045'));
        // the old 8-dp size (0.00004512) truncated by the exchange would be short
        $this->assertLessThan(0, Money::compare(Money::sub('0.000045', Money::mul('0.000045', '0.0025')), '0.000045'));
    }

    public function test_exit_sizer_buy_first_sell_floors_to_six_decimals_and_books_dust(): void
    {
        // buy 0.000046 filled, fee 0.000000115 BTC → credited 0.000045885
        $s = (new ExitSizer(new FeeModel()))->compute(
            null, 'BTCIRT', 'buy', '0.000046', '0.000000115', 'base', '221949050130', '225278286000', '0',
        );

        $this->assertSame('sell', $s['side']);
        $this->assertSame('0.000045', $s['amount']);
        $this->assertSame('0.000000885', $s['dust_after']);
    }

    public function test_bot48_real_case_exit_buy_is_tick_aligned_restoring_and_row_equals_payload(): void
    {
        $bot  = $this->bot();
        $sell = GridOrder::create([
            'bot_config_id' => $bot->id, 'price' => self::SELL_PRICE, 'amount' => '0.000045',
            'original_amount' => '0.000045', 'type' => 'sell', 'status' => 'placed',
            'client_order_id' => 'seed-279p', 'nobitex_order_id' => '5566181467',
        ]);
        $rows = ['5566181467' => [
            'id' => 5566181467, 'type' => 'sell', 'execution' => 'Limit', 'status' => 'Done',
            'price' => self::SELL_PRICE, 'averagePrice' => self::SELL_PRICE,
            'amount' => '0.000045', 'matchedAmount' => '0.000045',
            'totalPrice' => '10139804.32095', 'fee' => self::SELL_FEE,
        ]];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($sell, $bot);

        $this->assertCount(1, $this->placed);
        $this->assertSame('buy', $this->placed[0]['side']);
        $this->assertSame(221949050130, $this->placed[0]['price']);   // floor to tick, not …136
        $this->assertSame('0.000046', $this->placed[0]['amount']);    // ceil6, not 0.00004512

        // Row == payload
        $exitBuy = GridOrder::find($sell->fresh()->paired_order_id);
        $this->assertSame('221949050130', self::d($exitBuy->price));
        $this->assertSame('0.000046', self::d($exitBuy->amount));

        // Realised spread is never below grid_spacing (buy rounded DOWN)
        $this->assertLessThanOrEqual(0, Money::compare(
            (string) $this->placed[0]['price'],
            Money::mul(self::SELL_PRICE, '0.985')
        ));

        // The exit buy fills: fee 0.000046 × 0.0025 = 0.000000115 BTC.
        $exitBuy->update(['nobitex_order_id' => '7001']);
        $rows['7001'] = [
            'id' => 7001, 'type' => 'buy', 'execution' => 'Limit', 'status' => 'Done',
            'price' => '221949050130', 'averagePrice' => '221949050130',
            'amount' => '0.000046', 'matchedAmount' => '0.000046',
            'totalPrice' => '10209656.30598', 'fee' => '0.000000115',
        ];
        (new CheckTradesJob())->processSingleOrder($exitBuy, $bot);

        // credited 0.000045885 − sold 0.000045 = +0.000000885 → ledger
        $this->assertSame('0.000000885', self::d(BotConfig::find($bot->id)->base_dust));
        $this->assertSame('0.000000885', self::d($exitBuy->fresh()->exit_dust_delta));
    }

    public function test_exit_sell_is_rounded_up_to_the_tick(): void
    {
        $bot = $this->bot();
        // 221949050130 × 1.015 = 225278285881.95 → ceil to tick 225278285890
        $buy = GridOrder::create([
            'bot_config_id' => $bot->id, 'price' => '221949050130', 'amount' => '0.000046',
            'original_amount' => '0.000046', 'type' => 'buy', 'status' => 'placed',
            'client_order_id' => 'seed-buy', 'nobitex_order_id' => '5566181468',
        ]);
        $rows = ['5566181468' => [
            'id' => 5566181468, 'type' => 'buy', 'execution' => 'Limit', 'status' => 'Done',
            'price' => '221949050130', 'averagePrice' => '221949050130',
            'amount' => '0.000046', 'matchedAmount' => '0.000046',
            'totalPrice' => '10209656.30598', 'fee' => '0.000000115',
        ]];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($buy, $bot);

        $this->assertSame('sell', $this->placed[0]['side']);
        $this->assertSame(225278285890, $this->placed[0]['price']);
        $this->assertSame('0.000045', $this->placed[0]['amount']);

        $exit = GridOrder::find($buy->fresh()->paired_order_id);
        $this->assertSame('225278285890', self::d($exit->price));
        $this->assertSame('0.000045', self::d($exit->amount));
        $this->assertSame('0.000000885', self::d(BotConfig::find($bot->id)->base_dust));

        // spread ≥ grid_spacing (sell rounded UP)
        $this->assertGreaterThanOrEqual(0, Money::compare('225278285890', Money::mul('221949050130', '1.015')));
    }
}
