<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Http\Client\PendingRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 12 Step 4 — connect vs read timeout wiring.
 *
 * Http::fake() does not exercise real network timeouts, so we cannot observe a
 * real connect failure here. What we CAN verify honestly is that the two
 * budgets are actually configured on the outgoing request: NobitexService::http()
 * must set BOTH Guzzle options — `timeout` (total/read budget) and
 * `connect_timeout` (the previously-dead config key) — from
 * config('trading.nobitex.http.*').
 *
 * We assert this by reflecting the protected http() factory and reading back the
 * PendingRequest's options (PendingRequest::getOptions()). This proves the
 * config value is read and applied; it does NOT prove cURL honours it at
 * runtime (that is untestable without a real slow/unreachable host).
 */
final class NobitexConnectTimeoutTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function pendingOptions(): array
    {
        $svc = new NobitexService();
        $ref = new ReflectionMethod($svc, 'http');
        $ref->setAccessible(true);

        /** @var PendingRequest $pending */
        $pending = $ref->invoke($svc, []);

        return $pending->getOptions();
    }

    public function test_http_applies_both_read_and_connect_timeouts_from_config(): void
    {
        config([
            'trading.nobitex.http.timeout'         => 7,
            'trading.nobitex.http.connect_timeout' => 3.5,
        ]);

        $options = $this->pendingOptions();

        $this->assertArrayHasKey('timeout', $options, 'Read/total timeout must be applied.');
        $this->assertArrayHasKey('connect_timeout', $options, 'Connect timeout must be applied (was previously dead config).');

        $this->assertSame(7, $options['timeout']);
        $this->assertSame(3.5, $options['connect_timeout']);
    }

    public function test_connect_timeout_is_independent_of_read_timeout(): void
    {
        // The whole point of Step 4: the two budgets are separate knobs, not one
        // shared value. Prove they carry distinct values end-to-end.
        config([
            'trading.nobitex.http.timeout'         => 12,
            'trading.nobitex.http.connect_timeout' => 4.0,
        ]);

        $options = $this->pendingOptions();

        $this->assertSame(12, $options['timeout']);
        $this->assertSame(4.0, $options['connect_timeout']);
        $this->assertNotSame(
            $options['timeout'],
            $options['connect_timeout'],
            'Connect and read timeouts must be independently sourced.'
        );
    }
}
