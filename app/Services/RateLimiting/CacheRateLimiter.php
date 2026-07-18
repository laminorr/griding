<?php

declare(strict_types=1);

namespace App\Services\RateLimiting;

use App\Contracts\RateLimiter as RateLimiterContract;
use App\Exceptions\RateLimitExceededException;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;

/**
 * Cache-backed fixed-window rate limiter.
 *
 * Backed by Laravel's RateLimiter facade, which stores its hit counter and a
 * per-window timer in the default cache store. In production that store is the
 * `database` driver, so the budget is shared across every process that touches
 * the same cache — queue workers, the scheduler, and web — which is exactly the
 * granularity a per-ACCOUNT Nobitex limit needs. A single global key
 * (`nobitex:global`) is used by the caller, NOT a per-route key, so the whole
 * account shares one budget.
 *
 * ── Algorithm: fixed window ────────────────────────────────────────────────
 * `capacity` permits are allowed per `windowSeconds` window (default 60s, i.e.
 * a literal "requests per minute" when capacity = rpm). The counter resets when
 * the window's cache entry expires.
 *
 * ── Documented imprecision (honest, by design) ─────────────────────────────
 *  1. Window-boundary bursts. Because the window is fixed (not sliding), up to
 *     `capacity` permits can be spent at the very end of one window and another
 *     `capacity` at the very start of the next — up to 2×capacity within a span
 *     shorter than one window. A sliding window or token bucket would smooth
 *     this; we accept the burst to keep the primitive trivial.
 *  2. Check-then-hit race. acquire() reads the counter (tooManyAttempts) and
 *     then increments it (hit) in two steps, not one atomic compare-and-add.
 *     Under heavy concurrency N workers can each observe "room left" before any
 *     of them increments, overshooting capacity by up to (N-1). For a ~60 rpm
 *     account budget guarding against an occasional over-limit burst this slop
 *     is acceptable; eliminating it would require a distributed lock per
 *     acquire, which the task explicitly says not to build.
 */
final class CacheRateLimiter implements RateLimiterContract
{
    /** Poll cadence for block(): never sleep longer than this between retries. */
    private const MAX_POLL_MS = 200;

    /** Minimum sleep between block() polls, so we never busy-spin the CPU. */
    private const MIN_POLL_MS = 5;

    private readonly int $capacity;
    private readonly int $windowSeconds;

    /**
     * @param  int|null  $capacity        Permits per window; defaults to config rpm.
     * @param  int|null  $windowSeconds   Window length; defaults to config window_seconds (60 = per-minute).
     */
    public function __construct(?int $capacity = null, ?int $windowSeconds = null)
    {
        $cfg = (array) config('trading.nobitex.rate_limit', []);

        $this->capacity      = max(1, $capacity      ?? (int) ($cfg['rpm'] ?? 60));
        $this->windowSeconds = max(1, $windowSeconds ?? (int) ($cfg['window_seconds'] ?? 60));
    }

    public function acquire(string $key, int $tokens = 1): bool
    {
        $tokens   = max(1, $tokens);
        $cacheKey = $this->cacheKey($key);

        // Would consuming $tokens exceed the window budget?
        if (LaravelRateLimiter::attempts($cacheKey) + $tokens > $this->capacity) {
            return false;
        }

        // Consume. hit() increments by one, so charge $tokens hits.
        for ($i = 0; $i < $tokens; $i++) {
            LaravelRateLimiter::hit($cacheKey, $this->windowSeconds);
        }

        return true;
    }

    public function reserve(string $key, int $tokens = 1): int
    {
        $tokens = max(1, $tokens);

        if (LaravelRateLimiter::attempts($this->cacheKey($key)) + $tokens <= $this->capacity) {
            return 0;
        }

        // Exhausted: the earliest a permit can free is when this window resets.
        // availableIn() is whole seconds; convert to ms and never report < 1ms
        // while still exhausted so callers keep polling rather than spin.
        return max(1, LaravelRateLimiter::availableIn($this->cacheKey($key)) * 1000);
    }

    public function available(string $key): int
    {
        return max(0, LaravelRateLimiter::remaining($this->cacheKey($key), $this->capacity));
    }

    public function block(string $key, int $maxWaitMs, int $tokens = 1): void
    {
        $tokens    = max(1, $tokens);
        $maxWaitMs = max(0, $maxWaitMs);
        $startMs   = $this->nowMs();

        while (true) {
            if ($this->acquire($key, $tokens)) {
                return;
            }

            $elapsedMs = $this->nowMs() - $startMs;
            if ($elapsedMs >= $maxWaitMs) {
                throw new RateLimitExceededException($key, $maxWaitMs);
            }

            // Sleep until the next permit frees, but never past our deadline and
            // never longer than one poll interval (so a shared window that frees
            // early — another process resetting it — is noticed promptly).
            $remainingMs = $maxWaitMs - $elapsedMs;
            $reserveMs   = $this->reserve($key, $tokens) ?: self::MIN_POLL_MS;
            $sleepMs     = max(self::MIN_POLL_MS, min($reserveMs, $remainingMs, self::MAX_POLL_MS));

            usleep($sleepMs * 1000);
        }
    }

    private function cacheKey(string $key): string
    {
        return 'nobitex:ratelimit:' . $key;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
