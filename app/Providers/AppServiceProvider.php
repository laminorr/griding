<?php
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\ExchangeClient;
use App\Services\NobitexService;
use App\Contracts\MarketData;
use App\Services\MarketDataLayer;

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
        //
    }
}
