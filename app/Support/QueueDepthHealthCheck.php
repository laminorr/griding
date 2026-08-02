<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QueueDepthHealthCheck
 * ------------------------------------------------------------------------
 * A tiny scheduled guard that catches a runaway `jobs` table BEFORE it turns
 * into the production incident that motivated it: schedule:run enqueues several
 * jobs per minute, and if the worker ever drains slower than that (the classic
 * `queue:work --once` = one-job-per-minute misconfiguration) the database queue
 * grows without bound and the critical CheckTradesJob effectively stops running
 * on time. We once found 301,943 jobs backed up this way.
 *
 * It is deliberately dependency-light and reads the queue depth directly from
 * the database so it works even while a backlog is choking the worker. It is
 * scheduled INLINE via Schedule::call() in routes/console.php so the guard
 * itself never adds a row to the very table it is watching.
 *
 * Only the `database` queue driver keeps its backlog in a SQL table, so the
 * check is a no-op for any other driver (sync/redis/sqs) — there is nothing to
 * COUNT() and nothing this guard could see.
 */
final class QueueDepthHealthCheck
{
    /**
     * Run the check. Returns the observed queue depth, or null when the check
     * did not apply (non-database queue driver) or could not run (DB error —
     * swallowed so a scheduler tick is never brought down by the guard).
     */
    public function check(): ?int
    {
        $default = (string) config('queue.default');

        // Only the database driver stores its backlog in a table we can count.
        if (config("queue.connections.{$default}.driver") !== 'database') {
            return null;
        }

        $threshold = self::threshold();
        $table = (string) config("queue.connections.{$default}.table", 'jobs');
        $connection = config("queue.connections.{$default}.connection"); // null = default

        try {
            $depth = (int) DB::connection($connection)->table($table)->count();
        } catch (Throwable $e) {
            // A guard must never take down the scheduler tick it rides on.
            Log::channel('queue')->warning('QUEUE_DEPTH_CHECK_FAILED', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($depth > $threshold) {
            Log::channel('queue')->critical('QUEUE_DEPTH_CRITICAL', [
                'depth'     => $depth,
                'threshold' => $threshold,
                'table'     => $table,
                'hint'      => 'Worker is not draining the queue fast enough. '
                    . 'Confirm a persistent/stop-when-empty queue:work runs each '
                    . 'minute (see routes/console.php header + crontab).',
            ]);
        }

        return $depth;
    }

    /**
     * Alert threshold for the `jobs` backlog. Configurable so the value is not
     * buried in code; defaults to 500 — comfortably above the handful of jobs a
     * healthy per-minute drain leaves transiently in flight, low enough to fire
     * long before a real backlog becomes an incident.
     */
    public static function threshold(): int
    {
        return (int) config('trading.scheduler.queue_depth_alert_threshold', 500);
    }
}
