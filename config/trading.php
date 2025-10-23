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

        'fee_bps' => (int) env('TRADING_EXCHANGE_FEE_BPS', 35), // 0.35%
        'fee_rate_percent' => ((int) env('TRADING_EXCHANGE_FEE_BPS', 35)) / 100.0,

        'slippage_bps' => (int) env('TRADING_SLIPPAGE_BPS', 10), // 0.10%
        'min_order_value_irt' => (int) env('TRADING_MIN_ORDER_VALUE_IRT', 3_000_000),

        'allowed_symbols' => array_values(
            array_filter(
                array_map('trim', explode(',', (string) env('TRADING_SYMBOLS_ALLOWED', 'BTCIRT,ETHIRT,USDTIRT')))
            )
        ),

        'precision' => [
            'BTCIRT'  => ['price_decimals' => 0, 'qty_decimals' => 8],
            'ETHIRT'  => ['price_decimals' => 0, 'qty_decimals' => 6],
            'USDTIRT' => ['price_decimals' => 0, 'qty_decimals' => 2],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nobitex connection (REST + WebSocket)
    |--------------------------------------------------------------------------
    */
    'nobitex' => [
        'api_key'  => env('NOBITEX_API_KEY', ''),

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
            'http_statuses'=> [429, 500, 502, 503, 504],
        ],

        // Rate limit (token bucket)
        'rate_limit' => [
            'rpm'            => (int) env('NOBITEX_RATE_LIMIT_RPM', 60),
            'tokens'         => (int) env('TRADING_RATE_LIMIT_TOKENS', 1),
            'window_seconds' => (int) env('TRADING_RATE_LIMIT_WINDOW_SECONDS', 2),
        ],

        // ************ NEW: Auth (login + 30-day token + auto-refresh) ************
        'auth' => [
            'username'     => env('NOBITEX_USERNAME'),            // ایمیل لاگین
            'password'     => env('NOBITEX_PASSWORD'),            // پسورد
            'remember'     => (string) env('NOBITEX_REMEMBER', 'yes') === 'yes', // 30 روزه
            'totp_secret'  => env('NOBITEX_TOTP_SECRET'),         // اختیاری (2FA)
            'login_path'   => env('NOBITEX_AUTH_LOGIN_PATH', '/auth/login/'),
            'auto_refresh' => (bool) env('NOBITEX_AUTO_REFRESH_TOKEN', true),    // روی 401 رفرش کن
            'cache_key'    => env('NOBITEX_TOKEN_CACHE_KEY', 'nobitex:api_token'),
        ],
        // **************************************************************************
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
