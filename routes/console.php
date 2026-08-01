<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckTradesJob;
use App\Jobs\AdjustGridJob;
use App\Jobs\ReadMarketStatsJob;
use App\Jobs\ReconcileSubmissionsJob;
use App\Support\ScheduleCadence;
use App\Support\QueueDepthHealthCheck;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if ((bool) config('trading.enable_scheduler', true)) {

    // ---- Core trading jobs ----
    // Cadence now derives from config('trading.scheduler.*') (single source of
    // truth) instead of a hard-coded literal. interval_check_trades defaults to
    // 60s -> everyMinute(). A non-standard seconds value that ScheduleCadence
    // can't express as a Laravel helper falls back to everyMinute() (the
    // config's intent for this job). withoutOverlapping() below means a run that
    // outlives its minute is SKIPPED, never stacked.
    $checkTradesCadence = ScheduleCadence::methodForSeconds(
        (int) config('trading.scheduler.interval_check_trades', 60)
    ) ?? 'everyMinute';
    Schedule::job(new CheckTradesJob())
        ->{$checkTradesCadence}()
        ->withoutOverlapping()
        ->name('check-trades');
        // onOneServer() removed - not needed for single server setup

    // AdjustGridJob - now works with active BotConfig records only.
    // Cadence derives from config('trading.scheduler.interval_adjust_grid')
    // (defaults to 600s -> everyTenMinutes()). A non-mappable value falls back
    // to everyTenMinutes() (this job's historical cadence) rather than the
    // check-trades everyMinute() default.
    $adjustGridCadence = ScheduleCadence::methodForSeconds(
        (int) config('trading.scheduler.interval_adjust_grid', 600)
    ) ?? 'everyTenMinutes';
    Schedule::job(new AdjustGridJob())
        ->name('adjust-grid')
        ->description('Adjust grids for active bots only')
        ->{$adjustGridCadence}()
        ->withoutOverlapping(20)
        ->onOneServer();  // Requires CACHE_STORE=database or redis

    // Phase 12 Step 7 — resolve orders parked in submission_unknown (and
    // stale pending). Read-only against the exchange; safe alongside
    // CheckTradesJob (per-row locks). Same cadence as check-trades so a
    // parked level is unfrozen within minutes.
    Schedule::job(new ReconcileSubmissionsJob())
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->name('reconcile-submissions');

    Schedule::command('queue:prune-batches --hours=48')
        ->name('queue-prune-batches')
        ->description('Prune old queue batches')
        ->dailyAt('03:20');

    // ---- Market stats heartbeat (BTCIRT/ETHIRT/USDTIRT) ----
    // These run INLINE via Schedule::call (not Schedule::job) on purpose. Each
    // is a tiny fire-and-forget heartbeat that only reads an order book and
    // LOGS a line — nothing downstream consumes its result, and it carries no
    // retry/backoff design worth preserving. Queuing them used to push THREE
    // rows/minute into the `jobs` table (the bulk of the baseline enqueue
    // rate), which — against a worker that drained one job/minute — was the
    // largest single contributor to the 300k-row backlog incident. Running the
    // job's handle() through the container (so its MarketData dependency is
    // still injected) keeps the exact same behaviour while touching the queue
    // zero times. handle() already catches every Throwable internally, so a
    // slow/failed exchange read cannot break schedule:run. CheckTradesJob, by
    // contrast, keeps its queued + retry/backoff/timeout design (Schedule::job
    // above) — only the needless enqueues are removed here.
    foreach (['BTCIRT','ETHIRT','USDTIRT'] as $s) {
        Schedule::call(function () use ($s) {
            app()->call([new ReadMarketStatsJob($s), 'handle']);
        })
            ->name("read-market-{$s}")
            ->description("Log last price & spread for {$s}")
            ->everyMinute()
            ->withoutOverlapping(2);
    }

    // ---- Queue-depth health guard ----
    // Early-warning against the one-job-per-minute worker misconfiguration
    // silently ballooning the database queue again (the root cause of the
    // 300k-row backlog). Scheduled INLINE via Schedule::call so the guard never
    // adds a row to the very table it watches. Logs a CRITICAL to the `queue`
    // channel once DB::table('jobs')->count() exceeds the configured threshold
    // (default 500). Cheap COUNT(*), run every five minutes.
    Schedule::call(fn () => (new QueueDepthHealthCheck())->check())
        ->name('queue-depth-health-check')
        ->description('Alert if the jobs backlog exceeds the configured threshold')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}
