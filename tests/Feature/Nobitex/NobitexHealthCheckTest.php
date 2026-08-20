<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase P3.5 Fix 2 — healthCheck() probed a broken endpoint.
 *
 * healthCheck() used GET /users/profile, which on the live host returns
 * HTTP 400 {"code":"UnexpectedError"} even though the connection and auth are
 * fine (getBalances(), which uses POST /users/wallets/list, works perfectly).
 * The check therefore false-alarmed the "Nobitex connection problem" banner on
 * an endpoint fault, not a real outage.
 *
 * The probe now hits the same POST /users/wallets/list call getBalances relies
 * on, so a well-formed balances response maps to overall_status=healthy. These
 * tests fake the HTTP layer to assert the mapping in both directions, and that
 * the old profile endpoint is no longer touched.
 */
final class NobitexHealthCheckTest extends TestCase
{
    private const HOST = 'apiv2.nobitex.ir/*';

    protected function setUp(): void
    {
        parent::setUp();

        // healthCheck() now authenticates via Ed25519 request signing, so it needs
        // a keypair configured (a self-contained test seed — never a real key).
        $seed = str_repeat("\x09", SODIUM_CRYPTO_SIGN_SEEDBYTES);

        config([
            'trading.nobitex.base_url'         => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'          => '',
            'trading.nobitex.api_public_key'   => 'test-public-key',
            'trading.nobitex.api_private_key'  => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            'trading.nobitex.retry.times'      => 1,
            'trading.nobitex.retry.sleep'      => 0,
            'trading.nobitex.rate_limit.rpm'   => 1000,
        ]);
    }

    public function test_well_formed_wallets_response_maps_to_healthy(): void
    {
        // A well-formed balances-style payload — the exact shape getBalances()
        // treats as success (status=ok + wallets array).
        Http::fake([
            '*/users/wallets/list' => Http::response([
                'status'  => 'ok',
                'wallets' => [
                    ['currency' => 'rls', 'balance' => '1000000', 'blocked' => '0'],
                    ['currency' => 'btc', 'balance' => '0.5', 'blocked' => '0'],
                ],
            ], 200),
        ]);

        $health = (new NobitexService())->healthCheck();

        $this->assertTrue($health['ok']);
        $this->assertSame('ok', $health['status']);
        $this->assertSame('healthy', $health['overall_status']);
        $this->assertStringContainsString('/users/wallets/list', $health['endpoint']);
        $this->assertArrayHasKey('response_time_ms', $health);
        $this->assertArrayHasKey('mode', $health);

        // The broken profile endpoint must no longer be probed.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/profile'));
    }

    public function test_endpoint_failure_maps_to_unhealthy(): void
    {
        // Mirror the live /users/profile fault (HTTP 400 UnexpectedError) but on
        // the probed endpoint — a genuine failure must still report unhealthy.
        Http::fake([
            '*/users/wallets/list' => Http::response(['code' => 'UnexpectedError'], 400),
        ]);

        $health = (new NobitexService())->healthCheck();

        $this->assertFalse($health['ok']);
        $this->assertSame('failed', $health['status']);
        $this->assertSame('unhealthy', $health['overall_status']);
    }
}
