<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use App\Support\QtyPrecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fee-correct sizing of cycle exit orders — the ONE function every pairing
 * path uses (minute poller, W4 single-order, simulation all reach it through
 * CheckTradesJob::createPairOrderLocked).
 *
 * Buy filled → exit SELL
 *   credited = filled − baseFee        (baseFee: the actual fee when it was
 *                                       charged in base, else a FeeModel
 *                                       estimate rounded UP)
 *   amount   = floor_qty(credited + base_dust)
 *   base_dust_after = credited + base_dust − amount   ∈ [0, one step)
 *   → never more than the BTC the buy credited plus the bot's own dust, and
 *     dust is folded into the sell automatically once it reaches a step. No
 *     dust-only order is ever placed: the amount is always this fill's own
 *     credited BTC plus (at most) the accumulated dust.
 *   If folding a NEGATIVE dust (a recorded shortfall) would push the order
 *   below the exchange minimum, the fold is deferred (EXIT_DUST_DEFERRED) and
 *   the sell is floor_qty(credited).
 *
 * Sell filled → exit BUY
 *   target   = filled (+ the sell's fee if it was charged in base)
 *   amount   = ceil_qty(target / (1 − buyRate))   when
 *              trading.fees.restore_inventory_on_buy_exit (default) — the
 *              BTC inventory is fully restored after the BTC buy fee
 *            = target                             otherwise (inventory shrinks
 *              by the buy fee each cycle; the shortfall lands in base_dust)
 *   The exit buy's real remainder (credited − target) is booked into
 *   base_dust when it FILLS (settleExitBuyFill), from its actual fee.
 *
 * All arithmetic is bcmath (App\Support\Money); quantities are fitted with
 * App\Support\QtyPrecision.
 */
class ExitSizer
{
    public function __construct(private FeeModel $fees) {}

    /**
     * Pure sizing (no I/O). See the class docblock for the rules.
     *
     * @param string      $filledSide  side of the FILLED order ('buy'|'sell')
     * @param string      $filled      matched base amount of the filled order
     * @param string|null $feeAmount   stored fee of the filled order (null = unknown)
     * @param string|null $feeCurrency 'base'|'quote'|null
     * @param string      $fillPrice   average fill price of the filled order
     * @param string      $exitPrice   limit price of the exit order
     * @param string      $dustBefore  bot base_dust before this exit
     * @return array{side:string, amount:string, gross:string, fee:string, fee_currency:string, fee_source:string, net:string, dust_before:string, dust_after:string, dust_delta:string, mode:string, deferred:bool, notional:string, below_min:bool}
     */
    public function compute(
        ?BotConfig $bot,
        string $symbol,
        string $filledSide,
        string $filled,
        ?string $feeAmount,
        ?string $feeCurrency,
        string $fillPrice,
        string $exitPrice,
        string $dustBefore,
    ): array {
        $filledSide = strtolower($filledSide);
        $minIrt     = (string) (int) (config('trading.min_order_value_irt') ?: 3_000_000);

        // The fee of the FILLED order, in base, as it affects inventory.
        if ($feeAmount !== null && $feeCurrency !== null) {
            $baseFee   = $feeCurrency === FeeModel::CURRENCY_BASE ? $feeAmount : '0';
            $feeSource = FeeModel::SOURCE_ACTUAL;
            $feeCur    = $feeCurrency;
            $feeShown  = $feeAmount;
        } else {
            $est       = $this->fees->estimate($filledSide, $filled, $fillPrice, $bot);
            $baseFee   = $est['currency'] === FeeModel::CURRENCY_BASE ? $est['amount'] : '0';
            $feeSource = FeeModel::SOURCE_ESTIMATED;
            $feeCur    = $est['currency'];
            $feeShown  = $est['amount'];
        }

        if ($filledSide === FeeModel::SIDE_BUY) {
            $credited  = Money::sub($filled, $baseFee);
            $available = Money::add($credited, $dustBefore);
            $amount    = Money::isPositive($available) ? QtyPrecision::floor($available, $symbol) : '0';
            $deferred  = false;

            // A negative dust (shortfall) must not shrink this exit below the
            // exchange minimum — defer that fold to a later, larger exit.
            if (Money::isNegative($dustBefore)
                && Money::compare(Money::mul($amount, $exitPrice), $minIrt) < 0) {
                $alone = Money::isPositive($credited) ? QtyPrecision::floor($credited, $symbol) : '0';
                if (Money::compare(Money::mul($alone, $exitPrice), $minIrt) >= 0) {
                    $amount   = $alone;
                    $deferred = true;
                }
            }

            $dustAfter = $deferred
                ? Money::add($dustBefore, Money::sub($credited, $amount))
                : Money::sub($available, $amount);

            return $this->result('sell', $amount, $filled, $feeShown, $feeCur, $feeSource, $credited,
                $dustBefore, $dustAfter, $deferred ? 'net_sell_dust_deferred' : 'net_sell', $exitPrice, $minIrt, $deferred);
        }

        // Sell filled → exit buy. A base-charged sell fee also left the account.
        $target = Money::add($filled, $baseFee);
        if ($this->fees->restoreInventoryOnBuyExit()) {
            $buyRate = $this->fees->rateFraction($bot, FeeModel::SIDE_BUY);
            $buyBase = $this->fees->expectedCurrency(FeeModel::SIDE_BUY) === FeeModel::CURRENCY_BASE;
            $raw     = $buyBase ? Money::div($target, Money::sub('1', $buyRate)) : $target;
            $amount  = QtyPrecision::ceil($raw, $symbol);
            $mode    = 'restore_buy';
        } else {
            $amount = QtyPrecision::ceil($target, $symbol);
            $mode   = 'plain_buy';
        }

        // Dust for a sell-first cycle is settled when the exit buy fills.
        return $this->result('buy', $amount, $filled, $feeShown, $feeCur, $feeSource, $target,
            $dustBefore, $dustBefore, $mode, $exitPrice, $minIrt, false);
    }

    /**
     * compute() + persist, inside the caller's pairing transaction: locks the
     * bot row, reads base_dust, sizes the exit for $fill and writes the new
     * base_dust. Returns the sizing; the caller stores 'dust_delta' on the
     * exit row as exit_dust_delta (see revertDust()).
     */
    public function reserve(GridOrder $fill, BotConfig $bot, string $exitPrice): array
    {
        $botRow = BotConfig::whereKey($bot->id)->lockForUpdate()->first() ?? $bot;
        $dust   = self::dec($botRow->getAttribute('base_dust'));

        $sizing = $this->compute(
            $botRow,
            (string) ($bot->symbol ?? 'BTCIRT'),
            (string) $fill->type,
            self::dec($fill->filled_amount ?? $fill->amount),
            $fill->fee_amount !== null ? self::dec($fill->fee_amount) : null,
            $fill->fee_currency !== null ? (string) $fill->fee_currency : null,
            self::dec($fill->avg_fill_price ?? $fill->price),
            $exitPrice,
            $dust,
        );

        if (Money::compare($sizing['dust_after'], $dust) !== 0) {
            BotConfig::whereKey($bot->id)->update(['base_dust' => $sizing['dust_after']]);
            $bot->setAttribute('base_dust', $sizing['dust_after']);
            // Keep the caller's instance in step WITHOUT marking it dirty, so a
            // later $bot->save() elsewhere can never write a stale dust back.
            $bot->syncOriginalAttribute('base_dust');
        }

        Log::channel('trading')->info('EXIT_SIZED', [
            'bot_id'          => $bot->id,
            'filled_order_id' => $fill->id,
            'exit_side'       => $sizing['side'],
            'mode'            => $sizing['mode'],
            'gross'           => $sizing['gross'],
            'fee'             => $sizing['fee'],
            'fee_currency'    => $sizing['fee_currency'],
            'fee_source'      => $sizing['fee_source'],
            'net'             => $sizing['net'],
            'amount'          => $sizing['amount'],
            'dust_before'     => $sizing['dust_before'],
            'dust_after'      => $sizing['dust_after'],
            'deferred'        => $sizing['deferred'],
        ]);
        if ($sizing['deferred']) {
            Log::channel('trading')->warning('EXIT_DUST_DEFERRED', [
                'bot_id' => $bot->id, 'filled_order_id' => $fill->id,
                'dust' => $sizing['dust_before'], 'amount' => $sizing['amount'],
                'note' => 'Folding the negative dust would push the exit below min_order_value_irt; kept for a later exit.',
            ]);
        }

        return $sizing;
    }

    /**
     * Undo an exit row's dust reservation (exit intent cancelled AND its fill
     * unlinked, so the fill will be re-sized later). Idempotent: the row's
     * exit_dust_delta is zeroed after reverting. Must run inside the caller's
     * transaction or on its own; it locks the bot row.
     */
    public function revertDust(GridOrder $exitRow): void
    {
        $delta = $exitRow->exit_dust_delta !== null ? self::dec($exitRow->exit_dust_delta) : '0';
        if (Money::isZero($delta)) {
            return;
        }

        DB::transaction(function () use ($exitRow, $delta) {
            $bot  = BotConfig::whereKey($exitRow->bot_config_id)->lockForUpdate()->first();
            if (! $bot) {
                return;
            }
            $before = self::dec($bot->getAttribute('base_dust'));
            $after  = Money::sub($before, $delta);
            BotConfig::whereKey($bot->id)->update(['base_dust' => $after]);
            $exitRow->forceFill(['exit_dust_delta' => '0'])->save();

            Log::channel('trading')->info('EXIT_DUST_REVERTED', [
                'bot_id' => $bot->id, 'exit_order_id' => $exitRow->id,
                'delta' => $delta, 'dust_before' => $before, 'dust_after' => $after,
            ]);
        });
    }

    /**
     * A sell-first cycle closes when its exit BUY fills: book the real
     * remainder credited − target (target = what the parent sell removed)
     * into base_dust. Positive with inventory-restoring buys (a sub-step
     * excess), negative without (the buy fee). Idempotent per exit row.
     */
    public function settleExitBuyFill(GridOrder $exitBuy, BotConfig $bot): void
    {
        if ($exitBuy->type !== 'buy' || $exitBuy->role !== 'cycle_exit'
            || $exitBuy->paired_order_id === null || $exitBuy->exit_dust_delta !== null
            || $exitBuy->net_base_delta === null) {
            return;
        }
        $parent = GridOrder::whereKey($exitBuy->paired_order_id)->first();
        if (! $parent || $parent->type !== 'sell' || $parent->net_base_delta === null) {
            return;
        }

        // parent.net_base_delta = −(sold + base sell fee) = −target
        $delta = Money::add(self::dec($exitBuy->net_base_delta), self::dec($parent->net_base_delta));

        DB::transaction(function () use ($exitBuy, $bot, $delta) {
            $row    = BotConfig::whereKey($bot->id)->lockForUpdate()->first();
            $before = self::dec($row?->getAttribute('base_dust'));
            $after  = Money::add($before, $delta);
            BotConfig::whereKey($bot->id)->update(['base_dust' => $after]);
            $bot->setAttribute('base_dust', $after);
            $bot->syncOriginalAttribute('base_dust');
            $exitBuy->forceFill(['exit_dust_delta' => $delta])->save();

            Log::channel('trading')->info('EXIT_BUY_DUST_SETTLED', [
                'bot_id' => $bot->id, 'exit_order_id' => $exitBuy->id,
                'delta' => $delta, 'dust_before' => $before, 'dust_after' => $after,
            ]);
        });
    }

    private function result(
        string $side, string $amount, string $gross, string $fee, string $feeCurrency, string $feeSource,
        string $net, string $dustBefore, string $dustAfter, string $mode, string $exitPrice, string $minIrt, bool $deferred,
    ): array {
        $notional = Money::mul($amount, $exitPrice);
        return [
            'side'         => $side,
            'amount'       => $amount,
            'gross'        => $gross,
            'fee'          => $fee,
            'fee_currency' => $feeCurrency,
            'fee_source'   => $feeSource,
            'net'          => $net,
            'dust_before'  => $dustBefore,
            'dust_after'   => $dustAfter,
            'dust_delta'   => Money::sub($dustAfter, $dustBefore),
            'mode'         => $mode,
            'deferred'     => $deferred,
            'notional'     => $notional,
            'below_min'    => Money::compare($notional, $minIrt) < 0,
        ];
    }

    /** DB/model value → decimal string ('0' for null/blank). */
    public static function dec(mixed $v): string
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return '0';
        }
        return Money::trimZeros(Money::normalize(is_string($v) ? trim($v) : $v));
    }
}
