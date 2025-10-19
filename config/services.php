<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third‑party Mail Services
    |--------------------------------------------------------------------------
    */
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime / Notifications (اختیاری)
    |--------------------------------------------------------------------------
    */
    'pusher' => [
        'app_id' => env('PUSHER_APP_ID'),
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'options' => [
            'cluster' => env('PUSHER_APP_CLUSTER'),
            'useTLS' => true,
        ],
    ],

    'slack' => [
        // برای ارسال اعلان (در سرویس‌های داخلی خودت استفاده می‌شود)
        'token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        'default_channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nobitex (هم‌راستا با config/trading.php)
    |--------------------------------------------------------------------------
    */
    'nobitex' => [
        'api_key' => env('NOBITEX_API_KEY', ''),
        'base_url' => env('NOBITEX_USE_TESTNET', false)
            ? env('NOBITEX_TESTNET_URL', 'https://testnetapiv2.nobitex.ir')
            : env('NOBITEX_BASE_URL', 'https://apiv2.nobitex.ir'),
        'websocket_url' => env('WEBSOCKET_URL', 'wss://wss.nobitex.ir/connection/websocket'),

        // HTTP
        'http' => [
            'timeout' => (float) env('NOBITEX_HTTP_TIMEOUT', 8.0),
            'connect_timeout' => (float) env('NOBITEX_HTTP_CONNECT_TIMEOUT', 5.0),
            'headers' => [ 'Accept' => 'application/json' ],
        ],

        // Retry / Backoff
        'retry' => [
            'max_attempts' => (int) env('NOBITEX_RETRY_MAX_ATTEMPTS', 3),
            'initial_ms' => (int) env('TRADING_BACKOFF_INITIAL_MS', 500),
            'max_ms' => (int) env('TRADING_BACKOFF_MAX_MS', 4_000),
            'factor' => (float) env('TRADING_BACKOFF_FACTOR', 2.0),
            'jitter_ms' => (int) env('TRADING_BACKOFF_JITTER_MS', 250),
            'http_statuses' => [429, 500, 502, 503, 504],
        ],

        // Rate limit ساده (Token Bucket داخل اپ)
        'rate_limit' => [
            'tokens' => (int) env('TRADING_RATE_LIMIT_TOKENS', 1),
            'window_seconds' => (int) env('TRADING_RATE_LIMIT_WINDOW_SECONDS', 2),
        ],
    ],
];
