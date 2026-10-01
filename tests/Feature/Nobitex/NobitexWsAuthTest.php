<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * W3 — the two credentials the private WebSocket consumer needs:
 *  - getWebsocketToken(): SIGNED GET /auth/ws/token/ (legacy Token auth is retired)
 *  - getWebsocketAuthParam(): SIGNED GET /users/profile → profile.websocketAuthParam, cached 24h
 * Neither may ever log the secret.
 */
final class NobitexWsAuthTest extends TestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.SECRET-JWT-PAYLOAD.sig';
    private const PARAM = 'Ab12Cd34Ef56Gh78Ij90Kl12Mn34Op56';

    /** @var array<int,string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        config([
            'trading.nobitex.base_url' => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key' => 'legacy-token-should-not-be-used',
            'trading.nobitex.api_public_key' => 'my-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            'trading.nobitex.retry.times' => 1,
            'trading.nobitex.retry.sleep' => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
        ]);
        Cache::flush();

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->logged[] = $m->message . ' ' . json_encode($m->context);
        });
    }

    private function assertNoSecretLogged(): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString(self::PARAM, $line);
        }
    }

    private function assertSigned($request): void
    {
        $this->assertTrue($request->hasHeader('Nobitex-Key'));
        $this->assertTrue($request->hasHeader('Nobitex-Signature'));
        $this->assertTrue($request->hasHeader('Nobitex-Timestamp'));
        $this->assertFalse($request->hasHeader('Authorization'), 'Legacy Token auth must not be sent.');
    }

    public function test_get_websocket_token_is_signed_and_returns_the_token_string(): void
    {
        Http::fake(['*/auth/ws/token/*' => Http::response(['status' => 'ok', 'token' => self::TOKEN], 200)]);

        $this->assertSame(self::TOKEN, (new NobitexService)->getWebsocketToken());

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/auth/ws/token/')) {
                return false;
            }
            $this->assertSame('GET', $request->method());
            $this->assertSigned($request);
            return true;
        });
        $this->assertNoSecretLogged();
    }

    public function test_get_websocket_token_throws_without_a_token(): void
    {
        Http::fake(['*/auth/ws/token/*' => Http::response(['status' => 'ok'], 200)]);

        $this->expectException(\RuntimeException::class);
        (new NobitexService)->getWebsocketToken();
    }

    public function test_get_websocket_token_throws_on_failed_status(): void
    {
        Http::fake(['*/auth/ws/token/*' => Http::response(['status' => 'failed', 'code' => 'Unauthorized', 'message' => 'x'], 200)]);

        $this->expectException(\RuntimeException::class);
        (new NobitexService)->getWebsocketToken();
    }

    public function test_get_websocket_auth_param_is_signed_extracted_and_cached(): void
    {
        Http::fake(['*/users/profile*' => Http::response([
            'status' => 'ok',
            'profile' => ['username' => 'u', 'websocketAuthParam' => self::PARAM],
        ], 200)]);

        $svc = new NobitexService;
        $this->assertSame(self::PARAM, $svc->getWebsocketAuthParam());
        $this->assertSame(self::PARAM, $svc->getWebsocketAuthParam());
        $this->assertSame(self::PARAM, (new NobitexService)->getWebsocketAuthParam());

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/users/profile', $request->url());
            $this->assertSigned($request);
            return true;
        });
        $this->assertSame(self::PARAM, Cache::get(NobitexService::WS_AUTH_PARAM_CACHE_KEY));
        $this->assertSame(86400, NobitexService::WS_AUTH_PARAM_CACHE_TTL);
        $this->assertNoSecretLogged();
    }

    public function test_get_websocket_auth_param_throws_and_caches_nothing_when_missing(): void
    {
        Http::fake(['*/users/profile*' => Http::response(['status' => 'ok', 'profile' => []], 200)]);

        try {
            (new NobitexService)->getWebsocketAuthParam();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(Cache::get(NobitexService::WS_AUTH_PARAM_CACHE_KEY));
    }
}
