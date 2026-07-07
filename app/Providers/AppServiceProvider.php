<?php
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\ExchangeClient;
use App\Services\NobitexService;
use App\Contracts\MarketData;
use App\Services\MarketDataLayer;
use App\Models\GridOrder;
use App\Observers\GridOrderObserver;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Exchange REST client
        $this->app->bind(ExchangeClient::class, NobitexService::class);

        // Market data source (WS-first, REST-fallback)
        $this->app->bind(MarketData::class, MarketDataLayer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 11, Step 2 — populate the read-only inventory tracking columns
        // (open_cycles_count, capital_locked_irt) on bot_configs whenever a
        // GridOrder changes state. Observation only; nothing consumes these
        // values for decisions yet.
        GridOrder::observe(GridOrderObserver::class);

        if ($this->app->runningInConsole()) {
            $this->validateCacheDriverForOnOneServer();
        }
    }

    /**
     * The scheduler's onOneServer() guarantee (used by AdjustGridJob) relies on
     * Cache::lock() being atomic across processes/servers, which only the
     * 'database' and 'redis' cache drivers provide. 'file'/'array' drivers
     * would silently let the job run on every server.
     */
    private function validateCacheDriverForOnOneServer(): void
    {
        $driver = config('cache.default');

        if (!in_array($driver, ['database', 'redis'], true)) {
            Log::critical(
                "CACHE driver '{$driver}' does not support atomic locks required by ".
                "Schedule::onOneServer() (used for AdjustGridJob). Set CACHE_STORE/CACHE_DRIVER ".
                "to 'database' or 'redis', otherwise the job may run concurrently on multiple servers."
            );
        }
    }
}
