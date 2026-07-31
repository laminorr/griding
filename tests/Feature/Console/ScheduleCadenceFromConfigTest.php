<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

/**
 * Cleanup 6a, Fix 1: routes/console.php now derives the CheckTradesJob and
 * AdjustGridJob cadences from config('trading.scheduler.*') (single source of
 * truth) instead of hard-coded literals. These tests rebuild the schedule from
 * the real routes/console.php against a fresh Schedule instance, so a change to
 * the config value is reflected in the registered cron expression.
 */
final class ScheduleCadenceFromConfigTest extends TestCase
{
    /**
     * Bind a fresh Schedule and (re-)load the real routes/console.php against it,
     * so the Schedule facade calls inside register onto our instance and we can
     * inspect the resulting events.
     */
    private function buildSchedule(): Schedule
    {
        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        // The Schedule facade caches its resolved instance separately from the
        // container, so clear it or the require below registers onto the stale
        // singleton built at boot.
        ScheduleFacade::clearResolvedInstances();

        // enable_scheduler gates the whole block; make sure it's on for the test.
        config(['trading.enable_scheduler' => true]);

        require base_path('routes/console.php');

        return $schedule;
    }

    private function eventByDescription(Schedule $schedule, string $description): Event
    {
        foreach ($schedule->events() as $event) {
            if (($event->description ?? null) === $description) {
                return $event;
            }
        }

        $this->fail("No scheduled event with description [{$description}] was found.");
    }

    public function test_check_trades_defaults_to_every_minute_from_config(): void
    {
        // Default interval_check_trades is 60s -> everyMinute() -> '* * * * *'.
        config(['trading.scheduler.interval_check_trades' => 60]);

        $event = $this->eventByDescription($this->buildSchedule(), 'check-trades');

        $this->assertSame('* * * * *', $event->getExpression());
    }

    public function test_check_trades_cadence_follows_a_changed_config_value(): void
    {
        // 300s must map to everyFiveMinutes(), proving the cadence is derived
        // from config and not a hard-coded literal.
        config(['trading.scheduler.interval_check_trades' => 300]);

        $event = $this->eventByDescription($this->buildSchedule(), 'check-trades');

        $this->assertSame('*/5 * * * *', $event->getExpression());
    }

    public function test_check_trades_keeps_the_without_overlapping_guard(): void
    {
        // The everyMinute() cadence runs check-trades 5x more often than before,
        // so the skip-don't-stack guard must remain in place.
        $event = $this->eventByDescription($this->buildSchedule(), 'check-trades');

        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_adjust_grid_defaults_to_every_ten_minutes_from_config(): void
    {
        // Default interval_adjust_grid is 600s -> everyTenMinutes() -> '*/10 * * * *'.
        // The event's description is overridden after ->name('adjust-grid'), so it
        // is found by its final description string.
        config(['trading.scheduler.interval_adjust_grid' => 600]);

        $event = $this->eventByDescription($this->buildSchedule(), 'Adjust grids for active bots only');

        $this->assertSame('*/10 * * * *', $event->getExpression());
    }

    public function test_adjust_grid_cadence_follows_a_changed_config_value(): void
    {
        config(['trading.scheduler.interval_adjust_grid' => 300]);

        $event = $this->eventByDescription($this->buildSchedule(), 'Adjust grids for active bots only');

        $this->assertSame('*/5 * * * *', $event->getExpression());
    }
}
