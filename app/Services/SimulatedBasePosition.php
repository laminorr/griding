<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Fee model Phase 7 — a simple simulated base (BTC) wallet per simulation
 * bot, so paper trading hits the same "not enough BTC" wall live would
 * (audit D6 / E6).
 *
 *   position   = Σ grid_orders.net_base_delta over the bot's fills (buys add
 *                filled − BTC fee, sells subtract) — seed 0
 *   committed  = Σ amount of the bot's OTHER open cycle_exit and re-arm
 *                sells (pending / placed / partially_filled / submission_unknown)
 *   free       = position − committed
 *
 * Before a simulated exit SELL is "placed", its amount is compared with free;
 * if it would not fit, WARNING SIM_WOULD_FAIL_INSUFFICIENT_BASE is logged —
 * live would have been rejected with InsufficientBalance at this point. The
 * simulated order is still placed (simulation keeps running), the warning is
 * the signal. Initial/rebalance grid sells are NOT counted as committed: live
 * checks those against the real wallet at init (TradingEngineService).
 */
class SimulatedBasePosition
{
    private const OPEN = ['pending', 'placed', 'partially_filled', 'submission_unknown'];

    /** Σ net_base_delta of the bot's orders (decimal string). */
    public function position(int $botId): string
    {
        $sum = '0';
        GridOrder::where('bot_config_id', $botId)
            ->whereNotNull('net_base_delta')
            ->select(['id', 'net_base_delta'])
            ->cursor()
            ->each(function (GridOrder $o) use (&$sum) {
                $sum = Money::add($sum, ExitSizer::dec($o->net_base_delta));
            });
        return $sum;
    }

    /** BTC committed to the bot's open exit / re-arm sells, excluding $exceptOrderId. */
    public function committed(int $botId, ?int $exceptOrderId = null): string
    {
        $sum = '0';
        GridOrder::where('bot_config_id', $botId)
            ->whereIn('role', ['cycle_exit', 'rearm'])
            ->where('type', 'sell')
            ->whereIn('status', self::OPEN)
            ->when($exceptOrderId !== null, fn ($q) => $q->where('id', '!=', $exceptOrderId))
            ->select(['id', 'amount'])
            ->cursor()
            ->each(function (GridOrder $o) use (&$sum) {
                $sum = Money::add($sum, ExitSizer::dec($o->amount));
            });
        return $sum;
    }

    /**
     * Check a simulated exit sell against the simulated wallet; log the
     * warning if it would not fit. Returns true when it fits.
     */
    public function checkExitSell(BotConfig $bot, GridOrder $exitSell, ?int $filledOrderId = null): bool
    {
        $position = $this->position($bot->id);
        $free     = Money::sub($position, $this->committed($bot->id, $exitSell->id));
        $amount   = ExitSizer::dec($exitSell->amount);

        if (Money::compare($amount, $free) <= 0) {
            return true;
        }

        Log::channel('trading')->warning('SIM_WOULD_FAIL_INSUFFICIENT_BASE', [
            'bot_id'          => $bot->id,
            'exit_order_id'   => $exitSell->id,
            'filled_order_id' => $filledOrderId,
            'amount'          => $amount,
            'sim_position'    => $position,
            'sim_free'        => $free,
            'shortfall'       => Money::sub($amount, $free),
            'note'            => 'A live bot would get InsufficientBalance here (see docs/fees.md).',
        ]);
        return false;
    }
}
