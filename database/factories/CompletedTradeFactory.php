<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompletedTrade;
use App\Models\GridOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 13 Step 5 — test factory for CompletedTrade.
 *
 * CompletedTrade DOES use HasFactory, so both CompletedTrade::factory() and
 * CompletedTradeFactory::new() work. The explicit $model property is kept for
 * symmetry with the sibling factories and to keep the direct form unambiguous.
 *
 * The model applies DECIMAL(20,0) string-normalising mutators to buy_price,
 * sell_price, profit and fee (setBuyPriceAttribute etc.), so integer inputs are
 * fine — they get stringified for a PARAM_STR binding. gross_profit / net_profit
 * are plain DECIMAL(20,8) columns with no mutator.
 *
 * Defaults describe a small winning grid round-trip at BTCIRT magnitude, so a
 * bare create() already produces a coherent, guard-satisfying row.
 *
 * @extends Factory<CompletedTrade>
 */
class CompletedTradeFactory extends Factory
{
    protected $model = CompletedTrade::class;

    public function definition(): array
    {
        // A modest winning round-trip: 0.001 BTC bought at 98e9, sold at 99e9.
        //   gross  = (99e9 - 98e9) * 0.001            = 1,000,000 IRT
        //   fee    = (98e9 + 99e9) * 0.001 * 0.0035    =   689,500 IRT
        //   net    = gross - fee                       =   310,500 IRT
        return [
            'bot_config_id'          => fn () => BotConfigFactory::new()->create()->id,
            'buy_order_id'           => null,
            'sell_order_id'          => null,

            'buy_price'              => 98_000_000_000,
            'sell_price'             => 99_000_000_000,
            'amount'                 => '0.00100000',

            'profit'                 => 310_500,   // == net_profit (mirrors createFromOrders)
            'fee'                    => 689_500,
            'gross_profit'           => 1_000_000,
            'net_profit'             => 310_500,
            'profit_percentage'      => 1.0204,

            'execution_time_seconds' => 120,
            'market_conditions'      => ['btc_price_at_trade' => 98_500_000_000, 'trend' => 'sideways'],
            'trade_type'             => 'grid',
            'grid_level_buy'         => null,
            'grid_level_sell'        => null,
            'slippage'               => null,
            'notes'                  => null,
        ];
    }

    // ── Outcome states ───────────────────────────────────────────────────────

    /**
     * A profitable trade: net_profit > 0.
     */
    public function winning(int|string $netProfit = 310_500): static
    {
        return $this->state(fn () => [
            'gross_profit' => 1_000_000,
            'net_profit'   => $netProfit,
            'profit'       => $netProfit,
            'fee'          => 689_500,
        ]);
    }

    /**
     * A losing trade: net_profit < 0. This is the state KillSwitchService's
     * max-drawdown branch consumes (it sums completed trades where
     * net_profit < 0). Pass a positive magnitude; it is stored negated.
     */
    public function losing(int|string $lossMagnitude = 500_000): static
    {
        $loss = '-' . ltrim((string) $lossMagnitude, '-');

        return $this->state(fn () => [
            'gross_profit' => $loss,
            'net_profit'   => $loss,
            'profit'       => $loss,
            'fee'          => 689_500,
        ]);
    }

    // ── Build from an order pair ─────────────────────────────────────────────

    /**
     * Convenience for booking a trade against a concrete buy/sell GridOrder
     * pair: links both order ids and copies their prices/amount. profit/fee and
     * the derived metrics keep whatever the current state provides (default =
     * the winning round-trip above), so callers can chain ->losing() etc.
     *
     * amount is booked on the matched quantity (min of the two legs), mirroring
     * CompletedTrade::createFromOrders.
     */
    public function fromOrders(GridOrder $buy, GridOrder $sell): static
    {
        $matched = bccomp((string) $buy->amount, (string) $sell->amount, 8) <= 0
            ? (string) $buy->amount
            : (string) $sell->amount;

        return $this->state(fn () => [
            'bot_config_id' => $buy->bot_config_id,
            'buy_order_id'  => $buy->id,
            'sell_order_id' => $sell->id,
            'buy_price'     => (string) $buy->price,
            'sell_price'    => (string) $sell->price,
            'amount'        => $matched,
        ]);
    }
}
