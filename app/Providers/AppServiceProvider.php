<?php
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\ExchangeClient;
use App\Services\NobitexService;
use App\Contracts\MarketData;
use App\Services\MarketDataLayer;
use App\Contracts\RateLimiter;
use App\Services\RateLimiting\CacheRateLimiter;
use App\Models\GridOrder;
use App\Observers\GridOrderObserver;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // Enforcing rate limiter (used by NobitexService only when
        // trading.nobitex.rate_limit.enforce is true). Bound fresh per resolve
        // so it reads the current rpm/window_seconds config each time.
        $this->app->bind(RateLimiter::class, CacheRateLimiter::class);
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

        $this->registerPersianDigits();

        if ($this->app->runningInConsole()) {
            $this->validateCacheDriverForOnOneServer();
        }
    }

    /**
     * Persian-digit display helper (P4-final).
     *
     * The panel is a Persian RTL UI, so human-facing numbers should read as
     * ۰-۹ rather than 0-9. This is a DISPLAY-ONLY transform — it rewrites the
     * ASCII digit glyphs of an already-formatted string and touches nothing
     * else (thousands separators, signs, units and any Latin text pass
     * through untouched), so no metric or calculation is affected.
     *
     *   Str::faDigits('۱۰۰,۰۰۰')  → used in Filament column formatters / widgets
     *   @fa($value)               → used inside Blade views
     *
     * Machine-readable strings (API URLs, copyable IDs) are intentionally left
     * Latin at the call sites, per the design intent.
     */
    private function registerPersianDigits(): void
    {
        Str::macro('faDigits', function ($value): string {
            return strtr((string) $value, [
                '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ]);
        });

        Blade::directive('fa', fn (string $expression): string =>
            "<?php echo \\Illuminate\\Support\\Str::faDigits($expression); ?>"
        );
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
