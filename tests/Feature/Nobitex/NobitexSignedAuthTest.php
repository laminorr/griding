<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PART 2a — Ed25519 request-signing auth on the balance-fetching path.
 *
 * getBalances() / healthCheck() now authenticate by signing each request
 * (Nobitex-Key / Nobitex-Signature / Nobitex-Timestamp) INSTEAD of the legacy
 * `Authorization: Token` header. These tests fake the HTTP layer and assert:
 *   • the three Nobitex-* headers are attached (and Authorization is not),
 *   • the call is POST /users/wallets/list with an EMPTY body (proven shape),
 *   • the emitted signature actually verifies against the configured keypair.
 */
final class NobitexSignedAuthTest extends TestCase
{
    /** url-safe base64 of a 32-byte Ed25519 seed → the NOBITEX_API_PRIVATE_KEY form. */
    private string $seed;

    private string $pubRaw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $this->pubRaw = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed));

        config([
            'trading.nobitex.base_url' => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key' => 'legacy-token-should-not-be-used',
            'trading.nobitex.api_public_key' => 'my-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode($this->seed), '+/', '-_'), '='),
            'trading.nobitex.signed_user_agent' => 'TraderBot/Griding-1.0',
            'trading.nobitex.retry.times' => 1,
            'trading.nobitex.retry.sleep' => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
        ]);
    }

    public function test_get_balances_signs_the_request_and_omits_token_header(): void
    {
        Http::fake([
            '*/users/wallets/list' => Http::response([
                'status' => 'ok',
                'wallets' => [
                    ['currency' => 'rls', 'balance' => '1000000', 'blocked' => '0'],
                    ['currency' => 'btc', 'balance' => '0.5', 'blocked' => '250'],
                ],
            ], 200),
        ]);

        $balances = (new NobitexService)->getBalances();

        $this->assertSame(['available' => '1000000', 'locked' => '0'], $balances['rls']);
        $this->assertSame(['available' => '0.5', 'locked' => '250'], $balances['btc']);

        Http::assertSent(function ($request) {
            // POST, correct endpoint, EMPTY body (the proven /users/wallets/list shape).
            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString('/users/wallets/list', $request->url());
            $this->assertSame('', $request->body());

            // Signed headers present; legacy Token auth NOT present.
            $this->assertTrue($request->hasHeader('Nobitex-Key'));
            $this->assertTrue($request->hasHeader('Nobitex-Signature'));
            $this->assertTrue($request->hasHeader('Nobitex-Timestamp'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertSame('my-public-key', $request->header('Nobitex-Key')[0]);
            $this->assertSame('TraderBot/Griding-1.0', $request->header('User-Agent')[0]);

            // The signature verifies against the configured keypair over the
            // documented payload (timestamp + METHOD + full_path + raw_body).
            $ts = $request->header('Nobitex-Timestamp')[0];
            $payload = $ts.'POST'.'/users/wallets/list'.'';
            $sigRaw = base64_decode(strtr($request->header('Nobitex-Signature')[0], '-_', '+/'));

            $this->assertTrue(
                sodium_crypto_sign_verify_detached($sigRaw, $payload, $this->pubRaw),
                'Signature emitted by getBalances() must verify against the configured public key.'
            );

            return true;
        });
    }

    public function test_health_check_uses_the_signed_wallets_endpoint(): void
    {
        Http::fake([
            '*/users/wallets/list' => Http::response(['status' => 'ok', 'wallets' => []], 200),
        ]);

        $health = (new NobitexService)->healthCheck();

        $this->assertTrue($health['ok']);
        $this->assertSame('healthy', $health['overall_status']);

        Http::assertSent(fn ($request) => $request->hasHeader('Nobitex-Signature')
            && ! $request->hasHeader('Authorization')
            && str_contains($request->url(), '/users/wallets/list'));
    }
}
