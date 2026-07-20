<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SubmissionReconciler;
use Illuminate\Console\Command;

/**
 * Operator entry point for the Phase 12 Step 7 reconciler.
 *
 *   php artisan grid:reconcile-submissions            run the reconciler now
 *   php artisan grid:reconcile-submissions --bot=3    one bot only
 *   php artisan grid:reconcile-submissions --list     just show parked rows
 *                                                     (no exchange contact)
 *
 * --list prints everything needed to check a row against the Nobitex panel by
 * hand (side/price/amount/client id/age/attempts), which is the fallback when
 * a row escalates as RECONCILE_STUCK.
 */
class GridReconcileSubmissions extends Command
{
    protected $signature = 'grid:reconcile-submissions
                            {--bot= : Only reconcile this bot id}
                            {--list : List parked rows without contacting the exchange}';

    protected $description = 'Resolve grid orders parked in submission_unknown (and stale pending) — read-only against Nobitex';

    public function handle(SubmissionReconciler $reconciler): int
    {
        $botId = $this->option('bot') !== null ? (int) $this->option('bot') : null;

        if (! $this->option('list')) {
            $summary = $reconciler->run($botId);
            $this->info('Reconcile summary: ' . json_encode($summary));
        }

        $rows = $reconciler->parkedRows($botId);

        if ($rows->isEmpty()) {
            $this->info('No parked rows (submission_unknown / pending).');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'bot', 'sim', 'status', 'side', 'price (IRT)', 'amount', 'client_order_id', 'age', 'attempts', 'nf-streak', 'last attempt'],
            $rows->map(fn ($o) => [
                $o->id,
                $o->botConfig?->name . ' #' . $o->bot_config_id,
                $o->botConfig?->simulation ? 'yes' : 'no',
                $o->status,
                $o->type,
                (string) $o->price,
                (string) $o->amount,
                (string) $o->client_order_id,
                $o->created_at?->diffForHumans(short: true) ?? '—',
                (int) $o->reconcile_attempts,
                (int) $o->reconcile_not_found_count,
                $o->reconcile_last_attempt_at?->diffForHumans(short: true) ?? 'never',
            ])->all()
        );

        $this->line('To resolve a row manually: verify it in the Nobitex panel by side/price/amount,');
        $this->line("then update grid_orders.status to 'placed' (with nobitex_order_id) or 'cancelled'.");

        return self::SUCCESS;
    }
}
