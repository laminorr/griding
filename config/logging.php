<?php

/*
| LOG_FILE_OVERRIDE — when set (phpunit.xml pins it to "testing.log"), EVERY
| file channel below writes to storage/logs/<that file> instead of its own
| production file, so a test run can never append to laravel/trading/nobitex/
| queue/scheduler logs. Unset in production: each channel keeps its own file.
*/
$logFile = static function (string $default): string {
    $override = trim((string) env('LOG_FILE_OVERRIDE', ''));

    return storage_path('logs/' . ($override !== '' ? basename($override) : $default));
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    | کانال پیش‌فرض لاگ‌ها.
    */
    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    | هشدارهای deprecation به این کانال می‌روند.
    */
    'deprecations' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    | تعریف کانال‌های پروژه.
    */
    'channels' => [

        /*
        |-------------------- کانال تجمیعی --------------------
        | با کاما در .env تعیین کن: LOG_STACK=laravel,trading,nobitex,queue,scheduler
        */
        'stack' => [
            'driver' => 'stack',
            'channels' => array_map('trim', explode(',', env('LOG_STACK', 'laravel'))),
            'ignore_exceptions' => false,
        ],

        /*
        |-------------------- لاگ اصلی (Laravel) --------------------
        */
        'laravel' => [
            'driver' => 'daily',
            'path' => $logFile('laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => (int) env('LOG_DAYS', 14),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        /*
        |-------------------- کانال ترید --------------------
        */
        'trading' => [
            'driver' => 'daily',
            'path' => $logFile('trading.log'),
            'level' => env('LOG_TRADING_LEVEL', 'info'),
            'days' => (int) env('LOG_TRADING_DAYS', 30),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        /*
        |-------------------- کانال Nobitex --------------------
        */
        'nobitex' => [
            'driver' => 'daily',
            'path' => $logFile('nobitex.log'),
            'level' => env('LOG_NOBITEX_LEVEL', 'warning'),
            'days' => (int) env('LOG_NOBITEX_DAYS', 30),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        /*
        |-------------------- کانال Queue --------------------
        */
        'queue' => [
            'driver' => 'daily',
            'path' => $logFile('queue.log'),
            'level' => env('LOG_QUEUE_LEVEL', 'info'),
            'days' => (int) env('LOG_QUEUE_DAYS', 14),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        /*
        |-------------------- کانال Scheduler --------------------
        */
        'scheduler' => [
            'driver' => 'daily',
            'path' => $logFile('scheduler.log'),
            'level' => env('LOG_SCHEDULER_LEVEL', 'info'),
            'days' => (int) env('LOG_SCHEDULER_DAYS', 14),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        /*
        |-------------------- کانال‌های پیش‌فرض دیگر --------------------
        */
        'single' => [
            'driver' => 'single',
            'path' => $logFile('laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'stderr',
            'level' => env('LOG_LEVEL', 'debug'),
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'tap' => [App\Logging\CustomizeFormatter::class],
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

        'emergency' => [
            'path' => $logFile('laravel.log'),
        ],
    ],
];
