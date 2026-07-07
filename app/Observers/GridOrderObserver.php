<?php
declare(strict_types=1);

namespace App\Observers;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11, Step 2 — populate the read-only inventory tracking columns on
 * bot_configs (open_cycles_count, capital_locked_irt) whenever a GridOrder
 * changes state.
 *
 * This is observation only: nothing consumes these values for decisions yet.
 * Later phases (Kill Switch, Rebalance accounting) will read them. Step 1 added
 * the columns (nullable = "not yet computed"); this step is the FIRST writer.
 *
 * DEFINITIONS (see the Step 1 migration docblock for the canonical wording):
 *   - "Open cycle"     = a GridOrder with role='cycle_exit' AND status='placed'.
 *                        A cycle_exit order exists because a matching buy/sell
 *                        already filled and now needs its counterpart; while it
 *                        sits at 'placed' the counterpart has not executed, so
 *                        the round-trip cycle is still open.
 *   - "Capital locked" = only buy-side open cycles lock capital. A cycle_exit
 *                        *sell* waiting to fill means we bought earlier and now
 *                        hold crypto; the IRT we spent is locked until the sell
 *                        completes. Its notional is derived from the FILLED
 *                        counterpart buy (paired_order_id): price × amount.
 *                        A cycle_exit *buy* waiting to fill means we sold
 *                        earlier and hold IRT to redeploy — that IRT is still
 *                        available, so those cycles are counted in
 *                        open_cycles_count but add nothing to capital_locked_irt.
 *
 * PERFORMANCE: this fires on EVERY GridOrder save. In steady state that is a
 * handful of recomputes per minute — fine. During initial grid placement 4-10
 * orders are created in rapid succession and each one triggers a full recompute
 * (two aggregate queries per save). Correctness over performance for now;
 * batching/debouncing is a later optimization.
 *
 * NO INFINITE LOOP: the recompute writes to BotConfig, a DIFFERENT model. No
 * observer is registered on BotConfig (this is the only observer in the app),
 * so updating it cannot cascade back into GridOrder queries.
 */
class GridOrderObserver
{
    /**
     * Fires on both create and update (Eloquent's "saved" covers insert +
     * update), so a brand-new cycle_exit order — created by
     * CheckTradesJob::createPairOrderLocked() — and a status transition on an
     * existing order both refresh the counts.
     */
    public function saved(GridOrder $order): void
    {
        $this->recompute($order);
    }

    public function deleted(GridOrder $order): void
    {
        $this->recompute($order);
    }

    /**
     * Resolve the owning bot for the changed order and recompute its inventory.
     *
     * Wrapped so a failure here can NEVER break the main save/delete flow: a
     * recorded fill must not be lost because an inventory recompute hiccuped.
     */
    private function recompute(GridOrder $order): void
    {
        try {
            if ($order->bot_config_id === null) {
                return;
            }

            $bot = BotConfig::find($order->bot_config_id);
            if ($bot === null) {
                return;
            }

            $this->recomputeInventoryForBot($bot);
        } catch (\Throwable $e) {
            Log::warning('GRID_ORDER_OBSERVER_RECOMPUTE_FAILED', [
                'grid_order_id' => $order->id ?? null,
                'bot_config_id' => $order->bot_config_id ?? null,
                'exception'     => get_class($e),
                'message'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recompute open_cycles_count and capital_locked_irt for one bot and persist
     * both in a single update to avoid multiple DB writes.
     */
    public function recomputeInventoryForBot(BotConfig $bot): void
    {
        // Open cycles: every cycle_exit order currently sitting at 'placed',
        // regardless of side (buy-side and sell-side both count here).
        $openCycles = GridOrder::query()
            ->where('bot_config_id', $bot->id)
            ->where('role', 'cycle_exit')
            ->where('status', 'placed')
            ->count();

        // Capital locked: sum the notional of buy-side open cycles only. A
        // buy-side open cycle is a cycle_exit *sell* awaiting fill; its locked
        // IRT is the notional of the FILLED buy it was paired against
        // (paired_order_id), computed as buy price × amount via bcmath so the
        // ~20-digit IRT value never picks up float noise.
        $capitalLocked = '0';

        $openSellCycles = GridOrder::query()
            ->where('bot_config_id', $bot->id)
            ->where('role', 'cycle_exit')
            ->where('status', 'placed')
            ->where('type', 'sell')
            ->whereNotNull('paired_order_id')
            ->get();

        foreach ($openSellCycles as $sell) {
            $buy = GridOrder::find($sell->paired_order_id);
            if ($buy === null) {
                continue;
            }

            $notional = Money::mul((string) $buy->price, (string) $buy->amount);
            $capitalLocked = Money::add($capitalLocked, $notional);
        }

        // capital_locked_irt is DECIMAL(20,0); store the whole-IRT value with no
        // fractional part. Money::mul on an 8-dp amount can yield fractional
        // digits, so trim to integer scale (bccomp/round at scale 0). We use a
        // plain bcadd at scale 0 to floor to the integer IRT the column holds.
        $capitalLockedIrt = Money::add($capitalLocked, '0', 0);

        // Single write: both columns in one update(). Nothing observes
        // BotConfig, so this does not cascade.
        $bot->update([
            'open_cycles_count'  => $openCycles,
            'capital_locked_irt' => $capitalLockedIrt,
        ]);
    }
}
