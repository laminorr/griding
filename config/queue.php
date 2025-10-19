<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    | اتصال پیش‌فرض صف. برای پروژه ما روی "database" باقی می‌ماند.
    */
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    | می‌توانید چند اتصال داشته باشید. ما از اتصال "database" استفاده می‌کنیم.
    */
    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            // نام جدول jobs همان پیش‌فرض لاراول است
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('QUEUE_NAME', 'default'),
            // زمان انتظار قبل از تلاش مجدد (ثانیه)
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            // اجرای job پس از commit تراکنش ها
            'after_commit' => false,
            // گزینهٔ مسدودسازی (برای درایورهای پشتیبانی‌شده) — در DB اهمیتی ندارد
            'block_for' => null,
        ],

        // اتصال های دیگر برای سازگاری — استفاده نمی‌کنیم
        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_HOST', 'localhost'),
            'queue' => env('QUEUE_NAME', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('QUEUE_NAME', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    | تنظیمات جدول job_batches برای مدیریت بچ‌ها.
    */
    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => env('DB_JOB_BATCHES_TABLE', 'job_batches'),
        'cache' => env('CACHE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    | تنظیمات ذخیرهٔ jobهای fail شده.
    */
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => env('DB_FAILED_JOBS_TABLE', 'failed_jobs'),
    ],
];
