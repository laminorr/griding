<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Maps a raw interval (in seconds, as stored in config('trading.scheduler.*'))
 * to the Laravel scheduler frequency method that expresses it exactly.
 *
 * WHY THIS EXISTS
 * ---------------
 * The scheduler in routes/console.php used to hard-code cadences with literals
 * like ->everyFiveMinutes() while config('trading.scheduler.interval_check_trades')
 * (and interval_adjust_grid) sat unread — a second, silently-diverging source of
 * truth. This helper lets the schedule site DERIVE its cadence from that config
 * value so there is a single source of truth, and keeps the mapping in one pure,
 * unit-testable place.
 *
 * Returns null for a seconds value that has no clean Laravel helper (e.g. 90s):
 * the caller decides the fallback, because "sensible fallback" differs per job
 * (check-trades falls back to everyMinute; adjust-grid to everyTenMinutes).
 */
class ScheduleCadence
{
    /**
     * @return string|null The Illuminate\Console\Scheduling\Event frequency
     *                     method name (e.g. 'everyMinute'), or null when the
     *                     interval isn't directly expressible as a helper.
     */
    public static function methodForSeconds(int $seconds): ?string
    {
        return match ($seconds) {
            60  => 'everyMinute',
            120 => 'everyTwoMinutes',
            180 => 'everyThreeMinutes',
            300 => 'everyFiveMinutes',
            600 => 'everyTenMinutes',
            900 => 'everyFifteenMinutes',
            1800 => 'everyThirtyMinutes',
            3600 => 'hourly',
            default => null,
        };
    }
}
