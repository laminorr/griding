<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Global toggles
    |--------------------------------------------------------------------------
    */
    'simulation_mode' => env('TRADING_SIMULATION_MODE', false),
    'enable_scheduler' => env('TRADING_ENABLE_SCHEDULER', true),

    'min_order_value_irt' => (int) (env('TRADING_MIN_ORDER_VALUE_IRT') ?: 3_000_000),

    /*
    |--------------------------------------------------------------------------
    | Market ticks (price step per symbol)
    |--------------------------------------------------------------------------
    */
    'ticks' => [
        'BTCIRT'  => (int) env('TICK_BTCIRT', 10),
        'ETHIRT'  => (int) env('TICK_ETHIRT', 10),
        'USDTIRT' => (int) env('TICK_USDTIRT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange / Market (Nobitex)
    |--------------------------------------------------------------------------
    */
    'exchange' => [
        'name' => 'nobitex',

        // DEPRECATED — no code reads this key any more. One rate for both legs
        // cannot express a BTC-charged buy fee plus a rial-charged sell fee.
        // Fee rates now live under 'fees' below and are only ever decided by
        // App\Services\FeeModel. Kept so an old .env does not break config:cache.
        'fee_bps' => (int) env('TRADING_EXCHANGE_FEE_BPS', 35),

        'slippage_bps' => (int) env('TRADING_SLIPPAGE_BPS', 10), // 0.10%

        'allowed_symbols' => array_values(
            array_filter(
                array_map('trim', explode(',', (string) env('TRADING_SYMBOLS_ALLOWED', 'BTCIRT,ETHIRT,USDTIRT')))
            )
        ),

        'precision' => [
            'BTCIRT'  => ['price_decimals' => 0, 'qty_decimals' => 8],
            'ETHIRT'  => ['price_decimals' => 0, 'qty_decimals' => 6],
            'LTCIRT'  => ['price_decimals' => 0, 'qty_decimals' => 6],
            'USDTIRT' => ['price_decimals' => 0, 'qty_decimals' => 2],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees (single source of truth: App\Services\FeeModel)
    |--------------------------------------------------------------------------
    | Nothing outside FeeModel may read these keys. See docs/fees.md.
    |
    | Rates are basis points (25 = 0.25%), decimal strings so fractional
    | tiers (e.g. 17.5) are expressible. Precedence for a bot:
    |   bot_configs.buy_fee_bps / sell_fee_bps (non-NULL)  →  these values.
    | bot_configs.fee_bps is legacy and is NOT read.
    */
    'fees' => [
        // Verified on the live account: a BUY is charged 0.25% in BASE (BTC).
        'buy_fee_bps'  => (string) env('TRADING_BUY_FEE_BPS', '25'),
        // Nobitex docs: a SELL is charged in QUOTE (rial). Rate unverified.
        'sell_fee_bps' => (string) env('TRADING_SELL_FEE_BPS', '25'),

        // Which asset each side's fee is EXPECTED to be charged in: 'base' | 'quote'.
        // The actual currency is detected from every real fill
        // (FeeModel::classifyActualFee); a mismatch logs FEE_CURRENCY_UNEXPECTED.
        'buy_fee_currency'  => env('TRADING_BUY_FEE_CURRENCY', 'base'),
        'sell_fee_currency' => env('TRADING_SELL_FEE_CURRENCY', 'quote'),

        // FEE_RATE_DRIFT is logged when an actual fill's effective rate differs
        // from the configured rate by more than this many bps.
        'drift_warn_bps' => (string) env('TRADING_FEE_DRIFT_WARN_BPS', '5'),

        // An actual fee whose effective rate is above this (in either currency
        // reading) is not classified at all (FEE_UNCLASSIFIABLE) — guards
        // against garbage payloads being booked as a fee.
        'classify_max_bps' => (string) env('TRADING_FEE_CLASSIFY_MAX_BPS', '100'),

        // Fractional digits an ESTIMATED base-currency fee is rounded UP to.
        // 10 matches the precision of the observed live BTC fees
        // (e.g. 0.0000005575); rounding up keeps a fee-sized sell from ever
        // exceeding the BTC actually credited.
        'fee_scale' => (int) env('TRADING_FEE_SCALE', 10),

        // Version stamped on completed_trades booked with this fee model
        // (0 = legacy single-rate bookings, see fees:backfill).
        'model_version' => 1,

        // Sell-first cycles: size the exit BUY at ceil_qty(sold / (1 − buyRate))
        // so the BTC inventory is restored after the BTC-denominated buy fee.
        // false → exit buy = sold (inventory shrinks by the buy fee each cycle;
        // the shortfall is recorded in bot_configs.base_dust).
        'restore_inventory_on_buy_exit' => (bool) env('TRADING_RESTORE_INVENTORY_ON_BUY_EXIT', true),

        // Exit-sell self-heal (App\Services\ExitRejectionHandler): on an
        // InsufficientBalance rejection, retry ONCE with floor_qty(free BTC)
        // only if free BTC >= this ratio of the intended amount; the shortfall
        // is recorded in base_dust. Otherwise the fill is marked exit_blocked.
        'self_heal_min_ratio' => (string) env('TRADING_EXIT_SELF_HEAL_MIN_RATIO', '0.98'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nobitex connection (REST + WebSocket)
    |--------------------------------------------------------------------------
    */
    'nobitex' => [
        'api_key'  => env('NOBITEX_API_KEY', ''),

        // Ed25519 request-signing credentials (replaces the legacy Token auth for
        // signed endpoints). PUBLIC key is sent verbatim in the Nobitex-Key
        // header; PRIVATE key is a url-safe base64 of a 32-byte Ed25519 SEED used
        // to sign each request. See App\Services\NobitexRequestSigner.
        'api_public_key'  => env('NOBITEX_API_PUBLIC_KEY', ''),
        'api_private_key' => env('NOBITEX_API_PRIVATE_KEY', ''),

        // User-Agent sent on every signed (authenticated) call.
        'signed_user_agent' => env('NOBITEX_SIGNED_UA', 'TraderBot/Griding-1.0'),

        'base_url' => env('NOBITEX_USE_TESTNET', false)
            ? env('NOBITEX_TESTNET_URL', 'https://testnetapiv2.nobitex.ir')
            : env('NOBITEX_BASE_URL', 'https://apiv2.nobitex.ir'),

        'websocket_url' => env('NOBITEX_WS_URL', env('WEBSOCKET_URL', 'wss://ws.nobitex.ir/connection/websocket')),

        // HTTP client
        'http' => [
            'timeout'         => (float) env('NOBITEX_HTTP_TIMEOUT', 10.0),
            'connect_timeout' => (float) env('NOBITEX_HTTP_CONNECT_TIMEOUT', 5.0),
            'user_agent'      => env('NOBITEX_HTTP_UA', 'TraderBot/GridBot_v1'),
        ],

        // Retry / Backoff
        'retry' => [
            'times'        => (int) env('NOBITEX_RETRY_MAX_ATTEMPTS', 3),
            'sleep'        => (int) env('NOBITEX_RETRY_SLEEP_MS', 200), // ms
            'initial_ms'   => (int) env('TRADING_BACKOFF_INITIAL_MS', 500),
            'max_ms'       => (int) env('TRADING_BACKOFF_MAX_MS', 4_000),
            'factor'       => (float) env('TRADING_BACKOFF_FACTOR', 2.0),
            'jitter_ms'    => (int) env('TRADING_BACKOFF_JITTER_MS', 250),
            // Single source of truth for status-based retry classification.
            // 408 (Request Timeout) is transient like the 5xx/429 set, so it
            // lives here rather than being appended in code.
            'http_statuses'=> [408, 429, 500, 502, 503, 504],
        ],

        // Rate limit (cache-backed fixed window; see App\Services\RateLimiting\CacheRateLimiter)
        'rate_limit' => [
            // Master switch. false (default) keeps the legacy soft per-route
            // attempt + 200ms nap in NobitexService::request() — zero behaviour
            // change. true swaps in the global blocking gate.
            'enforce'        => (bool) env('NOBITEX_RATE_LIMIT_ENFORCE', false),

            // Permits per window for the whole account (a single global key).
            'rpm'            => (int) env('NOBITEX_RATE_LIMIT_RPM', 60),

            // Fixed-window length in seconds. 60 makes `rpm` a literal
            // requests-per-minute budget; tests shrink it to keep windows tiny.
            'window_seconds' => (int) env('NOBITEX_RATE_LIMIT_WINDOW_SECONDS', 60),

            // Max time the gate will block for a permit before throwing
            // App\Exceptions\RateLimitExceededException instead of sending.
            'max_wait_ms'    => (int) env('NOBITEX_RATE_LIMIT_MAX_WAIT_MS', 10_000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Submission reconciler (Phase 12 Step 7)
    |--------------------------------------------------------------------------
    | Resolves grid_orders rows parked in 'submission_unknown' (and stale
    | 'pending') by read-only lookups against Nobitex. See
    | App\Services\SubmissionReconciler.
    */
    'reconcile' => [
        'enabled' => (bool) env('TRADING_RECONCILE_ENABLED', true),

        // Age guard: never touch a row younger than this — the process that
        // created it may still be mid-placement.
        'min_age_seconds'         => (int) env('TRADING_RECONCILE_MIN_AGE_SECONDS', 300),
        // 'pending' is also a normal transient state during a live placement,
        // so stuck-pending rows get a longer grace period before sweeping.
        'pending_min_age_seconds' => (int) env('TRADING_RECONCILE_PENDING_MIN_AGE_SECONDS', 900),

        // A row is only resolved to 'cancelled' after the exchange answered
        // NotFound for its clientOrderId on this many consecutive runs (with
        // no matching open order either time). Set cancel_on_not_found=false
        // to disable automatic cancellation entirely (rows then escalate to
        // manual review instead) — e.g. until the clientOrderId tagging has
        // been verified once against the live API.
        'not_found_confirmations' => (int) env('TRADING_RECONCILE_NOT_FOUND_CONFIRMATIONS', 2),
        'cancel_on_not_found'     => (bool) env('TRADING_RECONCILE_CANCEL_ON_NOT_FOUND', true),

        // Escalation: after this many attempts OR this age, the row is logged
        // at error level and surfaced on the bot's last_error_code health
        // surface (it keeps being retried, just loudly).
        'max_attempts'  => (int) env('TRADING_RECONCILE_MAX_ATTEMPTS', 12),
        'max_age_hours' => (int) env('TRADING_RECONCILE_MAX_AGE_HOURS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grid Strategy Defaults & Risk
    |--------------------------------------------------------------------------
    */
    'grid' => [
        'default_capital'           => (int) env('GRID_DEFAULT_CAPITAL', 100_000_000),
        'default_active_percent'    => (int) env('GRID_DEFAULT_ACTIVE_PERCENT', 30),
        'default_spacing_percent'   => (float) env('GRID_DEFAULT_SPACING', 1.5),
        'default_levels'            => (int) env('GRID_DEFAULT_LEVELS', 10),
        'max_active_percent'        => (int) env('GRID_MAX_ACTIVE_PERCENT', 80),
        'default_stop_loss_percent' => (float) env('GRID_DEFAULT_STOP_LOSS', 15),
        'max_drawdown_percent'      => (float) env('GRID_MAX_DRAWDOWN', 25),
        'simulation'                => (bool) env('TRADING_SIMULATION_MODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'price_ttl'        => (int) env('TRADING_PRICE_TTL_SECONDS', (int) env('PRICE_CACHE_DURATION', 30)),
        'market_stats_ttl' => (int) env('MARKET_STATS_CACHE_DURATION', 300),
        'balance_ttl'      => (int) env('TRADING_BALANCE_TTL_SECONDS', (int) env('BALANCE_CACHE_DURATION', 60)),
        'prefix'           => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_trading_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler-related
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'interval_check_trades' => (int) env('TRADING_INTERVAL_CHECK_TRADES', 60),
        'interval_adjust_grid'  => (int) env('TRADING_INTERVAL_ADJUST_GRID', 600),
        'align_to_minute'       => (bool) env('TRADING_SCHEDULER_ALIGN', true),
        'jitter'                => (int) env('TRADING_SCHEDULER_JITTER', 0),
        // QueueDepthHealthCheck fires a CRITICAL log once the `jobs` backlog
        // grows past this many rows — an early-warning guard against the
        // one-job-per-minute worker misconfiguration silently ballooning the
        // database queue again.
        'queue_depth_alert_threshold' => (int) env('TRADING_QUEUE_DEPTH_ALERT', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | AdjustGridJob rebalance
    |--------------------------------------------------------------------------
    | Dedicated (deliberately NARROWER) symbol whitelist for AdjustGridJob's
    | rebalance pass — it defaults to BTCIRT only, unlike exchange.allowed_symbols
    | (BTCIRT,ETHIRT,USDTIRT). env() is read HERE, inside the config file, which
    | is the correct place: under `php artisan config:cache` an env() call from
    | inside the job would return null and collapse the whitelist. Same env var
    | (TRADING_ALLOWED_SYMBOLS) and default the job used before, so behaviour is
    | unchanged — only the read site moved.
    */
    'adjust_grid' => [
        'allowed_symbols' => array_values(
            array_filter(
                array_map('trim', explode(',', (string) env('TRADING_ALLOWED_SYMBOLS', 'BTCIRT')))
            )
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket market-data feed (nobitex:ws-consumer)
    |--------------------------------------------------------------------------
    */
    'websocket' => [
        // Per-symbol minimum gap between orderbook/price cache writes. The
        // in-memory snapshot updates on every publication; a throttled symbol
        // is flushed on the next frame once this interval has elapsed.
        'cache_write_interval_ms' => (int) env('NOBITEX_WS_CACHE_WRITE_INTERVAL_MS', 1000),

        // Max wall time spent seeding orderbooks from REST after each connect
        // (the socket is already open and server pings need a reply in 20s).
        'seed_budget_seconds' => (int) env('NOBITEX_WS_SEED_BUDGET_SECONDS', 15),

        // Candle (OHLC) channels public:candle-{SYMBOL}-{RESOLUTION}, subscribed on
        // the same connection. Comma-separated in env. Each resolution must be in
        // NobitexService::OHLC_RESOLUTIONS; invalid entries are skipped + logged.
        // Latest candle is cached at mdl:candle:{SYMBOL}:{RESOLUTION} (TTL 300s),
        // throttled by cache_write_interval_ms per (symbol, resolution).
        'candle_symbols' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOBITEX_WS_CANDLE_SYMBOLS', 'BTCIRT'))), fn ($v) => $v !== '')),
        'candle_resolutions' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOBITEX_WS_CANDLE_RESOLUTIONS', '1,15,60,D'))), fn ($v) => $v !== '')),

        // W4: when true, ExchangeWsEventRecorder dispatches ProcessOrderEventJob
        // for actionable private:orders events (Done / Canceled / Inactive /
        // Active with a fill) matched to a grid order, so the order is re-checked
        // via REST right away instead of on the next CheckTradesJob minute. The
        // event is only a trigger; the minute poller stays on as the safety net.
        // false (default): events are recorded exactly as in W3, nothing is
        // dispatched. Roll back (no deploy): set NOBITEX_WS_ACT_ON_EVENTS=false,
        // run `php artisan config:clear`, then restart the long-running
        // nobitex:ws-private process (it read config at boot), e.g.
        // `pkill -f nobitex:ws-private` and let its keepalive cron restart it.
        'act_on_private_events' => (bool) env('NOBITEX_WS_ACT_ON_EVENTS', false),

        // WsFeedHealthCheck thresholds (log-only).
        'health' => [
            // No frame at all (not even a {} ping) for this long -> CRITICAL WS_FEED_DEAD
            'dead_after_seconds'   => (int) env('NOBITEX_WS_DEAD_AFTER_SECONDS', 180),
            // Frames arriving but no orderbook publication for this long -> WARNING WS_FEED_SILENT
            'silent_after_seconds' => (int) env('NOBITEX_WS_SILENT_AFTER_SECONDS', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags & Logging
    |--------------------------------------------------------------------------
    */
    'flags' => [
        'log_api_requests'        => (bool) env('LOG_API_REQUESTS', true),
        'log_trading_operations'  => (bool) env('LOG_TRADING_OPERATIONS', true),
        'performance_monitoring'  => (bool) env('ENABLE_PERFORMANCE_MONITORING', true),
        'websocket_enabled'       => (bool) env('WEBSOCKET_ENABLED', true),
        'queue_retry_enabled'     => (bool) env('QUEUE_RETRY_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security / Admin
    |--------------------------------------------------------------------------
    */
    'security' => [
        'admin_ip_whitelist' => array_values(
            array_filter(
                array_map('trim', explode(',', (string) env('ADMIN_IP_WHITELIST', '127.0.0.1,::1')))
            )
        ),
        'twofa'                 => (bool) env('ADMIN_2FA_ENABLED', false),
        'export_encryption_key' => env('EXPORT_ENCRYPTION_KEY'),
    ],
];
