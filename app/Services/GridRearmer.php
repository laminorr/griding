<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DefinitiveOrderRejection;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use App\Support\OrderRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Classic-grid re-arm («چراغ‌ها») — behind bot_configs.rearm_exits (default OFF).
 *
 * Without it a level goes dark once its cycle closes: grid order A fills →
 * exit B → B fills → CompletedTrade → nothing. With it, B's fill also places a
 * RE-ARM C: the SAME side as A, at the chain ROOT's price (copied, never
 * recomputed from B, so the level cannot drift with tick rounding), role
 * 'rearm'. C is an ordinary grid order from then on: its fill gets its exit
 * through CheckTradesJob::createPairOrderLocked exactly like an initial or
 * rebalance order, and that exit's fill re-arms again — A → B → C → D → E …
 *
 * Entry points (both in CheckTradesJob, after the exit fill is committed and
 * its CompletedTrade booked): sweep() at the end of processBot() (minute
 * poller + simulation), rearmAfterExit() at the end of processSingleOrder()
 * (W4). One exit fill → at most ONE re-arm: a per-exit Cache lock, a
 * lockForUpdate re-read of the exit's rearm_order_id / rearm_state, the intent
 * row and the exit's back-link committed in ONE transaction, and a UNIQUE
 * index on grid_orders.rearm_exit_order_id. The exchange call happens after
 * that commit, as for exits.
 *
 * Every refusal is final for that exit (rearm_state = 'skipped:<REASON>',
 * WARNING REARM_SKIPPED_<REASON>) and the level stays dark until a rebuild.
 *
 * Generation rule (no resurrection of old levels): GridOrderExecutor stamps
 * every grid row with bot_configs.grid_generation and starts a new generation
 * on each grid build (initial placement / AdjustGridJob rebuild), re-stamping
 * the open rows the rebuild kept. A re-arm inherits the generation of the
 * order that opened the cycle; it is placed only when that generation is the
 * bot's current one. A cycle opened before a rebuild therefore never re-arms
 * after it. Rows that predate the column (NULL) count as current only while
 * no build has happened since the deploy (bot generation 0) and their price
 * is a level of the current grid batch (OrderRegistry::currentGridLevelPrices).
 */
class GridRearmer
{
    /** Live statuses: a level with one of these at its price is busy. */
    public const LIVE_STATUSES = GridOrderExecutor::ACTIVE_STATUSES;

    /** Orders whose fill opened a re-armable level. */
    private const GRID_ROLES = ['initial_grid', 'rebalance', 'rearm'];

    public function __construct(
        private ExitSizer $sizer,
        private KillSwitchService $killSwitch,
        private OrderRegistry $registry,
    ) {}

    /**
     * Poller / simulation pass: re-arm every recently filled exit of the bot
     * that has no re-arm decision yet. Exits filled before the window (e.g.
     * before the flag was switched on) are never re-armed.
     */
    public function sweep(BotConfig $bot): int
    {
        if (! $bot->rearm_exits) {
            return 0;
        }

        $window = max(1, (int) config('trading.rearm.max_fill_age_minutes', 60));

        $exits = GridOrder::where('bot_config_id', $bot->id)
            ->where('role', 'cycle_exit')
            ->where('status', 'filled')
            ->whereNull('rearm_order_id')
            ->whereNull('rearm_state')
            ->where('filled_at', '>=', now()->subMinutes($window))
            ->orderBy('id')
            ->get();

        $placed = 0;
        foreach ($exits as $exit) {
            if ($this->rearmAfterExit($exit, $bot) === 'placed') {
                $placed++;
            }
        }

        return $placed;
    }

    /**
     * Re-arm the level of one filled exit.
     *
     * @return string placed | skipped | already | lock_busy | not_eligible | flag_off | failed
     */
    public function rearmAfterExit(GridOrder $exit, BotConfig $bot): string
    {
        $bot = BotConfig::find($bot->id) ?? $bot;
        if (! $bot->rearm_exits) {
            return 'flag_off';
        }
        if ($exit->role !== 'cycle_exit' || $exit->status !== 'filled') {
            return 'not_eligible';
        }

        $lock = Cache::lock("rearm:{$exit->id}", 10);
        if (! $lock->get()) {
            Log::channel('trading')->info('REARM_LOCK_BUSY', ['bot_id' => $bot->id, 'exit_order_id' => $exit->id]);
            return 'lock_busy';
        }

        try {
            return $this->rearmLocked($exit, $bot);
        } catch (\Throwable $e) {
            // The fill and its CompletedTrade are already committed; never fail
            // the caller (W4 job / poller) over the re-arm. Nothing was linked,
            // so the next sweep retries inside the fill-age window.
            Log::channel('trading')->error('REARM_ERROR', [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'error' => $e->getMessage(),
            ]);
            return 'failed';
        } finally {
            $lock->release();
        }
    }

    private function rearmLocked(GridOrder $exit, BotConfig $bot): string
    {
        $exit->refresh();
        if ($exit->rearm_order_id !== null || $exit->rearm_state !== null) {
            return 'already';
        }

        // ---- Gates (each one final for this exit) -------------------------
        if (! $bot->is_active) {
            return $this->skip($exit, $bot, 'BOT_INACTIVE');
        }
        $kill = $this->killSwitch->checkAndTrigger($bot);
        if ($kill['triggered']) {
            return $this->skip($exit, $bot, 'KILL_SWITCH', ['kill_reason' => $kill['reason']]);
        }
        if ((string) $bot->last_error_code === 'EXIT_BLOCKED') {
            return $this->skip($exit, $bot, 'EXIT_BLOCKED', ['scope' => 'bot']);
        }

        $parent = $exit->paired_order_id !== null ? GridOrder::find($exit->paired_order_id) : null;
        if ($parent === null || (int) $parent->bot_config_id !== (int) $bot->id) {
            return $this->skip($exit, $bot, 'NO_PARENT');
        }
        if ($exit->exit_state === 'blocked' || $parent->exit_state === 'blocked') {
            return $this->skip($exit, $bot, 'EXIT_BLOCKED', ['scope' => 'order', 'parent_order_id' => $parent->id]);
        }
        if (! in_array($parent->role, self::GRID_ROLES, true) || $parent->type === $exit->type) {
            return $this->skip($exit, $bot, 'NOT_GRID_LEVEL', ['parent_order_id' => $parent->id, 'parent_role' => $parent->role]);
        }

        $root = $parent->role === 'rearm' ? GridOrder::find($parent->rearm_root_order_id) : $parent;
        if ($root === null) {
            return $this->skip($exit, $bot, 'NO_ROOT', ['parent_order_id' => $parent->id]);
        }

        $side  = (string) $parent->type;
        $price = Money::normalize((string) $root->price);
        $ctx   = ['root_order_id' => $root->id, 'side' => $side, 'price' => $price];

        $generation = $this->currentGeneration($parent, $bot);
        if ($generation === null) {
            return $this->skip($exit, $bot, 'OLD_GENERATION', $ctx + [
                'level_generation' => $parent->grid_generation, 'bot_generation' => (int) $bot->grid_generation,
            ]);
        }

        // Invariant 5: a re-arm sell above its exit buy, a re-arm buy below its exit sell.
        $cmp = Money::compare($price, Money::normalize((string) $exit->price));
        if (($side === 'sell' && $cmp <= 0) || ($side === 'buy' && $cmp >= 0)) {
            Log::channel('trading')->error('REARM_REFUSED_PRICE_INVARIANT', $ctx + [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'exit_side' => $exit->type, 'exit_price' => (string) $exit->price,
            ]);
            $this->markSkipped($exit, 'PRICE_INVARIANT');
            return 'skipped';
        }

        // ---- Intent row + back-link, one transaction ----------------------
        // Same per-level lock GridOrderExecutor takes, so a rebuild placing
        // this exact level cannot interleave with the busy check below.
        $levelLock = Cache::lock("grid-level:{$bot->id}:{$side}:{$price}", 10);
        if (! $levelLock->get()) {
            return $this->skip($exit, $bot, 'LEVEL_BUSY', $ctx + ['note' => 'level lock held by a grid placement']);
        }

        $skipReason = null;
        $skipCtx    = [];
        $rearm      = null;
        $sizing     = null;
        try {
            DB::beginTransaction();
            try {
                $current = GridOrder::whereKey($exit->id)->lockForUpdate()->first();
                if (! $current || $current->rearm_order_id !== null || $current->rearm_state !== null) {
                    DB::commit();
                    return 'already';
                }

                // Invariant 2: at most one live order per price level.
                $busy = GridOrder::where('bot_config_id', $bot->id)
                    ->where('price', $price)
                    ->whereIn('status', self::LIVE_STATUSES)
                    ->first();
                if ($busy) {
                    DB::rollBack();
                    $skipReason = 'LEVEL_BUSY';
                    $skipCtx    = $ctx + ['busy_order_id' => $busy->id, 'busy_status' => $busy->status, 'busy_role' => $busy->role];
                } else {
                    $sizing = $side === 'sell'
                        ? $this->sizer->reserveRearmSell($parent, $bot, $price)
                        : $this->sizer->sizeRearmBuy($exit, $root, $bot, $price);

                    if (! Money::isPositive($sizing['amount']) || $sizing['below_min']) {
                        DB::rollBack();
                        $skipReason = 'MIN_NOTIONAL';
                        $skipCtx    = $ctx + ['amount' => $sizing['amount'], 'notional' => $sizing['notional']];
                    } else {
                        $rearm = GridOrder::createIntent([
                            'bot_config_id'       => $bot->id,
                            'price'               => $price,
                            'amount'              => $sizing['amount'],
                            'type'                => $side,
                            'status'              => 'pending',
                            'role'                => 'rearm',
                            'rearm_exit_order_id' => $exit->id,
                            'rearm_root_order_id' => $root->id,
                            'grid_generation'     => $generation,
                            // Lets a cancelled intent undo its dust move (revertDust).
                            'exit_dust_delta'     => $side === 'sell' ? $sizing['dust_delta'] : null,
                        ]);

                        $current->forceFill(['rearm_order_id' => $rearm->id, 'rearm_state' => 'placed'])->save();

                        DB::commit();
                    }
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::channel('trading')->error('REARM_INTENT_FAILED', $ctx + [
                    'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'error' => $e->getMessage(),
                ]);
                return 'failed';
            }
        } finally {
            $levelLock->release();
        }

        if ($skipReason !== null) {
            return $this->skip($exit, $bot, $skipReason, $skipCtx);
        }

        return $this->place($rearm, $exit, $bot, $root);
    }

    /**
     * Send the committed intent (outside any transaction), as
     * CheckTradesJob::createPairOrderLocked does for exits.
     */
    private function place(GridOrder $rearm, GridOrder $exit, BotConfig $bot, GridOrder $root): string
    {
        $apiCallAttempted = false;
        try {
            if ($bot->simulation) {
                if ($rearm->type === 'sell') {
                    app(SimulatedBasePosition::class)->checkExitSell($bot, $rearm, $exit->id);
                }
                $rearm->update(['status' => 'placed', 'nobitex_order_id' => 'SIM-' . uniqid() . '-' . time()]);
            } else {
                $apiCallAttempted = true;
                $resp = app(NobitexService::class)->placeOrder(
                    (string) ($bot->symbol ?? 'BTCIRT'),
                    (string) $rearm->type,
                    (int) $rearm->price,
                    (string) Money::trimZeros(Money::normalize((string) $rearm->amount)),
                    (string) $rearm->client_order_id,
                );
                if (($resp['status'] ?? null) !== 'ok') {
                    throw new \RuntimeException('Nobitex order placement failed: ' . ($resp['message'] ?? 'Unknown error'));
                }
                $nobitexId = $resp['order']['id'] ?? null;
                if (! $nobitexId) {
                    throw new \RuntimeException('Nobitex order ID not found in response');
                }
                $rearm->update(['status' => 'placed', 'nobitex_order_id' => (string) $nobitexId]);
            }

            Log::channel('trading')->info('REARM_PLACED', [
                'bot_id'         => $bot->id,
                'exit_order_id'  => $exit->id,
                'rearm_order_id' => $rearm->id,
                'root_order_id'  => $root->id,
                'side'           => $rearm->type,
                'price'          => (string) $rearm->price,
                'amount'         => Money::trimZeros(Money::normalize((string) $rearm->amount)),
                'simulation'     => (bool) $bot->simulation,
            ]);
            app(BotActivityLogger::class)->logOrderPlaced($bot->id, [
                'order_id' => $rearm->id, 'type' => $rearm->type, 'price' => (string) $rearm->price,
                'amount' => (string) $rearm->amount, 'nobitex_order_id' => $rearm->nobitex_order_id,
            ]);

            return 'placed';
        } catch (\Throwable $e) {
            if ($e instanceof DefinitiveOrderRejection) {
                // Certainly not on the exchange: cancel, give the dust back,
                // keep the back-link (one exit → one re-arm attempt).
                $rearm->update([
                    'status'             => 'cancelled',
                    'last_error_code'    => $e->errorCode(),
                    'last_error_message' => mb_substr($e->getMessage(), 0, 255),
                ]);
                $this->sizer->revertDust($rearm);
                $exit->forceFill(['rearm_state' => 'skipped:REJECTED'])->save();
                $this->logSkip($exit, $bot, 'REJECTED', ['rearm_order_id' => $rearm->id, 'code' => $e->errorCode(), 'message' => $e->getMessage()]);
                return 'skipped';
            }

            if ($apiCallAttempted) {
                // Ambiguous: Nobitex may hold it — the reconciler resolves it by
                // its clientOrderId; the back-link blocks any second re-arm.
                $rearm->update(['status' => 'submission_unknown']);
            } else {
                // Nothing left our process: undo and let the next sweep retry.
                DB::transaction(function () use ($rearm, $exit) {
                    $rearm->update(['status' => 'cancelled']);
                    $exit->forceFill(['rearm_order_id' => null, 'rearm_state' => null])->save();
                });
                $this->sizer->revertDust($rearm);
            }

            Log::channel('trading')->error('REARM_PLACE_FAILED', [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'rearm_order_id' => $rearm->id,
                'api_call_attempted' => $apiCallAttempted, 'error' => $e->getMessage(),
            ]);
            return 'failed';
        }
    }

    /**
     * The generation to stamp on the re-arm, or null when the level is not
     * part of the bot's current grid (see the class docblock).
     */
    private function currentGeneration(GridOrder $parent, BotConfig $bot): ?int
    {
        $current = (int) $bot->grid_generation;

        if ($parent->grid_generation !== null) {
            return (int) $parent->grid_generation === $current ? $current : null;
        }

        if ($current !== 0 || $parent->role === 'rearm') {
            return null;
        }

        $levels = $this->registry->currentGridLevelPrices($bot->id);
        return in_array((int) $parent->price, $levels, true) ? $current : null;
    }

    private function skip(GridOrder $exit, BotConfig $bot, string $reason, array $ctx = []): string
    {
        $this->markSkipped($exit, $reason);
        $this->logSkip($exit, $bot, $reason, $ctx);
        return 'skipped';
    }

    private function markSkipped(GridOrder $exit, string $reason): void
    {
        GridOrder::whereKey($exit->id)
            ->whereNull('rearm_order_id')
            ->whereNull('rearm_state')
            ->update(['rearm_state' => 'skipped:' . $reason]);
        $exit->refresh();
    }

    private function logSkip(GridOrder $exit, BotConfig $bot, string $reason, array $ctx): void
    {
        Log::channel('trading')->warning('REARM_SKIPPED_' . $reason, [
            'reason'        => $reason,
            'bot_id'        => $bot->id,
            'exit_order_id' => $exit->id,
        ] + $ctx);
    }
}
