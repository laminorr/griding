<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\MarketDataLayer;
use App\Services\NobitexService;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 3 — every fill records its fee (actual when the exchange
 * reports one, else a FeeModel estimate), the exact average fill price, and
 * the net base change. Covers both live paths (minute poller via handle(),
 * W4 via processSingleOrder()), partial fills, canceled-with-partial, and
 * simulation fills.
 *
 * Fixture numbers are the VERIFIED host order (V1/V2): buy 0.0003 BTC at
 * 111,939,999,980, totalPrice 33,581,999.994, fee "0.00000075".
 */
final class FeeCaptureTest extends TestCase
{
    use BuildsGridSchema;

    private const PRICE = '111939999980';

    private int $placeCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->placeCalls = 0;
        config([
            'trading.fees.buy_fee_bps'  => '25',
            'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency'  => 'base',
            'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.fee_scale'    => 10,
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

    private function bot(bool $simulation = false): BotConfig
    {
        return BotConfig::create([
            'name' => 'fee-capture', 'symbol' => 'BTCIRT', 'simulation' => $simulation,
            'is_active' => true, 'grid_spacing' => 1.50,
        ]);
    }

    private function order(BotConfig $bot, array $over = []): GridOrder
    {
        return GridOrder::create(array_merge([
            'bot_config_id' => $bot->id, 'price' => self::PRICE, 'amount' => '0.0003',
            'original_amount' => '0.0003', 'type' => 'buy', 'status' => 'placed',
            'client_order_id' => 'seed-fee-buy', 'nobitex_order_id' => '5566181467',
        ], $over));
    }

    /** The V2 order-status row, overridable. */
    private static function row(array $over = []): array
    {
        return array_merge([
            'type' => 'buy', 'execution' => 'Limit', 'tradeType' => 'Spot',
            'srcCurrency' => 'btc', 'dstCurrency' => 'rls',
            'price' => self::PRICE, 'amount' => '0.0003',
            'totalPrice' => '33581999.994', 'totalOrderPrice' => '33581999.994',
            'matchedAmount' => '0.0003', 'unmatchedAmount' => '0',
            'clientOrderId' => null, 'id' => 5566181467, 'status' => 'Done',
            'partial' => false, 'fee' => '0.00000075', 'user' => 'x',
            'created_at' => '2026-10-01T10:00:00+00:00', 'market' => 'BTC-RLS',
            'averagePrice' => self::PRICE,
        ], $over);
    }

    /** @param array<string,array> $rows nobitex id => raw row */
    private function mockNobitex(array &$rows): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(function (array $ids) use (&$rows) {
            return array_values(array_map(fn ($id) => OrderStatusDto::fromApi($rows[$id]), array_filter($ids, fn ($id) => isset($rows[$id]))));
        });
        $svc->shouldReceive('placeOrder')->andReturnUsing(function () {
            $this->placeCalls++;
            return ['status' => 'ok', 'order' => ['id' => 900000 + $this->placeCalls]];
        });
        $this->app->instance(NobitexService::class, $svc);
    }

    private static function d(?string $v): ?string
    {
        return $v === null ? null : \App\Support\Money::trimZeros($v);
    }

    // ── OrderStatusDto ─────────────────────────────────────────────────────

    public function test_dto_reads_fee_average_price_and_total_from_the_v2_payload(): void
    {
        $dto = OrderStatusDto::fromApi(self::row());
        $this->assertSame('0.00000075', $dto->fee);
        $this->assertSame(self::PRICE, $dto->averagePrice);
        $this->assertSame('33581999.994', $dto->totalPrice);
        $this->assertSame('0.0003', $dto->filledBase);
    }

    public function test_dto_tolerates_absent_or_malformed_fee_fields(): void
    {
        $row = self::row();
        unset($row['fee'], $row['averagePrice'], $row['totalPrice']);
        $dto = OrderStatusDto::fromApi($row);
        $this->assertNull($dto->fee);
        $this->assertNull($dto->averagePrice);
        $this->assertNull($dto->totalPrice);

        $dto = OrderStatusDto::fromApi(self::row(['fee' => 'n/a', 'averagePrice' => '-5', 'totalPrice' => '1e5']));
        $this->assertNull($dto->fee);
        $this->assertNull($dto->averagePrice);
        $this->assertNull($dto->totalPrice);
    }

    // ── live fills: W4 single-order path ───────────────────────────────────

    public function test_w4_filled_buy_persists_the_actual_base_fee(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        $rows = ['5566181467' => self::row()];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('filled', $o->status);
        $this->assertSame('0.00000075', self::d($o->fee_amount));
        $this->assertSame('base', $o->fee_currency);
        $this->assertSame('btc', $o->fee_asset);
        $this->assertSame('actual', $o->fee_source);
        // 0.00000075 × 111,939,999,980 = 83,954.999985
        $this->assertSame('83954.999985', self::d($o->fee_quote));
        $this->assertSame(self::PRICE, self::d($o->avg_fill_price));
        $this->assertSame(self::PRICE, (string) $o->average_fill_price);
        // +0.0003 − 0.00000075 = 0.00029925 BTC credited
        $this->assertSame('0.00029925', self::d($o->net_base_delta));
    }

    // ── live fills: minute poller path (same handlers) ─────────────────────

    public function test_poller_filled_buy_persists_identical_fields(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        $rows = ['5566181467' => self::row()];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->handle();

        $o = $order->fresh();
        $this->assertSame('filled', $o->status);
        $this->assertSame('0.00000075', self::d($o->fee_amount));
        $this->assertSame('base', $o->fee_currency);
        $this->assertSame('actual', $o->fee_source);
        $this->assertSame('0.00029925', self::d($o->net_base_delta));
    }

    public function test_price_improvement_records_the_exact_average_fill_price(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        // Crossing limit order filled below its limit, at a fractional average.
        $rows = ['5566181467' => self::row(['averagePrice' => '111800000000.5', 'totalPrice' => '33540000000.15'])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('111800000000.5', self::d($o->avg_fill_price));
        $this->assertSame('111800000001', (string) $o->average_fill_price); // whole rial, half-up
        $this->assertSame(self::PRICE, (string) $o->price);                 // limit price untouched
    }

    public function test_missing_fee_falls_back_to_a_feemodel_estimate(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        $row = self::row();
        unset($row['fee'], $row['averagePrice'], $row['totalPrice']);
        $rows = ['5566181467' => $row];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('estimated', $o->fee_source);
        $this->assertSame('0.00000075', self::d($o->fee_amount)); // 0.0003 × 0.0025
        $this->assertSame('base', $o->fee_currency);
        $this->assertSame(self::PRICE, self::d($o->avg_fill_price)); // limit price fallback
    }

    public function test_sell_with_a_rial_fee_is_classified_quote(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot, ['type' => 'sell', 'client_order_id' => 'seed-fee-sell']);
        // 0.25% of 33,581,999.994 = 83,954.999985 rial
        $rows = ['5566181467' => self::row(['type' => 'sell', 'fee' => '83954.999985'])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('quote', $o->fee_currency);
        $this->assertSame('rls', $o->fee_asset);
        $this->assertSame('actual', $o->fee_source);
        $this->assertSame('83954.999985', self::d($o->fee_quote));
        $this->assertSame('-0.0003', self::d($o->net_base_delta)); // sell: −filled, no base fee
    }

    public function test_sell_charged_in_base_reduces_net_base_delta_by_the_fee(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot, ['type' => 'sell', 'client_order_id' => 'seed-fee-sell2']);
        $rows = ['5566181467' => self::row(['type' => 'sell', 'fee' => '0.00000075'])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('base', $o->fee_currency); // detected, despite the 'quote' expectation
        $this->assertSame('-0.00030075', self::d($o->net_base_delta));
    }

    // ── partials ───────────────────────────────────────────────────────────

    public function test_partial_fill_records_the_cumulative_fee_so_far(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        // First of the three V1 trades: 0.000223 matched, fee 0.0000005575.
        $rows = ['5566181467' => self::row([
            'status' => 'Active', 'matchedAmount' => '0.000223', 'unmatchedAmount' => '0.000077',
            'fee' => '0.0000005575', 'totalPrice' => '24962619.99554',
        ])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('partially_filled', $o->status);
        $this->assertSame('0.0000005575', self::d($o->fee_amount));
        $this->assertSame('actual', $o->fee_source);
        $this->assertSame('0.0002224425', self::d($o->net_base_delta));

        // Then Done with the full cumulative fee — the row is updated, not summed twice.
        $rows['5566181467'] = self::row();
        (new CheckTradesJob())->processSingleOrder($o, $bot);
        $o = $order->fresh();
        $this->assertSame('filled', $o->status);
        $this->assertSame('0.00000075', self::d($o->fee_amount));
        $this->assertSame('0.00029925', self::d($o->net_base_delta));
    }

    public function test_canceled_with_partial_records_the_executed_parts_fee(): void
    {
        $bot = $this->bot();
        $order = $this->order($bot);
        $rows = ['5566181467' => self::row([
            'status' => 'Canceled', 'matchedAmount' => '0.000057', 'fee' => '0.0000001425', 'totalPrice' => null,
        ])];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($order, $bot);

        $o = $order->fresh();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('0.000057', self::d($o->filled_amount));
        $this->assertSame('0.0000001425', self::d($o->fee_amount));
        $this->assertSame('actual', $o->fee_source);
        $this->assertSame('0.0000568575', self::d($o->net_base_delta));
    }

    // ── simulation ─────────────────────────────────────────────────────────

    public function test_simulated_fill_records_estimated_fee_fields(): void
    {
        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->andReturn(111_000_000_000); // below the buy → fills
        $this->app->instance(MarketDataLayer::class, $md);

        $bot = $this->bot(simulation: true);
        $order = $this->order($bot, ['nobitex_order_id' => 'SIM-1']);

        (new CheckTradesJob())->handle();

        $o = $order->fresh();
        $this->assertSame('filled', $o->status);
        $this->assertSame('0.0003', self::d($o->filled_amount));
        $this->assertSame('estimated', $o->fee_source);
        $this->assertSame('0.00000075', self::d($o->fee_amount));
        $this->assertSame('base', $o->fee_currency);
        $this->assertSame(self::PRICE, self::d($o->avg_fill_price));
        $this->assertSame('0.00029925', self::d($o->net_base_delta));
    }
}
