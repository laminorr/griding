<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * "How much is my money worth now?" for one bot — the «پول من» card.
 *
 *   realized   = SUM(completed_trades.net_profit)            (closed cycles)
 *   unrealized = open cycle exits valued at the current price (paper P&L)
 *   net        = realized + unrealized
 *   value_now  = total_capital + net
 *
 * The open-cycle math is the same as bot:audit §7 (BotAuditor::position()):
 *   • open exit SELL (long cycle): BTC held = exit remaining; cost basis =
 *     parent buy notional pro-rated to that BTC; unrealized = value now −
 *     cost − estimated sell fee on the value.
 *   • open exit BUY (short cycle): proceeds of the parent sell after its fee
 *     vs the cost of buying the inventory back now (buy fee grossed up).
 * Grid orders that are not exits are not cycles and carry no P&L.
 *
 * All amounts are rial strings. When no current price is available the
 * unrealized / net / value_now fields are null — never a fake 0.
 */
final class BotPnlSummary
{
    private const OPEN_STATUSES = ['placed', 'partially_filled', 'pending', 'active'];

    public function __construct(private readonly FeeModel $fees)
    {
    }

    /**
     * @return array{capital:string, realized:string, unrealized:?string, net:?string,
     *               value_now:?string, net_percent:?string, price_now:?string, open_cycles:int}
     */
    public function summarize(BotConfig $bot, ?string $priceNow): array
    {
        $capital = Money::normalize($bot->total_capital ?? '0');
        $realized = Money::normalize(
            DB::table('completed_trades')->where('bot_config_id', $bot->id)
                ->sum(DB::raw('COALESCE(net_profit, profit)')) ?? '0'
        );

        $price = ($priceNow !== null && Money::isPositive(Money::normalize($priceNow)))
            ? Money::normalize($priceNow) : null;

        $sellRate = $this->fees->rateFraction($bot, 'sell');
        $buyRate = $this->fees->rateFraction($bot, 'buy');

        $exits = GridOrder::where('bot_config_id', $bot->id)
            ->where('role', 'cycle_exit')
            ->whereNotNull('paired_order_id')
            ->whereIn('status', self::OPEN_STATUSES)
            ->get();

        $unrealized = '0';
        $open = 0;
        foreach ($exits as $exit) {
            $parent = GridOrder::find($exit->paired_order_id);
            if ($parent === null) {
                continue;
            }
            $open++;
            if ($price === null) {
                continue;
            }

            $pq = $this->legQty($parent);
            $pp = $this->fillPrice($parent);

            if ($exit->type === 'sell') {
                $rem = Money::sub(Money::normalize($exit->amount), Money::normalize($exit->filled_amount ?? '0'));
                $fee = $parent->fee_currency === 'base'
                    ? Money::normalize($parent->fee_amount ?? '0')
                    : Money::mul($pq, $buyRate);
                $credited = Money::sub($pq, $fee);
                $cost = Money::isPositive($credited)
                    ? Money::div(Money::mul(Money::mul($pq, $pp), $rem), $credited)
                    : '0';
                $value = Money::mul($rem, $price);
                $unrealized = Money::add($unrealized,
                    Money::sub(Money::sub($value, $cost), Money::mul($value, $sellRate)));
            } else {
                $sFee = $parent->fee_currency === 'quote'
                    ? Money::normalize($parent->fee_amount ?? '0')
                    : Money::mul(Money::mul($pq, $pp), $sellRate);
                $proceeds = Money::sub(Money::mul($pq, $pp), $sFee);
                $buyBack = Money::mul(Money::div($pq, Money::sub('1', $buyRate)), $price);
                $unrealized = Money::add($unrealized, Money::sub($proceeds, $buyBack));
            }
        }

        $net = $price === null ? null : Money::add($realized, $unrealized);

        return [
            'capital' => $capital,
            'realized' => $realized,
            'unrealized' => $price === null ? null : $unrealized,
            'net' => $net,
            'value_now' => $net === null ? null : Money::add($capital, $net),
            'net_percent' => ($net !== null && Money::isPositive($capital))
                ? Money::div(Money::mul($net, '100'), $capital, 4)
                : null,
            'price_now' => $price,
            'open_cycles' => $open,
        ];
    }

    private function legQty(GridOrder $o): string
    {
        $f = Money::normalize($o->filled_amount ?? '0');

        return Money::isPositive($f) ? $f : Money::normalize($o->amount);
    }

    /** avg_fill_price → average_fill_price → limit price (same order as bot:audit). */
    private function fillPrice(GridOrder $o): string
    {
        foreach (['avg_fill_price', 'average_fill_price'] as $k) {
            $v = Money::normalize($o->getAttribute($k) ?? '0');
            if (Money::isPositive($v)) {
                return $v;
            }
        }

        return Money::normalize($o->price);
    }
}
