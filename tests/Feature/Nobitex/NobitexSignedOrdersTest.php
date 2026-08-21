<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PART 3 — Ed25519 request-signing auth on the ORDER endpoints.
 *
 * createOrder / cancelOrder / getOrdersStatus / getOrderByClientOrderId /
 * placeOrder now authenticate by SIGNING each request (Nobitex-Key /
 * Nobitex-Signature / Nobitex-Timestamp) instead of the legacy
 * `Authorization: Token` header — the same signed path getBalances() uses.
 *
 * The load-bearing assertion, repeated per method, is that the signature
 * emitted on the wire verifies against the configured keypair over the EXACT
 * documented payload:
 *
 *     payload = Nobitex-Timestamp + METHOD(upper) + full_path + raw_body
 *
 * where raw_body is byte-identical to the request body actually sent. Because
 * money rides on createOrder/placeOrder, those tests reconstruct the payload
 * from the request's OWN body string (not a hard-coded guess) and confirm the
 * signature verifies — so a re-encode/reorder between signing and sending
 * would fail the test.
 */
final class NobitexSignedOrdersTest extends TestCase
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
     * keypair over timestamp+METHOD+full_path+the request's own body.
     */
    private function assertSignedRequest($request, string $expectedMethod, string $expectedPath, string $expectedBody): void
    {
        $this->assertSame($expectedMethod, $request->method());
        $this->assertStringContainsString($expectedPath, $request->url());

        // The body sent on the wire must be byte-identical to what we signed.
        $this->assertSame($expectedBody, $request->body());

        $this->assertTrue($request->hasHeader('Nobitex-Key'));
        $this->assertTrue($request->hasHeader('Nobitex-Signature'));
        $this->assertTrue($request->hasHeader('Nobitex-Timestamp'));
        $this->assertFalse($request->hasHeader('Authorization'), 'Legacy Token auth must not be attached on a signed order call.');
        $this->assertSame('my-public-key', $request->header('Nobitex-Key')[0]);
        $this->assertSame('TraderBot/Griding-1.0', $request->header('User-Agent')[0]);

        // Reconstruct the documented payload from the request's OWN values and
        // verify the emitted (PADDED url-safe base64) signature against it.
        $ts = $request->header('Nobitex-Timestamp')[0];
        $payload = $ts.$expectedMethod.$expectedPath.$request->body();

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

    public function test_create_order_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/market/orders/add' => Http::response([
                'status' => 'ok',
                'order' => ['id' => 4242, 'status' => 'Active'],
            ], 200),
        ]);

        $dto = new CreateOrderDto(
            side: OrderSide::BUY,
            execution: ExecutionType::LIMIT,
            srcCurrency: 'btc',
            dstCurrency: 'irt',
            amountBase: '0.0015',
            priceIRT: 1_000_000_000,
            clientRef: 'grid-abc-1',
        );

        // The body we EXPECT on the wire is json_encode of the DTO payload with the
        // exact flags sendSigned() uses — same source of truth for sign and send.
        $expectedBody = json_encode($dto->toApiPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        (new NobitexService)->createOrder($dto);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/market/orders/add')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/market/orders/add', $expectedBody);

            return true;
        });
    }

    public function test_place_order_signs_the_exact_json_body_it_sends(): void
    {
        Http::fake([
            '*/market/orders/add' => Http::response(['status' => 'ok', 'order' => ['id' => 1]], 200),
        ]);

        (new NobitexService)->placeOrder('BTCIRT', 'sell', 1_050_000_000, '0.002', 'grid-xyz-9');

        $expectedBody = json_encode([
            'type' => 'sell',
            'execution' => 'limit',
            'srcCurrency' => 'btc',
            'dstCurrency' => 'rls',
            'amount' => '0.002',
            'price' => '1050000000',
            'clientOrderId' => 'grid-xyz-9',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            if (! str_contains($request->url(), '/market/orders/add')) {
                return false;
            }
            $this->assertSignedRequest($request, 'POST', '/market/orders/add', $expectedBody);

            return true;
        });
    }

    public function test_cancel_order_signs_the_update_status_request(): void
    {
        Http::fake([
            '*/market/orders/update-status' => Http::response(['status' => 'ok'], 200),
        ]);

        (new NobitexService)->cancelOrder('987654');

        $expectedBody = json_encode(['order' => '987654', 'status' => 'canceled'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            $this->assertSignedRequest($request, 'POST', '/market/orders/update-status', $expectedBody);

            return true;
        });
    }

    public function test_get_orders_status_signs_the_status_request(): void
    {
        Http::fake([
            '*/market/orders/status' => Http::response([
                'status' => 'ok',
                'order' => ['id' => '555', 'status' => 'Active', 'type' => 'buy', 'amount' => '0.01', 'matchedAmount' => '0'],
            ], 200),
        ]);

        (new NobitexService)->getOrdersStatus(['555']);

        $expectedBody = json_encode(['id' => '555'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            $this->assertSignedRequest($request, 'POST', '/market/orders/status', $expectedBody);

            return true;
        });
    }

    public function test_get_order_by_client_order_id_signs_the_status_request(): void
    {
        Http::fake([
            '*/market/orders/status' => Http::response([
                'status' => 'ok',
                'order' => ['id' => '777', 'clientOrderId' => 'grid-abc-1', 'status' => 'Active'],
            ], 200),
        ]);

        $row = (new NobitexService)->getOrderByClientOrderId('grid-abc-1');
        $this->assertSame('777', $row['id']);

        $expectedBody = json_encode(['clientOrderId' => 'grid-abc-1'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Http::assertSent(function ($request) use ($expectedBody) {
            $this->assertSignedRequest($request, 'POST', '/market/orders/status', $expectedBody);

            return true;
        });
    }
}
