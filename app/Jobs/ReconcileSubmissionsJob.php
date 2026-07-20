<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SubmissionReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ReconcileSubmissionsJob (Phase 12 Step 7)
 * ------------------------------------------------------------------
 * Thin scheduled wrapper around SubmissionReconciler. Read-only against the
 * exchange; only local grid_orders rows are updated. Scheduled every five
 * minutes alongside CheckTradesJob (see routes/console.php) — the two are
 * safe to run concurrently because the reconciler touches only
 * submission_unknown/pending rows under its own per-row Cache::lock and
 * borrows the pairing path's per-fill lock before unlinking a fill.
 *
 * $tries = 1: a run that dies mid-way resolved every row it got to; the next
 * scheduled tick simply continues with whatever is still parked. Re-running
 * the failed job would only duplicate read probes.
 */
class ReconcileSubmissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function __construct(public ?int $onlyBotId = null)
    {
    }

    public function handle(): void
    {
        if (! (bool) config('trading.reconcile.enabled', true)) {
            return;
        }

        $summary = app(SubmissionReconciler::class)->run($this->onlyBotId);

        if (($summary['examined'] ?? 0) > 0 || ($summary['skipped_young'] ?? 0) > 0) {
            Log::channel('trading')->info('RECONCILE_RUN_SUMMARY', $summary);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ReconcileSubmissionsJob failed: ' . $exception->getMessage());
    }
}
