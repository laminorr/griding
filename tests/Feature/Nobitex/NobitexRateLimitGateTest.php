<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Exceptions\RateLimitExceededException;
use App\Services\NobitexService;
use App\Services\RateLimiting\CacheRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Phase 12 Step 3 — the enforcing rate gate wired through request(), behind the
 * default-OFF trading.nobitex.rate_limit.enforce flag.
 *
 * Two invariants:
 *   • enforce=false (the shipped default) → request() ignores the new global
 *     gate entirely; an exhausted global budget does NOT block a send.
 *   • enforce=true with an exhausted budget + tiny max_wait → request() throws
 *     RateLimitExceededException and sends NOTHING (assertSentCount 0).
 *
 * request() is protected, so it is driven via reflection, mirroring
 * NobitexRequestRetryTest.
 */
final class NobitexRateLimitGateTest extends TestCase
{
    private const HOST = 'apiv2.nobitex.ir/*';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'trading.nobitex.base_url'    => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'     => '',
            'trading.nobitex.retry.times' => 3,
            'trading.nobitex.retry.sleep' => 0,
        ]);
    }

    /** Build the service AFTER config is pinned and expose request(). */
    private function invokeRequest(): array
    {
        $svc = new NobitexService();
        $ref = new ReflectionMethod($svc, 'request');
        $ref->setAccessible(true);

        return $ref->invoke($svc, 'POST', '/market/orders/add', [], ['x' => 1]);
    }

    /** Fully spend the global budget so the gate would block/throw if consulted. */
    private function exhaustGlobalBudget(int $capacity, int $windowSeconds): void
    {
        $limiter = new CacheRateLimiter(capacity: $capacity, windowSeconds: $windowSeconds);
        for ($i = 0; $i < $capacity; $i++) {
            $this->assertTrue($limiter->acquire('global'));
        }
        $this->assertFalse($limiter->acquire('global'), 'Budget should be exhausted for the test.');
    }

    /**
     * enforce=false (default): even with the global budget fully exhausted, the
     * request sails through — the new gate is never consulted, preserving
     * today's behaviour exactly.
     */
    public function test_enforce_false_ignores_global_budget_and_sends(): void
    {
        config([
            'trading.nobitex.rate_limit.enforce'        => false,
            'trading.nobitex.rate_limit.rpm'            => 2,
            'trading.nobitex.rate_limit.window_seconds' => 60,
            'trading.nobitex.rate_limit.max_wait_ms'    => 50,
        ]);

        $this->exhaustGlobalBudget(2, 60);

        Http::fake([self::HOST => Http::response(['status' => 'ok', 'order' => ['id' => 1]], 200)]);

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        Http::assertSentCount(1); // sent despite an exhausted global budget
    }

    /**
     * enforce=true + exhausted budget + tiny max_wait: request() throws
     * RateLimitExceededException and sends NOTHING — the gate stops the request
     * before it leaves the box.
     */
    public function test_enforce_true_with_exhausted_budget_throws_and_sends_nothing(): void
    {
        config([
            'trading.nobitex.rate_limit.enforce'        => true,
            'trading.nobitex.rate_limit.rpm'            => 2,
            'trading.nobitex.rate_limit.window_seconds' => 60,
            'trading.nobitex.rate_limit.max_wait_ms'    => 40,
        ]);

        $this->exhaustGlobalBudget(2, 60);

        Http::fake([self::HOST => Http::response(['status' => 'ok'], 200)]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (RateLimitExceededException $e) {
            $threw = true;
            $this->assertSame('global', $e->limiterKey);
        } catch (Throwable $e) {
            $this->fail('Expected RateLimitExceededException, got ' . $e::class . ': ' . $e->getMessage());
        }

        $this->assertTrue($threw, 'An exhausted budget under enforce=true must throw.');
        Http::assertSentCount(0); // NOT sent — the gate blocked it
    }

    /**
     * enforce=true with headroom: the request passes the gate and is sent,
     * consuming exactly one permit from the global budget.
     */
    public function test_enforce_true_with_headroom_sends_and_consumes_one_permit(): void
    {
        config([
            'trading.nobitex.rate_limit.enforce'        => true,
            'trading.nobitex.rate_limit.rpm'            => 5,
            'trading.nobitex.rate_limit.window_seconds' => 60,
            'trading.nobitex.rate_limit.max_wait_ms'    => 50,
        ]);

        Http::fake([self::HOST => Http::response(['status' => 'ok', 'order' => ['id' => 9]], 200)]);

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        Http::assertSentCount(1);

        // One permit gone from the shared budget (5 → 4 remaining).
        $limiter = new CacheRateLimiter(capacity: 5, windowSeconds: 60);
        $this->assertSame(4, $limiter->available('global'));
    }
}
