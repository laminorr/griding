<?php

declare(strict_types=1);

namespace Tests\Unit\RateLimiting;

use App\Exceptions\RateLimitExceededException;
use App\Services\RateLimiting\CacheRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 12 Step 3 — unit tests for the enforcing limiter itself.
 *
 * These drive App\Services\RateLimiting\CacheRateLimiter directly against the
 * `array` cache store (phpunit.xml pins CACHE_STORE=array). No HTTP, no service
 * wiring — just the fixed-window primitive: N permits per window, the (N+1)th
 * blocks, and block() throws RateLimitExceededException once max_wait elapses.
 *
 * Real sleeps are kept sub-100ms: the block() timeout tests use tiny max_wait
 * values, and window-reset is exercised via Carbon time travel (the array store
 * honours Carbon::now() for TTL expiry) rather than a real multi-second wait.
 */
final class CacheRateLimiterTest extends TestCase
{
    private const KEY = 'global';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** N permits are granted within a window; the (N+1)th acquire() fails. */
    public function test_permits_are_granted_up_to_capacity_then_denied(): void
    {
        $limiter = new CacheRateLimiter(capacity: 3, windowSeconds: 60);

        $this->assertTrue($limiter->acquire(self::KEY));
        $this->assertTrue($limiter->acquire(self::KEY));
        $this->assertTrue($limiter->acquire(self::KEY));

        // Budget exhausted → the 4th call is denied and consumes nothing.
        $this->assertFalse($limiter->acquire(self::KEY));
        $this->assertSame(0, $limiter->available(self::KEY));
    }

    /** available() reports the remaining budget as it is spent. */
    public function test_available_tracks_remaining_budget(): void
    {
        $limiter = new CacheRateLimiter(capacity: 2, windowSeconds: 60);

        $this->assertSame(2, $limiter->available(self::KEY));
        $limiter->acquire(self::KEY);
        $this->assertSame(1, $limiter->available(self::KEY));
        $limiter->acquire(self::KEY);
        $this->assertSame(0, $limiter->available(self::KEY));
    }

    /** reserve() is 0 while permits remain and > 0 (ms) once exhausted. */
    public function test_reserve_reports_zero_then_wait_when_exhausted(): void
    {
        $limiter = new CacheRateLimiter(capacity: 1, windowSeconds: 60);

        $this->assertSame(0, $limiter->reserve(self::KEY));
        $this->assertTrue($limiter->acquire(self::KEY));

        // Exhausted: reserve reports a positive wait until the window resets.
        $this->assertGreaterThan(0, $limiter->reserve(self::KEY));
    }

    /** block() returns immediately while a permit is available (no throw). */
    public function test_block_returns_when_permit_available(): void
    {
        $limiter = new CacheRateLimiter(capacity: 1, windowSeconds: 60);

        $limiter->block(self::KEY, 1_000);

        // The permit was consumed; a second block with a tiny wait must throw.
        $this->assertSame(0, $limiter->available(self::KEY));
    }

    /**
     * block() on an exhausted budget throws RateLimitExceededException once the
     * (tiny) max_wait elapses, WITHOUT ever granting a permit.
     */
    public function test_block_throws_when_max_wait_elapses(): void
    {
        $limiter = new CacheRateLimiter(capacity: 1, windowSeconds: 60);

        $this->assertTrue($limiter->acquire(self::KEY)); // exhaust

        $this->expectException(RateLimitExceededException::class);
        $limiter->block(self::KEY, 40); // 40ms budget, window is 60s → must throw
    }

    /** The thrown exception carries the key and the max-wait it gave up after. */
    public function test_exception_carries_key_and_wait(): void
    {
        $limiter = new CacheRateLimiter(capacity: 1, windowSeconds: 60);
        $limiter->acquire(self::KEY);

        try {
            $limiter->block(self::KEY, 30);
            $this->fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame(self::KEY, $e->limiterKey);
            $this->assertSame(30, $e->maxWaitMs);
        }
    }

    /**
     * After the fixed window elapses the budget refills. Time is advanced with
     * Carbon travel (the array cache store expires entries against Carbon::now)
     * so no real multi-second sleep is needed.
     */
    public function test_window_resets_after_elapsing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));

        $limiter = new CacheRateLimiter(capacity: 1, windowSeconds: 5);

        $this->assertTrue($limiter->acquire(self::KEY));
        $this->assertFalse($limiter->acquire(self::KEY)); // exhausted within window

        // Jump past the window boundary; the counter entry has now expired.
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:06'));

        $this->assertTrue($limiter->acquire(self::KEY), 'Budget must refill after the window elapses.');
    }
}
