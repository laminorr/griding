<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * ثبت دستی کامندها (این یکی حتماً لازم است).
     *
     * @var array<class-string>
     */
    protected $commands = [
        \App\Console\Commands\GridRunOnce::class,
    ];

    /**
     * زمان‌بندی دستورات.
     * (طبق الگوی فعلی شما، خالی می‌ماند و زمان‌بندی‌ها در routes/console.php تعریف می‌شوند.)
     */
    protected function schedule(Schedule $schedule): void
    {
        // Intentionally left blank. Use routes/console.php for schedules.
    }

    /**
     * تایم‌زون زمان‌بندی.
     */
    protected function scheduleTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }

    /**
     * لود کردن کامندها و فایل routes/console.php
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
