<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckTradesJob;
use App\Jobs\AdjustGridJob;
use App\Jobs\ReadMarketStatsJob; 

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if ((bool) config('trading.enable_scheduler', true)) {

    // ---- Core trading jobs ----
    Schedule::job(new CheckTradesJob())
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->name('check-trades');
        // onOneServer() removed - not needed for single server setup

    // AdjustGridJob - now works with active BotConfig records only
    Schedule::job(new AdjustGridJob())
        ->name('adjust-grid')
        ->description('Adjust grids for active bots only')
        ->everyTenMinutes()
        ->withoutOverlapping(20)
        ->onOneServer();  // Requires CACHE_STORE=database or redis

    Schedule::command('queue:prune-batches --hours=48')
        ->name('queue-prune-batches')
        ->description('Prune old queue batches')
        ->dailyAt('03:20');

    // ---- Market stats heartbeat (BTCIRT/ETHIRT/USDTIRT) ----
    foreach (['BTCIRT','ETHIRT','USDTIRT'] as $s) {
        Schedule::job(new ReadMarketStatsJob($s))
            ->name("read-market-{$s}")
            ->description("Log last price & spread for {$s}")
            ->everyMinute()
            ->withoutOverlapping(2);
    }
}
