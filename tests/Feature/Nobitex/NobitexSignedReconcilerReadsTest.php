<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PART 4 — Ed25519 request-signing auth on the reconciler READ endpoints.
 *
 * listOpenOrders() / listRecentTrades() are the two authenticated reader
 * methods the SubmissionReconciler needs. They were deliberately left on the
 * legacy `Authorization: Token` header in PARTS 2/3 and now 401 because token
 * auth is dead. They now authenticate by SIGNING each request (Nobitex-Key /
 * Nobitex-Signature / Nobitex-Timestamp) — the same signed path getBalances()
 * and the order methods use.
 *
 * These are GETs with a query-string symbol filter, so the load-bearing point
 * is that the signed full_path INCLUDES the exact query string, and the body
 * is empty. The signature emitted on the wire must verify against the
 * configured keypair over the EXACT documented payload:
 *
 *     payload = Nobitex-Timestamp + METHOD(upper) + full_path(incl. query) + raw_body
 *
 * where raw_body is "" (GET) and full_path is reconstructed exactly as
 * sendSigned() builds it (url + '?' + http_build_query(query)).
 */
final class NobitexSignedReconcilerReadsTest extends TestCase
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

        // The body sent on the wire must be byte-identical to what we signed (empty for GET).
        $this->assertSame($expectedBody, $request->body());

        $this->assertTrue($request->hasHeader('Nobitex-Key'));
        $this->assertTrue($request->hasHeader('Nobitex-Signature'));
        $this->assertTrue($request->hasHeader('Nobitex-Timestamp'));
        $this->assertFalse($request->hasHeader('Authorization'), 'Legacy Token auth must not be attached on a signed read call.');
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

    public function test_list_open_orders_signs_the_get_with_its_query_string(): void
    {
        Http::fake([
            '*/market/orders/list*' => Http::response([
                'status' => 'ok',
                'orders' => [
                    ['id' => 1, 'clientOrderId' => 'grid-a', 'status' => 'Active'],
                    ['id' => 2, 'clientOrderId' => 'grid-b', 'status' => 'Active'],
                ],
                'hasNext' => false,
            ], 200),
        ]);

        $orders = (new NobitexService)->listOpenOrders('BTCIRT');

        $this->assertCount(2, $orders);
        $this->assertSame(1, $orders[0]['id']);

        // BTCIRT → src=btc, dst=rls (IRT→rls, see GridOrderExecutor::splitSymbol).
        // full_path is built exactly as sendSigned() does it.
        $expectedFullPath = '/market/orders/list?'.http_build_query([
            'srcCurrency' => 'btc',
            'dstCurrency' => 'rls',
            'status'      => 'open',
            'details'     => 2,
        ]);

        Http::assertSent(function ($request) use ($expectedFullPath) {
            if (! str_contains($request->url(), '/market/orders/list')) {
                return false;
            }
            $this->assertSignedRequest($request, 'GET', $expectedFullPath, '');

            return true;
        });
    }

    public function test_list_recent_trades_signs_the_get_with_its_query_string(): void
    {
        Http::fake([
            '*/market/trades/list*' => Http::response([
                'status' => 'ok',
                'trades' => [
                    ['orderId' => 10, 'type' => 'buy', 'price' => '1000', 'amount' => '0.5', 'timestamp' => '2026-08-21T00:00:00Z'],
                ],
            ], 200),
        ]);

        $trades = (new NobitexService)->listRecentTrades('BTCIRT');

        $this->assertCount(1, $trades);
        $this->assertSame(10, $trades[0]['orderId']);

        $expectedFullPath = '/market/trades/list?'.http_build_query([
            'srcCurrency' => 'btc',
            'dstCurrency' => 'rls',
        ]);

        Http::assertSent(function ($request) use ($expectedFullPath) {
            if (! str_contains($request->url(), '/market/trades/list')) {
                return false;
            }
            $this->assertSignedRequest($request, 'GET', $expectedFullPath, '');

            return true;
        });
    }
}
