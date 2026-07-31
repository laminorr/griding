<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\CompletedTrade;
use Tests\TestCase;

/**
 * Cleanup Phase 3 — Change 2: characterization tests for
 * {@see CompletedTrade::getExecutionTimeFormattedAttribute()}, the display
 * accessor for execution_time_seconds. Step 7 made that column non-negative;
 * the accessor that renders it was never tested.
 *
 * DISCIPLINE: these lock the ACTUAL output. The accessor is a pure function of
 * the integer-cast `execution_time_seconds` attribute — no DB round-trip, no
 * clock, no cache — so each case sets the attribute in memory and pins the
 * exact Persian string emitted. The format strings are copied verbatim from the
 * accessor:
 *   - minutes present → "{m} دقیقه، {s} ثانیه"
 *   - minutes zero    → "{s} ثانیه"
 * If the formatting looks odd it is locked and noted, not "fixed".
 */
final class CompletedTradeExecutionTimeFormatTest extends TestCase
{
    /** Build an unsaved model carrying just the duration the accessor reads. */
    private function withSeconds(int $seconds): CompletedTrade
    {
        $trade = new CompletedTrade();
        $trade->execution_time_seconds = $seconds;

        return $trade;
    }

    /** Sub-minute: minutes==0, so only the seconds clause renders. */
    public function test_sub_minute_duration_renders_seconds_only(): void
    {
        $trade = $this->withSeconds(45);

        // 45 / 60 → 0 minutes, 45 % 60 → 45 seconds.
        $this->assertSame('45 ثانیه', $trade->execution_time_formatted);
    }

    /**
     * Exact minutes: seconds==0 but the seconds clause is ALWAYS appended.
     *
     * CHARACTERIZATION: the accessor never drops a zero-seconds tail, so a clean
     * two-minute duration reads "2 دقیقه، 0 ثانیه" rather than "2 دقیقه". Odd but
     * locked, not changed.
     */
    public function test_exact_minutes_still_appends_zero_seconds(): void
    {
        $trade = $this->withSeconds(120);

        // 120 / 60 → 2 minutes, 120 % 60 → 0 seconds.
        $this->assertSame('2 دقیقه، 0 ثانیه', $trade->execution_time_formatted);
    }

    /** Minutes-and-seconds: both clauses populated. */
    public function test_minutes_and_seconds_duration_renders_both(): void
    {
        $trade = $this->withSeconds(150);

        // 150 / 60 → 2 minutes, 150 % 60 → 30 seconds.
        $this->assertSame('2 دقیقه، 30 ثانیه', $trade->execution_time_formatted);
    }

    /**
     * Zero seconds (same-second fill, the exact-0 duration Step 7 can now produce).
     *
     * CHARACTERIZATION: the guard is `if (!$execution_time_seconds)`, and 0 is
     * falsy, so a zero duration returns NULL — not an empty string, not "0 ثانیه",
     * and never a negative. Null renders sanely in Blade (nothing shown). Locked,
     * not changed. A genuinely null column (never populated) takes the same
     * branch and is asserted alongside to pin that 0 and null are indistinguishable
     * here.
     */
    public function test_zero_seconds_renders_null_not_empty_or_negative(): void
    {
        $trade = $this->withSeconds(0);

        $this->assertNull($trade->execution_time_formatted);

        // A never-populated (null) duration hits the same falsy guard.
        $unset = new CompletedTrade();
        $this->assertNull($unset->execution_time_formatted);
    }
}
