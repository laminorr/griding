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
 * A FAILURE (the intent transaction or the pre-send step threw) is not final:
 * the exit is stamped rearm_state = 'retry:<n>:<KIND>' and the next sweep
 * retries it inside the fill-age window. The 3rd consecutive failure logs ONE
 * CRITICAL REARM_STUCK; once the window has passed without success the sweep
 * stamps 'abandoned:<KIND>' and logs ONE ERROR REARM_ABANDONED (bot:audit
 * flags both). `php artisan grid:rearm {exit}` re-arms such a level by hand
 * through rearmManually() — the same gates, minus the window.
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
    private const GRID_ROLES = [GridOrder::ROLE_INITIAL_GRID, GridOrder::ROLE_REBALANCE, GridOrder::ROLE_REARM];

    /** rearm_state of an exit whose re-arm failed and will be retried: 'retry:<n>:<KIND>'. */
    public const STATE_RETRY_PREFIX = 'retry:';

    /** rearm_state of an exit whose retries ran out of fill-age window: 'abandoned:<KIND>'. */
    public const STATE_ABANDONED_PREFIX = 'abandoned:';

    /** Consecutive failures for one exit that log CRITICAL REARM_STUCK (once). */
    public const STUCK_AFTER_FAILURES = 3;

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
            ->where('role', GridOrder::ROLE_CYCLE_EXIT)
            ->where('status', GridOrder::STATUS_FILLED)
            ->whereNull('rearm_order_id')
            ->where(fn ($q) => $q->whereNull('rearm_state')->orWhere('rearm_state', 'like', self::STATE_RETRY_PREFIX . '%'))
            ->where('filled_at', '>=', now()->subMinutes($window))
            ->orderBy('id')
            ->get();

        $placed = 0;
        foreach ($exits as $exit) {
            if ($this->rearmAfterExit($exit, $bot) === 'placed') {
                $placed++;
            }
        }

        $this->abandonExpired($bot, $window);

        return $placed;
    }

    /**
     * Exits that failed inside the window and are now past it: the sweep will
     * not retry them, so say so once (ERROR REARM_ABANDONED) and stamp
     * 'abandoned:<KIND>'. Exits that never failed (e.g. filled before the
     * flag was switched on) are left alone.
     */
    private function abandonExpired(BotConfig $bot, int $window): void
    {
        $expired = GridOrder::where('bot_config_id', $bot->id)
            ->where('role', GridOrder::ROLE_CYCLE_EXIT)
            ->where('status', GridOrder::STATUS_FILLED)
            ->whereNull('rearm_order_id')
            ->where('rearm_state', 'like', self::STATE_RETRY_PREFIX . '%')
            ->where('filled_at', '<', now()->subMinutes($window))
            ->orderBy('id')
            ->get();

        foreach ($expired as $exit) {
            $state = (string) $exit->rearm_state;
            [$failures, $kind] = self::parseRetryState($state);

            $won = GridOrder::whereKey($exit->id)
                ->whereNull('rearm_order_id')
                ->where('rearm_state', $state)
                ->update(['rearm_state' => self::STATE_ABANDONED_PREFIX . $kind]);
            if ($won !== 1) {
                continue;
            }

            Log::channel('trading')->error('REARM_ABANDONED', [
                'bot_id'         => $bot->id,
                'exit_order_id'  => $exit->id,
                'failures'       => $failures,
                'last_failure'   => $kind,
                'filled_at'      => (string) $exit->filled_at,
                'window_minutes' => $window,
                'simulation'     => (bool) $bot->simulation,
                'hint'           => "php artisan grid:rearm {$exit->id}",
            ]);
        }
    }

    /**
     * Re-arm the level of one filled exit.
     *
     * @return string placed | skipped | already | lock_busy | not_eligible | flag_off | failed
     */
    public function rearmAfterExit(GridOrder $exit, BotConfig $bot): string
    {
        return $this->attempt($exit, $bot, false);
    }

    /**
     * Operator re-arm (`grid:rearm --apply`): rearmAfterExit() with every gate,
     * but also for an exit whose window passed ('abandoned:*'); a failure is
     * reported to the operator and does not touch the retry counter.
     */
    public function rearmManually(GridOrder $exit, BotConfig $bot): string
    {
        $result = $this->attempt($exit, $bot, true);

        $exit->refresh();
        Log::channel('trading')->warning('REARM_MANUAL', [
            'bot_id'         => $bot->id,
            'exit_order_id'  => $exit->id,
            'result'         => $result,
            'rearm_order_id' => $exit->rearm_order_id,
            'rearm_state'    => $exit->rearm_state,
            'simulation'     => (bool) $bot->simulation,
        ]);

        return $result;
    }

    /**
     * Whether the exit has no re-arm decision yet: never re-armed, and either
     * untouched or waiting for a retry ('abandoned:*' too, for an operator).
     */
    public static function isOpenForRearm(GridOrder $exit, bool $manual = false): bool
    {
        if ($exit->rearm_order_id !== null) {
            return false;
        }
        $state = $exit->rearm_state;

        return $state === null
            || str_starts_with((string) $state, self::STATE_RETRY_PREFIX)
            || ($manual && str_starts_with((string) $state, self::STATE_ABANDONED_PREFIX));
    }

    private function attempt(GridOrder $exit, BotConfig $bot, bool $manual): string
    {
        $bot = BotConfig::find($bot->id) ?? $bot;
        if (! $bot->rearm_exits) {
            return 'flag_off';
        }
        if ($exit->role !== GridOrder::ROLE_CYCLE_EXIT || $exit->status !== GridOrder::STATUS_FILLED) {
            return 'not_eligible';
        }

        $lock = Cache::lock("rearm:{$exit->id}", 10);
        if (! $lock->get()) {
            Log::channel('trading')->info('REARM_LOCK_BUSY', ['bot_id' => $bot->id, 'exit_order_id' => $exit->id]);
            return 'lock_busy';
        }

        $prevState = $exit->rearm_state;
        try {
            return $this->rearmLocked($exit, $bot, $manual);
        } catch (\Throwable $e) {
            // The fill and its CompletedTrade are already committed; never fail
            // the caller (W4 job / poller) over the re-arm. Nothing was linked,
            // so the next sweep retries inside the fill-age window.
            Log::channel('trading')->error('REARM_ERROR', [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'error' => $e->getMessage(),
            ]);
            $this->recordFailure($exit, $bot, 'ERROR', $e->getMessage(), $prevState, $manual);
            return 'failed';
        } finally {
            $lock->release();
        }
    }

    private function rearmLocked(GridOrder $exit, BotConfig $bot, bool $manual): string
    {
        $exit->refresh();
        if (! self::isOpenForRearm($exit, $manual)) {
            return 'already';
        }
        $prevState = $exit->rearm_state;

        // ---- Gates (each one final for this exit) -------------------------
        $plan = $this->evaluateGates($exit, $bot, false);
        if ($plan['skip'] === 'PRICE_INVARIANT') {
            Log::channel('trading')->error('REARM_REFUSED_PRICE_INVARIANT', $plan['ctx']);
            $this->markSkipped($exit, 'PRICE_INVARIANT');
            return 'skipped';
        }
        if ($plan['skip'] !== null) {
            return $this->skip($exit, $bot, $plan['skip'], $plan['ctx']);
        }

        ['parent' => $parent, 'root' => $root, 'side' => $side, 'price' => $price, 'generation' => $generation, 'ctx' => $ctx] = $plan;

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
                if (! $current || ! self::isOpenForRearm($current, $manual) || $current->rearm_state !== $prevState) {
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
                            'status'              => GridOrder::STATUS_PENDING,
                            'role'                => GridOrder::ROLE_REARM,
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
                $this->recordFailure($exit, $bot, 'INTENT_FAILED', $e->getMessage(), $prevState, $manual);
                return 'failed';
            }
        } finally {
            $levelLock->release();
        }

        if ($skipReason !== null) {
            return $this->skip($exit, $bot, $skipReason, $skipCtx);
        }

        return $this->place($rearm, $exit, $bot, $root, $prevState, $manual);
    }

    /**
     * Every gate up to (not including) the level-busy check and sizing, in the
     * order rearmLocked() applies them. $dryRun reads the kill switch without
     * tripping it. 'skip' is the REASON of the first gate that refuses, or null.
     *
     * @return array{skip:?string, ctx:array, parent:?GridOrder, root:?GridOrder, side:?string, price:?string, generation:?int}
     */
    private function evaluateGates(GridOrder $exit, BotConfig $bot, bool $dryRun): array
    {
        $out = ['skip' => null, 'ctx' => [], 'parent' => null, 'root' => null, 'side' => null, 'price' => null, 'generation' => null];
        $refuse = fn (string $reason, array $ctx = []) => ['skip' => $reason, 'ctx' => $ctx] + $out;

        if (! $bot->is_active) {
            return $refuse('BOT_INACTIVE');
        }
        $kill = $dryRun ? $this->killSwitch->evaluate($bot) : $this->killSwitch->checkAndTrigger($bot);
        if ($kill['triggered']) {
            return $refuse('KILL_SWITCH', ['kill_reason' => $kill['reason']]);
        }
        if ((string) $bot->last_error_code === 'EXIT_BLOCKED') {
            return $refuse('EXIT_BLOCKED', ['scope' => 'bot']);
        }

        $parent = $exit->paired_order_id !== null ? GridOrder::find($exit->paired_order_id) : null;
        if ($parent === null || (int) $parent->bot_config_id !== (int) $bot->id) {
            return $refuse('NO_PARENT');
        }
        $out['parent'] = $parent;
        if ($exit->exit_state === 'blocked' || $parent->exit_state === 'blocked') {
            return $refuse('EXIT_BLOCKED', ['scope' => 'order', 'parent_order_id' => $parent->id]);
        }
        if (! in_array($parent->role, self::GRID_ROLES, true) || $parent->type === $exit->type) {
            return $refuse('NOT_GRID_LEVEL', ['parent_order_id' => $parent->id, 'parent_role' => $parent->role]);
        }

        $root = $parent->role === GridOrder::ROLE_REARM ? GridOrder::find($parent->rearm_root_order_id) : $parent;
        if ($root === null) {
            return $refuse('NO_ROOT', ['parent_order_id' => $parent->id]);
        }

        $side  = (string) $parent->type;
        $price = Money::normalize((string) $root->price);
        $ctx   = ['root_order_id' => $root->id, 'side' => $side, 'price' => $price];
        $out   = ['root' => $root, 'side' => $side, 'price' => $price, 'ctx' => $ctx] + $out;

        $generation = $this->currentGeneration($parent, $bot);
        if ($generation === null) {
            return $refuse('OLD_GENERATION', $ctx + [
                'level_generation' => $parent->grid_generation, 'bot_generation' => (int) $bot->grid_generation,
            ]);
        }
        $out['generation'] = $generation;

        // Invariant 5: a re-arm sell above its exit buy, a re-arm buy below its exit sell.
        $cmp = Money::compare($price, Money::normalize((string) $exit->price));
        if (($side === GridOrder::TYPE_SELL && $cmp <= 0) || ($side === GridOrder::TYPE_BUY && $cmp >= 0)) {
            return $refuse('PRICE_INVARIANT', $ctx + [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'exit_side' => $exit->type, 'exit_price' => (string) $exit->price,
            ]);
        }

        return $out;
    }

    /**
     * What rearmAfterExit() WOULD do for this exit, right now, without writing
     * anything: every gate (kill switch read-only), the level-busy check and
     * the sizing (ExitSizer dry-run: no dust move, no log). The fill-age
     * window is reported, not applied. For `grid:rearm` dry-runs.
     *
     * @return array<string,mixed>
     */
    public function preview(GridOrder $exit, BotConfig $bot): array
    {
        $bot    = BotConfig::find($bot->id) ?? $bot;
        $exit   = $exit->fresh() ?? $exit;
        $window = max(1, (int) config('trading.rearm.max_fill_age_minutes', 60));

        $out = [
            'bot_id'          => $bot->id,
            'exit_order_id'   => $exit->id,
            'exit_side'       => $exit->type,
            'exit_price'      => (string) $exit->price,
            'exit_status'     => $exit->status,
            'rearm_order_id'  => $exit->rearm_order_id,
            'rearm_state'     => $exit->rearm_state,
            'filled_at'       => $exit->filled_at?->toDateTimeString(),
            'fill_age_minutes' => $exit->filled_at ? (int) $exit->filled_at->diffInMinutes(now(), true) : null,
            'window_minutes'  => $window,
            'flag_on'         => (bool) $bot->rearm_exits,
            'simulation'      => (bool) $bot->simulation,
            'would_place'     => false,
            'refusal'         => null,
            'skip'            => null,
            'ctx'             => [],
            'side'            => null,
            'price'           => null,
            'amount'          => null,
            'notional'        => null,
            'below_min'       => null,
            'generation'      => ['bot' => (int) $bot->grid_generation, 'level' => null, 'ok' => null],
            'level_busy'      => null,
        ];
        $out['outside_window'] = $out['fill_age_minutes'] === null || $out['fill_age_minutes'] > $window;

        if (! $bot->rearm_exits) {
            return ['refusal' => 'FLAG_OFF'] + $out;
        }
        if ($exit->role !== GridOrder::ROLE_CYCLE_EXIT || $exit->status !== GridOrder::STATUS_FILLED) {
            return ['refusal' => 'NOT_ELIGIBLE'] + $out;
        }
        if ((int) $exit->bot_config_id !== (int) $bot->id) {
            return ['refusal' => 'OTHER_BOT'] + $out;
        }
        if (! self::isOpenForRearm($exit, true)) {
            return ['refusal' => 'ALREADY_DECIDED'] + $out;
        }

        $plan = $this->evaluateGates($exit, $bot, true);
        $out['side']  = $plan['side'];
        $out['price'] = $plan['price'];
        $out['ctx']   = $plan['ctx'];
        if ($plan['parent'] !== null) {
            $out['generation']['level'] = $plan['parent']->grid_generation;
        }
        $out['generation']['ok'] = match (true) {
            $plan['generation'] !== null      => true,
            $plan['skip'] === 'OLD_GENERATION' => false,
            default                           => null,   // refused before the generation gate
        };
        if ($plan['skip'] !== null) {
            return ['skip' => $plan['skip']] + $out;
        }

        $busy = GridOrder::where('bot_config_id', $bot->id)
            ->where('price', $plan['price'])
            ->whereIn('status', self::LIVE_STATUSES)
            ->first();
        $out['level_busy'] = $busy ? ['order_id' => $busy->id, 'status' => $busy->status, 'role' => $busy->role] : false;
        if ($busy) {
            return ['skip' => 'LEVEL_BUSY'] + $out;
        }

        $sizing = $plan['side'] === GridOrder::TYPE_SELL
            ? $this->sizer->reserveRearmSell($plan['parent'], $bot, $plan['price'], true)
            : $this->sizer->sizeRearmBuy($exit, $plan['root'], $bot, $plan['price'], true);

        $out['amount']    = $sizing['amount'];
        $out['notional']  = $sizing['notional'];
        $out['below_min'] = (bool) $sizing['below_min'];
        if (! Money::isPositive($sizing['amount']) || $sizing['below_min']) {
            return ['skip' => 'MIN_NOTIONAL'] + $out;
        }

        return ['would_place' => true] + $out;
    }

    /**
     * A retryable failure: count it on the exit ('retry:<n>:<KIND>') so the
     * next sweep retries it, and log CRITICAL REARM_STUCK exactly once, at the
     * STUCK_AFTER_FAILURES-th consecutive failure. An operator attempt only
     * logs (the operator sees the result).
     */
    private function recordFailure(GridOrder $exit, BotConfig $bot, string $kind, string $error, ?string $prevState, bool $manual): void
    {
        if ($manual) {
            return;
        }

        [$prev] = self::parseRetryState((string) $prevState);
        $failures = $prev + 1;

        try {
            GridOrder::whereKey($exit->id)
                ->whereNull('rearm_order_id')
                ->update(['rearm_state' => self::STATE_RETRY_PREFIX . $failures . ':' . $kind]);
        } catch (\Throwable $e) {
            Log::channel('trading')->error('REARM_FAILURE_NOT_RECORDED', [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'error' => $e->getMessage(),
            ]);
        }

        if ($failures === self::STUCK_AFTER_FAILURES) {
            Log::channel('trading')->critical('REARM_STUCK', [
                'bot_id'        => $bot->id,
                'exit_order_id' => $exit->id,
                'error'         => $error,
                'failures'      => $failures,
                'last_failure'  => $kind,
                'simulation'    => (bool) $bot->simulation,
            ]);
        }
    }

    /** 'retry:3:INTENT_FAILED' → [3, 'INTENT_FAILED']; anything else → [0, 'UNKNOWN']. */
    public static function parseRetryState(string $state): array
    {
        if (preg_match('/^retry:(\d+):([A-Z_]+)$/', $state, $m)) {
            return [(int) $m[1], $m[2]];
        }

        return [0, 'UNKNOWN'];
    }

    /**
     * Send the committed intent (outside any transaction), as
     * CheckTradesJob::createPairOrderLocked does for exits.
     */
    private function place(GridOrder $rearm, GridOrder $exit, BotConfig $bot, GridOrder $root, ?string $prevState, bool $manual): string
    {
        $apiCallAttempted = false;
        try {
            if ($bot->simulation) {
                if ($rearm->type === GridOrder::TYPE_SELL) {
                    app(SimulatedBasePosition::class)->checkExitSell($bot, $rearm, $exit->id);
                }
                $rearm->update(['status' => GridOrder::STATUS_PLACED, 'nobitex_order_id' => 'SIM-' . uniqid() . '-' . time()]);
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
                $rearm->update(['status' => GridOrder::STATUS_PLACED, 'nobitex_order_id' => (string) $nobitexId]);
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
                    'status'             => GridOrder::STATUS_CANCELLED,
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
                $rearm->update(['status' => GridOrder::STATUS_SUBMISSION_UNKNOWN]);
            } else {
                // Nothing left our process: undo and let the next sweep retry.
                DB::transaction(function () use ($rearm, $exit, $prevState) {
                    $rearm->update(['status' => GridOrder::STATUS_CANCELLED]);
                    $exit->forceFill(['rearm_order_id' => null, 'rearm_state' => $prevState])->save();
                });
                $this->sizer->revertDust($rearm);
            }

            Log::channel('trading')->error('REARM_PLACE_FAILED', [
                'bot_id' => $bot->id, 'exit_order_id' => $exit->id, 'rearm_order_id' => $rearm->id,
                'api_call_attempted' => $apiCallAttempted, 'error' => $e->getMessage(),
            ]);
            if (! $apiCallAttempted) {
                $this->recordFailure($exit, $bot, 'PLACE_FAILED', $e->getMessage(), $prevState, $manual);
            }
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

        if ($current !== 0 || $parent->role === GridOrder::ROLE_REARM) {
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
        $state = $exit->rearm_state;
        GridOrder::whereKey($exit->id)
            ->whereNull('rearm_order_id')
            ->where(fn ($q) => $state === null ? $q->whereNull('rearm_state') : $q->where('rearm_state', $state))
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
