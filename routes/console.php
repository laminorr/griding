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
    Schedule::job(new CheckTradesJob)
        ->name('check-trades')
        ->description('Check open orders and handle fills')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->onOneServer();

    Schedule::job(new AdjustGridJob)
        ->name('adjust-grid')
        ->description('Recalculate and adjust grid if needed')
        ->everyTenMinutes()
        ->withoutOverlapping(20)
        ->onOneServer();

    Schedule::command('queue:prune-batches --hours=48')
        ->name('queue-prune-batches')
        ->description('Prune old queue batches')
        ->dailyAt('03:20')
        ->onOneServer();

    // ---- Market stats heartbeat (BTCIRT/ETHIRT/USDTIRT) ----
    foreach (['BTCIRT','ETHIRT','USDTIRT'] as $s) {
        Schedule::job(new ReadMarketStatsJob($s))
            ->name("read-market-{$s}")
            ->description("Log last price & spread for {$s}")
            ->everyMinute()
            ->withoutOverlapping(2)
            ->onOneServer();
    }
}
