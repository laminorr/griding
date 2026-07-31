<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ScheduleCadence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see ScheduleCadence}, the pure seconds -> scheduler
 * frequency-method mapper that routes/console.php uses so a job's cadence
 * DERIVES from config('trading.scheduler.*') instead of a hard-coded literal
 * (Cleanup 6a, Fix 1).
 *
 * No framework dependency, so this extends PHPUnit's TestCase directly.
 */
final class ScheduleCadenceTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function mappableSeconds(): array
    {
        return [
            '60s  -> everyMinute'          => [60, 'everyMinute'],
            '120s -> everyTwoMinutes'      => [120, 'everyTwoMinutes'],
            '180s -> everyThreeMinutes'    => [180, 'everyThreeMinutes'],
            '300s -> everyFiveMinutes'     => [300, 'everyFiveMinutes'],
            '600s -> everyTenMinutes'      => [600, 'everyTenMinutes'],
            '900s -> everyFifteenMinutes'  => [900, 'everyFifteenMinutes'],
            '1800s -> everyThirtyMinutes'  => [1800, 'everyThirtyMinutes'],
            '3600s -> hourly'              => [3600, 'hourly'],
        ];
    }

    #[DataProvider('mappableSeconds')]
    public function test_maps_known_intervals_to_the_expected_helper(int $seconds, string $method): void
    {
        $this->assertSame($method, ScheduleCadence::methodForSeconds($seconds));
    }

    /**
     * The check-trades default (60s) is what makes the schedule fire every
     * minute — the whole point of Fix 1.
     */
    public function test_default_check_trades_interval_maps_to_every_minute(): void
    {
        $this->assertSame('everyMinute', ScheduleCadence::methodForSeconds(60));
    }

    /**
     * The adjust-grid default (600s) preserves the job's historical ten-minute
     * cadence, now sourced from config.
     */
    public function test_default_adjust_grid_interval_maps_to_every_ten_minutes(): void
    {
        $this->assertSame('everyTenMinutes', ScheduleCadence::methodForSeconds(600));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonMappableSeconds(): array
    {
        return [
            '90s'   => [90],
            '45s'   => [45],
            '240s'  => [240],
            '0s'    => [0],
            '-1s'   => [-1],
            '7200s' => [7200],
        ];
    }

    #[DataProvider('nonMappableSeconds')]
    public function test_returns_null_for_intervals_without_a_clean_helper(int $seconds): void
    {
        // Null is the contract that lets each call site pick its own fallback
        // (check-trades -> everyMinute, adjust-grid -> everyTenMinutes).
        $this->assertNull(ScheduleCadence::methodForSeconds($seconds));
    }
}
