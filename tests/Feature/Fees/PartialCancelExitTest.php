<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\NobitexService;
use App\Support\Money;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 8 / audit D12 — a cancelled order's partial execution gets
 * an exit through the normal fee-aware sizing when that exit is >= the
 * minimum order value; a smaller one is absorbed into base_dust (never a
 * below-minimum order). Behind trading.fees.exit_for_partial_cancels.
 */
final class PartialCancelExitTest extends TestCase
{
    use BuildsGridSchema;

    private const P = '216000000000';

    /** @var array<int,array{side:string,amount:string}> */
    private array $placed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->placed = [];
        config([
            'trading.fees.buy_fee_bps' => '25', 'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency' => 'base', 'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.exit_for_partial_cancels' => true,
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

    private function bot(): BotConfig
    {
        return BotConfig::create(['name' => 'd12', 'symbol' => 'BTCIRT', 'simulation' => false, 'is_active' => true, 'grid_spacing' => 1.5]);
    }

    private function buy(BotConfig $bot, string $nid = '1'): GridOrder
    {
        return GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'placed', 'price' => self::P,
            'amount' => '0.001', 'original_amount' => '0.001', 'role' => 'grid',
            'client_order_id' => "seed-{$nid}", 'nobitex_order_id' => $nid,
        ]);
    }

    /** @param array<string,array> $rows */
    private function mockNobitex(array &$rows): void
    {
        $svc = Mockery::mock(NobitexService::class);
        // A by-reference closure: statuses added to $rows later are seen.
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(function (array $ids) use (&$rows) {
            $out = [];
            foreach ($ids as $id) {
                if (isset($rows[$id])) {
                    $out[] = OrderStatusDto::fromApi($rows[$id]);
                }
            }
            return $out;
        });
        $svc->shouldReceive('placeOrder')->andReturnUsing(function ($sym, $side, $price, $amount) {
            $this->placed[] = ['side' => $side, 'amount' => $amount];
            return ['status' => 'ok', 'order' => ['id' => 5000 + count($this->placed)]];
        });
        $this->app->instance(NobitexService::class, $svc);
    }

    private static function canceled(string $id, string $matched, string $fee, string $type = 'buy'): array
    {
        return ['id' => $id, 'type' => $type, 'execution' => 'Limit', 'status' => 'Canceled', 'price' => self::P,
            'amount' => '0.001', 'matchedAmount' => $matched, 'fee' => $fee, 'averagePrice' => self::P];
    }

    public function test_partial_above_minimum_gets_a_fee_net_exit_and_books_when_it_fills(): void
    {
        $bot = $this->bot();
        $buy = $this->buy($bot);
        // 0.0004 BTC executed (≈ 86.4M IRT), fee 0.000001 BTC, then cancelled.
        $rows = ['1' => self::canceled('1', '0.0004', '0.000001')];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($buy, $bot);

        $buy->refresh();
        $this->assertSame('cancelled', $buy->status);
        $this->assertCount(1, $this->placed);
        $this->assertSame('sell', $this->placed[0]['side']);
        $this->assertSame('0.000399', $this->placed[0]['amount']); // 0.0004 − 0.000001

        // The exit fills → the cycle is booked against the cancelled partial.
        $exit = GridOrder::findOrFail($buy->paired_order_id);
        $exit->update(['nobitex_order_id' => '2']);
        $rows['2'] = ['id' => '2', 'type' => 'sell', 'execution' => 'Limit', 'status' => 'Done',
            'price' => (string) $exit->price, 'amount' => '0.000399', 'matchedAmount' => '0.000399',
            'fee' => Money::mul(Money::mul('0.000399', (string) $exit->price), '0.0025'), 'averagePrice' => (string) $exit->price];
        (new CheckTradesJob())->processSingleOrder($exit, $bot);

        $this->assertSame(1, CompletedTrade::count());
        $this->assertSame('0.00039900', (string) CompletedTrade::first()->amount);
    }

    public function test_partial_below_minimum_is_absorbed_into_dust_without_an_order(): void
    {
        $bot = $this->bot();
        $buy = $this->buy($bot);
        // 0.00001 BTC (≈ 2.16M IRT) — its exit would be < 3M IRT.
        $rows = ['1' => self::canceled('1', '0.00001', '0.000000025')];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($buy, $bot);

        $this->assertSame([], $this->placed, 'no below-minimum / dust-only order');
        $buy->refresh();
        $this->assertSame('dusted', $buy->exit_state);
        $this->assertNull($buy->paired_order_id);
        // credited 0.00001 − 0.000000025 = 0.000009975 → base_dust
        $this->assertSame('0.000009975', Money::trimZeros((string) BotConfig::find($bot->id)->base_dust));

        // Never re-selected.
        (new CheckTradesJob())->handle();
        $this->assertSame([], $this->placed);
        $this->assertSame('0.000009975', Money::trimZeros((string) BotConfig::find($bot->id)->base_dust));
    }

    public function test_disabled_by_config_restores_the_old_behaviour(): void
    {
        config(['trading.fees.exit_for_partial_cancels' => false]);
        $bot = $this->bot();
        $buy = $this->buy($bot);
        $rows = ['1' => self::canceled('1', '0.0004', '0.000001')];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->processSingleOrder($buy, $bot);
        (new CheckTradesJob())->handle();

        $this->assertSame([], $this->placed);
        $this->assertNull($buy->fresh()->paired_order_id);
    }

    public function test_historic_cancelled_partials_are_not_paired_by_surprise(): void
    {
        $bot = $this->bot();
        // A row cancelled-with-partial BEFORE this deploy: no fee_source.
        GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'cancelled', 'price' => self::P,
            'amount' => '0.001', 'filled_amount' => '0.0004', 'client_order_id' => 'old', 'nobitex_order_id' => '9',
        ]);
        $rows = [];
        $this->mockNobitex($rows);

        (new CheckTradesJob())->handle();

        $this->assertSame([], $this->placed);
    }
}
