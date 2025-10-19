<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    | این مقدار مشخص می‌کند کدام اتصال دیتابیس به صورت پیش‌فرض استفاده شود.
    | برای پروژه‌ی ما روی MySQL می‌ماند مگر در .env چیز دیگری بگذاری.
    */
    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    | همه‌ی اتصالاتی که ممکن است استفاده کنی. پروژه ما "mysql" را پیش‌فرض دارد.
    | بقیه اتصال‌ها صرفاً برای سازگاری/تست هستند.
    */
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),

            // کاراکترست/کالیشن پیشنهادی برای MySQL 8
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),

            'prefix' => env('DB_PREFIX', ''),
            'prefix_indexes' => true,

            // حالت strict بهتره true باشه تا ناسازگاری‌ها زودتر پیدا بشن
            'strict' => true,

            // اگر نیاز داری ENGINE خاصی ست کنی (مثلاً InnoDB):
            'engine' => env('DB_ENGINE', null),

            // گزینه‌های PDO — فقط اگر اکستنشن pdo_mysql لود شده
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // SSL CA اگر لازم بود
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                // تایم‌اوت اتصال (ثانیه)
                PDO::ATTR_TIMEOUT => (int) env('DB_TIMEOUT', 10),
            ]) : [],

            // تنظیمات dump (برای بکاپ/اکسپورت اختیاری)
            'dump' => [
                'dump_binary_path' => env('DB_DUMP_BINARY_PATH', '/usr/bin'),
                'use_single_transaction' => true,
                'timeout' => (int) env('DB_DUMP_TIMEOUT', 60),
            ],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    | نام جدول مهاجرت‌ها. مقدار پیش‌فرض لاراول استفاده می‌شود.
    */
    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases (اختیاری و «بدون استفاده» در این پروژه)
    |--------------------------------------------------------------------------
    | ما Redis را در این پروژه استفاده نمی‌کنیم اما بلوک زیر را برای سازگاری
    | باقی می‌گذاریم تا اگر پکیجی به آن ارجاع کرد، خطا ندهد.
    */
'redis' => [
    // طبق راهنمای cPanel: predis + اتصال یونیکس
    'client' => env('REDIS_CLIENT', 'predis'),

    'options' => [
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_'),
    ],

    // کانکشن پیش‌فرض
    'default' => [
        'scheme'   => env('REDIS_SCHEME', 'unix'),
        'path'     => env('REDIS_PATH', '/home/savesir/redis/redis.sock'),
        'database' => (int) env('REDIS_DB', 0),
    ],

    // کانکشن cache جداگانه (برای استور market)
    'cache' => [
        'scheme'   => env('REDIS_SCHEME', 'unix'),
        'path'     => env('REDIS_PATH', '/home/savesir/redis/redis.sock'),
        'database' => (int) env('REDIS_CACHE_DB', 1),
    ],
],

];
