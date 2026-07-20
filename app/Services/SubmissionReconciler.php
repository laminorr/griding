<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OrderNotFoundException;
use App\Models\BotConfig;
use App\Models\GridOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SubmissionReconciler (Phase 12 Step 7)
 * ------------------------------------------------------------------
 * Resolves grid_orders rows parked in 'submission_unknown' (and rows stuck at
 * 'pending' long past any plausible placement) by asking Nobitex — read-only —
 * what actually happened. Each eligible row ends in exactly one of:
 *
 *   'placed'    — positive evidence the order IS on the exchange; the real
 *                 nobitex_order_id is recorded so CheckTradesJob's normal
 *                 fill-polling state machine takes over (including a fill or
 *                 cancel that happened while the row was parked).
 *   'cancelled' — the exchange definitively does not know the order: the
 *                 clientOrderId lookup answered NotFound on
 *                 `not_found_confirmations` consecutive runs AND no unclaimed
 *                 open order sits at the row's price/side. The grid level is
 *                 freed (a pair row's parent fill is unlinked for re-pairing).
 *   unchanged   — anything ambiguous. The row stays parked, an attempt is
 *                 recorded, and after max_attempts / max_age_hours it is
 *                 escalated loudly (error log + the bot's last_error_code
 *                 health surface from Phase 11). NEVER guessed.
 *
 * SAFETY INVARIANT: this class performs no exchange mutations — the only
 * NobitexService calls are getOrderByClientOrderId() and listOpenOrders(),
 * both reads. Local rows are the only thing written.
 *
 * Pending rows share the same resolution ladder deliberately: a process that
 * died mid-placement leaves 'pending' with exactly the same ambiguity as
 * 'submission_unknown' (the order may or may not have reached the exchange),
 * and the same client_order_id evidence applies. They only get a longer age
 * threshold, because 'pending' is also a normal transient state during a live
 * placement.
 *
 * Simulation bots are skipped before any HTTP: SIM-* orders never existed on
 * any exchange, so there is nothing to reconcile against.
 *
 * Concurrency: rows are taken under Cache::lock("reconcile-order:{id}") so two
 * reconciler runs cannot fight, and unlinking a parent fill takes the same
 * Cache::lock("pair-order:{fillId}") CheckTradesJob::createPairOrder() uses,
 * so a cancel can never interleave with an in-flight pairing of the same fill.
 */
class SubmissionReconciler
{
    public const STATUSES_IN_SCOPE = ['submission_unknown', 'pending'];

    public function __construct(protected NobitexService $svc)
    {
    }

    /**
     * Reconcile every eligible parked row (optionally for a single bot).
     *
     * @return array<string,int> Summary counters for logging/CLI output.
     */
    public function run(?int $onlyBotId = null): array
    {
        $summary = [
            'examined'         => 0,
            'placed'           => 0,
            'cancelled'        => 0,
            'unresolved'       => 0,
            'escalated'        => 0,
            'skipped_young'    => 0,
            'skipped_sim'      => 0,
            'skipped_locked'   => 0,
        ];

        $bots = BotConfig::query()
            ->when($onlyBotId !== null, fn ($q) => $q->where('id', $onlyBotId))
            ->whereHas('gridOrders', fn ($q) => $q->whereIn('status', self::STATUSES_IN_SCOPE))
            ->get();

        foreach ($bots as $bot) {
            $rows = $bot->gridOrders()
                ->whereIn('status', self::STATUSES_IN_SCOPE)
                ->orderBy('id')
                ->get();

            if ($bot->simulation) {
                // SIM-* ids never existed on any exchange — nothing to ask
                // Nobitex about, and no HTTP may be made for these bots.
                $summary['skipped_sim'] += $rows->count();
                Log::channel('trading')->debug('RECONCILE_SKIP_SIMULATION_BOT', [
                    'bot_id' => $bot->id,
                    'rows'   => $rows->count(),
                ]);
                continue;
            }

            foreach ($rows as $row) {
                if (! $this->isOldEnough($row)) {
                    // The placing process may still be running — hands off.
                    $summary['skipped_young']++;
                    continue;
                }

                $lock = Cache::lock("reconcile-order:{$row->id}", 30);
                if (! $lock->get()) {
                    $summary['skipped_locked']++;
                    continue;
                }

                try {
                    $fresh = $row->fresh();
                    if (! $fresh || ! in_array($fresh->status, self::STATUSES_IN_SCOPE, true)) {
                        continue; // resolved elsewhere while we waited
                    }

                    $summary['examined']++;
                    $outcome = $this->resolveRow($fresh, $bot);
                    $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
                } finally {
                    $lock->release();
                }
            }
        }

        return $summary;
    }

    /**
     * Rows currently parked, with everything an operator needs to check them
     * against the Nobitex panel by hand. Used by grid:reconcile-submissions
     * --list; makes no HTTP calls.
     *
     * @return Collection<int,GridOrder>
     */
    public function parkedRows(?int $onlyBotId = null): Collection
    {
        return GridOrder::query()
            ->whereIn('status', self::STATUSES_IN_SCOPE)
            ->when($onlyBotId !== null, fn ($q) => $q->where('bot_config_id', $onlyBotId))
            ->with('botConfig')
            ->orderBy('bot_config_id')
            ->orderBy('id')
            ->get();
    }

    /* -----------------------------------------------------------------
     | Resolution ladder
     |------------------------------------------------------------------*/

    /**
     * @return string One of 'placed' | 'cancelled' | 'unresolved'.
     */
    protected function resolveRow(GridOrder $row, BotConfig $bot): string
    {
        $this->recordAttempt($row);

        $symbol = $bot->symbol ?? 'BTCIRT';

        // ---- Probe A: direct lookup by the client-supplied id. -----------
        $notFoundByClientId = false;

        if ($row->client_order_id) {
            try {
                $order = $this->svc->getOrderByClientOrderId($row->client_order_id);
            } catch (\Throwable $e) {
                Log::channel('trading')->warning('RECONCILE_PROBE_FAILED', [
                    'order_id'        => $row->id,
                    'bot_id'          => $bot->id,
                    'probe'           => 'orders/status by clientOrderId',
                    'client_order_id' => $row->client_order_id,
                    'error'           => $e->getMessage(),
                ]);

                return $this->leaveUnresolved($row, $bot, 'status probe failed: ' . $e->getMessage());
            }

            if ($order !== null) {
                if ($this->identityMatches($order, $row)) {
                    return $this->resolveAsPlaced($row, $bot, (string) ($order['id'] ?? ''), 'clientOrderId lookup');
                }

                // An order exists under OUR deterministic id but its price or
                // side disagree with the local row. That should be impossible
                // (id collision / data corruption) — refuse to touch anything.
                Log::channel('trading')->error('RECONCILE_IDENTITY_MISMATCH', [
                    'order_id'        => $row->id,
                    'bot_id'          => $bot->id,
                    'client_order_id' => $row->client_order_id,
                    'local'           => ['type' => $row->type, 'price' => (string) $row->price],
                    'exchange'        => ['type' => $order['type'] ?? null, 'price' => $order['price'] ?? null],
                ]);

                return $this->leaveUnresolved($row, $bot, 'exchange order under this clientOrderId does not match the local row');
            }

            $notFoundByClientId = true;
        }

        // ---- Probe B: the account's open orders for this market. ---------
        try {
            $openOrders = $this->svc->listOpenOrders($symbol);
        } catch (\Throwable $e) {
            Log::channel('trading')->warning('RECONCILE_PROBE_FAILED', [
                'order_id' => $row->id,
                'bot_id'   => $bot->id,
                'probe'    => 'orders/list (open)',
                'error'    => $e->getMessage(),
            ]);

            return $this->leaveUnresolved($row, $bot, 'open-orders probe failed: ' . $e->getMessage());
        }

        $claimedIds = $this->claimedExchangeIds($openOrders);

        // Open orders at this row's exact price+side that no local row owns.
        $candidates = array_values(array_filter($openOrders, function (array $o) use ($row, $claimedIds) {
            $id = (string) ($o['id'] ?? '');

            return $id !== ''
                && ! in_array($id, $claimedIds, true)
                && strtolower((string) ($o['type'] ?? '')) === strtolower((string) $row->type)
                && (string) (int) ($o['price'] ?? 0) === (string) (int) $row->price;
        }));

        // B1 — the list echoes our clientOrderId back: certain identity.
        foreach ($candidates as $o) {
            $echoed = (string) ($o['clientOrderId'] ?? $o['client_ref'] ?? '');
            if ($row->client_order_id && $echoed === (string) $row->client_order_id) {
                return $this->resolveAsPlaced($row, $bot, (string) $o['id'], 'clientOrderId echoed in open-orders list');
            }
        }

        // B2 — exactly one unclaimed open order with the full fingerprint
        // (price+side+amount). Grid amounts are computed odd values, so a
        // coincidental foreign order matching all three is very unlikely; a
        // UNIQUE match is adopted, anything else is refused below.
        $fingerprint = array_values(array_filter(
            $candidates,
            fn (array $o) => $this->amountsMatch($o['amount'] ?? null, $row->amount)
        ));

        if (count($fingerprint) === 1) {
            Log::channel('trading')->warning('RECONCILE_ADOPTED_BY_FINGERPRINT', [
                'order_id'         => $row->id,
                'bot_id'           => $bot->id,
                'nobitex_order_id' => (string) $fingerprint[0]['id'],
                'note'             => 'Matched on price+side+amount only — clientOrderId was not resolvable.',
            ]);

            return $this->resolveAsPlaced($row, $bot, (string) $fingerprint[0]['id'], 'unique price/side/amount fingerprint');
        }

        if ($candidates !== []) {
            // Something unclaimed IS open at this price/side but identity is
            // not provable (amount differs, or several identical orders).
            // Could be ours, could be a manual order — never guess, and do
            // not let the absence streak grow while a lookalike is live.
            if ($row->reconcile_not_found_count > 0) {
                $row->forceFill(['reconcile_not_found_count' => 0])->save();
            }

            Log::channel('trading')->warning('RECONCILE_AMBIGUOUS_OPEN_ORDERS', [
                'order_id'   => $row->id,
                'bot_id'     => $bot->id,
                'candidates' => array_map(fn (array $o) => [
                    'id' => $o['id'] ?? null, 'amount' => $o['amount'] ?? null,
                ], $candidates),
            ]);

            return $this->leaveUnresolved($row, $bot, 'unclaimed open order(s) at this price/side — identity unprovable');
        }

        // ---- Absence: NotFound by clientOrderId AND nothing open here. ---
        if ($notFoundByClientId && (bool) config('trading.reconcile.cancel_on_not_found', true)) {
            $confirmations = max(1, (int) config('trading.reconcile.not_found_confirmations', 2));
            $streak        = (int) $row->reconcile_not_found_count + 1;

            if ($streak >= $confirmations) {
                return $this->resolveAsCancelled($row, $bot, $streak);
            }

            $row->forceFill(['reconcile_not_found_count' => $streak])->save();
            Log::channel('trading')->info('RECONCILE_NOT_FOUND_AWAITING_CONFIRMATION', [
                'order_id' => $row->id,
                'bot_id'   => $bot->id,
                'streak'   => $streak,
                'needed'   => $confirmations,
            ]);

            return $this->leaveUnresolved($row, $bot, "NotFound streak {$streak}/{$confirmations} — awaiting confirmation");
        }

        return $this->leaveUnresolved(
            $row,
            $bot,
            $row->client_order_id
                ? 'no definitive evidence either way'
                : 'row has no client_order_id — absence cannot be proven automatically'
        );
    }

    /* -----------------------------------------------------------------
     | Outcomes
     |------------------------------------------------------------------*/

    protected function resolveAsPlaced(GridOrder $row, BotConfig $bot, string $nobitexOrderId, string $evidence): string
    {
        if ($nobitexOrderId === '') {
            return $this->leaveUnresolved($row, $bot, 'exchange order found but its id was missing from the payload');
        }

        // Only the status and the real id change; pairing links stay intact.
        // CheckTradesJob's next poll drives the row through the normal state
        // machine — including straight to 'filled' or 'cancelled' if that is
        // what actually happened while the row was parked.
        $row->forceFill([
            'status'           => 'placed',
            'nobitex_order_id' => $nobitexOrderId,
        ])->save();

        Log::channel('trading')->info('RECONCILE_RESOLVED_PLACED', [
            'order_id'         => $row->id,
            'bot_id'           => $bot->id,
            'nobitex_order_id' => $nobitexOrderId,
            'evidence'         => $evidence,
            'attempts'         => (int) $row->reconcile_attempts,
        ]);

        return 'placed';
    }

    protected function resolveAsCancelled(GridOrder $row, BotConfig $bot, int $streak): string
    {
        $parentFillId = $row->paired_order_id;

        // A pair-intent row back-links the fill that spawned it; freeing the
        // level means unlinking that fill so CheckTradesJob may pair it again.
        // Take the SAME per-fill lock the pairing path uses, so we can never
        // unlink underneath an in-flight createPairOrderLocked().
        if ($parentFillId !== null) {
            $pairLock = Cache::lock("pair-order:{$parentFillId}", 10);
            if (! $pairLock->get()) {
                return $this->leaveUnresolved($row, $bot, 'parent fill is being paired right now — retry next run');
            }

            try {
                $this->cancelRowAndUnlink($row, $parentFillId);
            } finally {
                $pairLock->release();
            }
        } else {
            $row->forceFill(['status' => 'cancelled'])->save();
        }

        Log::channel('trading')->info('RECONCILE_RESOLVED_CANCELLED', [
            'order_id'         => $row->id,
            'bot_id'           => $bot->id,
            'client_order_id'  => $row->client_order_id,
            'not_found_streak' => $streak,
            'note'             => 'Exchange answered NotFound repeatedly and no unclaimed open order matches — grid level freed.',
        ]);

        return 'cancelled';
    }

    protected function cancelRowAndUnlink(GridOrder $row, int $parentFillId): void
    {
        DB::transaction(function () use ($row, $parentFillId) {
            $row->forceFill(['status' => 'cancelled'])->save();

            $parent = GridOrder::where('id', $parentFillId)->lockForUpdate()->first();
            if ($parent && (int) $parent->paired_order_id === (int) $row->id) {
                $parent->forceFill(['paired_order_id' => null])->save();
            }
        });
    }

    protected function leaveUnresolved(GridOrder $row, BotConfig $bot, string $reason): string
    {
        $maxAttempts = max(1, (int) config('trading.reconcile.max_attempts', 12));
        $maxAgeHours = max(1, (int) config('trading.reconcile.max_age_hours', 6));
        $ageHours    = $row->created_at ? $row->created_at->diffInRealHours(now()) : 0;

        $stuck = (int) $row->reconcile_attempts >= $maxAttempts || $ageHours >= $maxAgeHours;

        if ($stuck) {
            Log::channel('trading')->error('RECONCILE_STUCK', [
                'order_id'        => $row->id,
                'bot_id'          => $bot->id,
                'status'          => $row->status,
                'type'            => $row->type,
                'price'           => (string) $row->price,
                'amount'          => (string) $row->amount,
                'client_order_id' => $row->client_order_id,
                'attempts'        => (int) $row->reconcile_attempts,
                'age_hours'       => $ageHours,
                'reason'          => $reason,
                'action'          => 'Manual check required: verify this order in the Nobitex panel by price/side/amount, then set the row to placed (with its id) or cancelled.',
            ]);

            // Surface on the existing bot health surface (Phase 11):
            // last_error_code/-message feed scopeHasError() and the
            // last_error_summary accessor shown in the admin panel.
            $bot->forceFill([
                'last_error_code'    => 'RECONCILE_STUCK',
                'last_error_message' => sprintf(
                    'Order #%d (%s %s @ %s) unresolved after %d attempts / %sh — check it on Nobitex manually (client id: %s).',
                    $row->id,
                    $row->type,
                    (string) $row->amount,
                    (string) $row->price,
                    (int) $row->reconcile_attempts,
                    $ageHours,
                    $row->client_order_id ?? '—'
                ),
            ])->save();
        } else {
            Log::channel('trading')->info('RECONCILE_UNRESOLVED', [
                'order_id' => $row->id,
                'bot_id'   => $bot->id,
                'attempts' => (int) $row->reconcile_attempts,
                'reason'   => $reason,
            ]);
        }

        return 'unresolved';
    }

    /* -----------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------*/

    protected function isOldEnough(GridOrder $row): bool
    {
        $minAge = $row->status === 'pending'
            ? (int) config('trading.reconcile.pending_min_age_seconds', 900)
            : (int) config('trading.reconcile.min_age_seconds', 300);

        $createdAt = $row->created_at instanceof Carbon ? $row->created_at : null;

        return $createdAt !== null && $createdAt->lte(now()->subSeconds($minAge));
    }

    protected function recordAttempt(GridOrder $row): void
    {
        $row->forceFill([
            'reconcile_attempts'        => (int) $row->reconcile_attempts + 1,
            'reconcile_last_attempt_at' => now(),
        ])->save();
    }

    /** Exchange order id + local row must tell the same story. */
    protected function identityMatches(array $order, GridOrder $row): bool
    {
        $sideOk  = strtolower((string) ($order['type'] ?? '')) === strtolower((string) $row->type);
        $priceOk = (string) (int) ($order['price'] ?? 0) === (string) (int) $row->price;

        if ($sideOk && $priceOk && ! $this->amountsMatch($order['amount'] ?? null, $row->amount)) {
            // Identity holds on the deterministic axes; a differing amount is
            // worth a breadcrumb but does not invalidate the match.
            Log::channel('trading')->warning('RECONCILE_AMOUNT_DIVERGENCE', [
                'order_id' => $row->id,
                'local'    => (string) $row->amount,
                'exchange' => (string) ($order['amount'] ?? ''),
            ]);
        }

        return $sideOk && $priceOk;
    }

    /**
     * Exchange ids already owned by ANY local grid_orders row — an open order
     * carrying one of these cannot be the missing order for a parked row.
     *
     * @param array<int,array<string,mixed>> $openOrders
     * @return array<int,string>
     */
    protected function claimedExchangeIds(array $openOrders): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $o) => (string) ($o['id'] ?? ''),
            $openOrders
        )));

        if ($ids === []) {
            return [];
        }

        return GridOrder::whereIn('nobitex_order_id', $ids)
            ->pluck('nobitex_order_id')
            ->map(static fn ($id) => (string) $id)
            ->all();
    }

    /**
     * 8-dp amount equality without bcmath (the deploy host has ext-bcmath but
     * the test sandbox may not, and reconciliation only ever compares
     * quantities far inside double precision).
     */
    protected function amountsMatch(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return number_format((float) $a, 8, '.', '') === number_format((float) $b, 8, '.', '');
    }
}
