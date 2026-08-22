<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PART 5 — Ed25519 request-signing auth on the REMAINING authenticated
 * NobitexService methods that were missed by PARTS 2–4.
 *
 * These methods (getBalance, the margin/position endpoints, the address-book /
 * whitelist endpoints, and getOptionsV2) were still on the legacy
 * `Authorization: Token` header and would 401 because token auth is dead. They
 * now authenticate by SIGNING each request (Nobitex-Key / Nobitex-Signature /
 * Nobitex-Timestamp) — the same signed path getBalances() and the order/reader
 * methods already use.
 *
 * getBalance() sits nearest the bot, so it gets a FULL signature verification
 * (payload reconstructed from the request's own values). The remaining methods
 * get the lightweight assertion the task calls for: they now emit the Nobitex-*
 * signed headers and NOT the legacy Authorization header, and — because it is
 * cheap and shared — their signatures are verified too via the same helper.
 *
 *     payload = Nobitex-Timestamp + METHOD(upper) + full_path(incl. query) + raw_body
 *
 * Withdraw endpoints (createWithdraw / confirmWithdraw / listWithdraws /
 * getWithdraw) are deliberately NOT converted and are NOT covered here.
 */
final class NobitexSignedRemainingAuthTest extends TestCase
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

    /**
     * Assert a captured request carries the signed headers (and NOT the legacy
     * Token header) and that its signature verifies against the configured
     * keypair over timestamp+METHOD+full_path(incl. query)+the request's own body.
     */
    private function assertSignedRequest($request, string $expectedMethod, string $expectedFullPath, string $expectedBody): void
    {
        $this->assertSame($expectedMethod, $request->method());
        // The full path AND its query string must appear on the wire exactly as signed.
        $this->assertStringContainsString($expectedFullPath, $request->url());

        // The body sent on the wire must be byte-identical to what we signed.
        $this->assertSame($expectedBody, $request->body());

        $this->assertTrue($request->hasHeader('Nobitex-Key'));
        $this->assertTrue($request->hasHeader('Nobitex-Signature'));
        $this->assertTrue($request->hasHeader('Nobitex-Timestamp'));
        $this->assertFalse($request->hasHeader('Authorization'), 'Legacy Token auth must not be attached on a signed call.');
        $this->assertSame('my-public-key', $request->header('Nobitex-Key')[0]);
        $this->assertSame('TraderBot/Griding-1.0', $request->header('User-Agent')[0]);

        // Reconstruct the documented payload from the request's OWN values and
        // verify the emitted (PADDED url-safe base64) signature against it.
        $ts = $request->header('Nobitex-Timestamp')[0];
        $payload = $ts.$expectedMethod.$expectedFullPath.$request->body();

        $signatureHeader = $request->header('Nobitex-Signature')[0];
        // Padded url-safe base64: '=' padding is preserved by the signer.
        $this->assertSame(
            $signatureHeader,
            $this->reencodePaddedUrlSafe($signatureHeader),
            'Nobitex-Signature must be PADDED url-safe base64.'
        );

        $sigRaw = base64_decode(strtr($signatureHeader, '-_', '+/'));
        $this->assertTrue(
            sodium_crypto_sign_verify_detached($sigRaw, $payload, $this->pubRaw),
            'Signature must verify against the configured public key over timestamp+METHOD+full_path+raw_body.'
        );
    }

    /** Round-trip a url-safe base64 string to confirm it is padded (idempotent for padded input). */
    private function reencodePaddedUrlSafe(string $s): string
    {
        return strtr(base64_encode(base64_decode(strtr($s, '-_', '+/'))), '+/', '-_');
    }

    public function test_get_balance_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/users/wallets/balance' => Http::response([
                'status' => 'ok',
                'balance' => '123.45',
                'locked' => '0',
            ], 200),
        ]);

        $dto = (new NobitexService)->getBalance('rls');
        $this->assertSame('rls', $dto->currency);

        // Same source of truth for sign and send: json_encode with sendSigned's flags.
        $expectedBody = json_encode(['currency' => 'rls'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/users/wallets/balance')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/users/wallets/balance', $expectedBody);

            return true;
        });
    }

    public function test_create_margin_oco_order_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/market/orders/add' => Http::response([
                'status' => 'ok',
                'orders' => [['id' => 1], ['id' => 2]],
            ], 200),
        ]);

        $dto = [
            'type' => 'sell',
            'srcCurrency' => 'btc',
            'dstCurrency' => 'rls',
            'amount' => '0.01',
            'price' => '1000000000',
            'stopPrice' => '990000000',
            'stopLimitPrice' => '985000000',
        ];

        (new NobitexService)->createMarginOcoOrder($dto);

        $expectedBody = json_encode($dto + ['mode' => 'oco'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/market/orders/add')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/market/orders/add', $expectedBody);

            return true;
        });
    }

    public function test_list_positions_signs_the_get_with_its_query_string(): void
    {
        Http::fake([
            '*/positions/list*' => Http::response([
                'status' => 'ok',
                'positions' => [['id' => 7]],
                'hasNext' => false,
            ], 200),
        ]);

        (new NobitexService)->listPositions();

        // Default call: only status='active' survives array_filter.
        $expectedFullPath = '/positions/list?'.http_build_query(['status' => 'active']);

        Http::assertSent(function ($request) use ($expectedFullPath) {
            if (! str_contains($request->url(), '/positions/list')) {
                return false;
            }
            $this->assertSignedRequest($request, 'GET', $expectedFullPath, '');

            return true;
        });
    }

    public function test_get_position_status_signs_the_get(): void
    {
        Http::fake([
            '*/positions/42/status' => Http::response([
                'status' => 'ok',
                'position' => ['id' => 42],
            ], 200),
        ]);

        (new NobitexService)->getPositionStatus(42);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/positions/42/status')) {
                return false;
            }
            $this->assertSignedRequest($request, 'GET', '/positions/42/status', '');

            return true;
        });
    }

    public function test_close_position_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/positions/42/close' => Http::response([
                'status' => 'ok',
                'order' => ['id' => 9],
            ], 200),
        ]);

        $dto = ['amount' => '0.01', 'price' => '1000000000'];
        (new NobitexService)->closePosition(42, $dto);

        $expectedBody = json_encode($dto, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/positions/42/close')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/positions/42/close', $expectedBody);

            return true;
        });
    }

    public function test_edit_collateral_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/positions/42/edit-collateral' => Http::response([
                'status' => 'ok',
                'position' => ['id' => 42],
            ], 200),
        ]);

        (new NobitexService)->editCollateral(42, '5000000');

        $expectedBody = json_encode(['collateral' => '5000000'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/positions/42/edit-collateral')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/positions/42/edit-collateral', $expectedBody);

            return true;
        });
    }

    public function test_address_book_list_signs_the_get(): void
    {
        Http::fake([
            '*/address_book*' => Http::response([
                'status' => 'ok',
                'data' => [['id' => 1, 'address' => 'x']],
            ], 200),
        ]);

        // No network filter → empty query → full_path has no query string.
        (new NobitexService)->addressBookList();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/address_book')) {
                return false;
            }
            $this->assertSignedRequest($request, 'GET', '/address_book', '');

            return true;
        });
    }

    public function test_address_book_add_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/address_book' => Http::response([
                'status' => 'ok',
                'data' => ['id' => 1],
            ], 200),
        ]);

        $dto = ['currency' => 'btc', 'network' => 'BTC', 'address' => 'bc1qxyz', 'label' => 'cold'];
        (new NobitexService)->addressBookAdd($dto);

        $expectedBody = json_encode($dto, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            // Match the exact POST (not the DELETE/whitelist sub-paths).
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/address_book')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/address_book', $expectedBody);

            return true;
        });
    }

    public function test_address_book_delete_signs_the_delete(): void
    {
        Http::fake([
            '*/address_book/5/delete' => Http::response(['status' => 'ok'], 200),
        ]);

        (new NobitexService)->addressBookDelete(5);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/address_book/5/delete')) {
                return false;
            }
            $this->assertSignedRequest($request, 'DELETE', '/address_book/5/delete', '');

            return true;
        });
    }

    public function test_activate_whitelist_signs_the_bodyless_post(): void
    {
        Http::fake([
            '*/address_book/whitelist/activate' => Http::response(['status' => 'ok'], 200),
        ]);

        (new NobitexService)->activateWhitelist();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/address_book/whitelist/activate')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/address_book/whitelist/activate', '');

            return true;
        });
    }

    public function test_deactivate_whitelist_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/address_book/whitelist/deactivate' => Http::response(['status' => 'ok'], 200),
        ]);

        (new NobitexService)->deactivateWhitelist('123456', '654321');

        $expectedBody = json_encode([
            'otpCode' => '123456',
            'tfaCode' => '654321',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/address_book/whitelist/deactivate')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/address_book/whitelist/deactivate', $expectedBody);

            return true;
        });
    }

    public function test_get_options_v2_signs_the_get(): void
    {
        // getOptionsV2 memoises via Cache::remember — flush so this test's call
        // actually reaches the HTTP layer.
        Cache::flush();

        Http::fake([
            '*/v2/options' => Http::response([
                'status' => 'ok',
                'features' => [],
                'coins' => [],
                'nobitex' => [],
            ], 200),
        ]);

        (new NobitexService)->getOptionsV2();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/options')) {
                return false;
            }
            $this->assertSignedRequest($request, 'GET', '/v2/options', '');

            return true;
        });
    }
}
