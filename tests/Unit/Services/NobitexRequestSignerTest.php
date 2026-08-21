<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NobitexRequestSigner;
use Tests\TestCase;

/**
 * Locks the Ed25519 request-signing contract for Nobitex private endpoints.
 *
 * The signed payload MUST be exactly:  timestamp + METHOD + full_path + raw_body
 * (no separators), and the emitted Nobitex-Signature MUST verify against the
 * Ed25519 public key derived from the same seed. sodium_compat backs these
 * functions in pure PHP, so this suite runs even on hosts without ext-sodium.
 */
final class NobitexRequestSignerTest extends TestCase
{
    /** @return array{0:NobitexRequestSigner,1:string,2:string,3:string} [signer, privateKeyB64, publicKeyHeader, derivedPubRaw] */
    private function makeSigner(): array
    {
        // A deterministic, self-contained test keypair (never a real key).
        $seed = str_repeat("\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES); // 32 bytes
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $pubRaw = sodium_crypto_sign_publickey($keypair);

        $privateKey = $this->urlSafeB64Encode($seed);
        $publicHdr = 'test-public-key-id';

        return [new NobitexRequestSigner($publicHdr, $privateKey), $privateKey, $publicHdr, $pubRaw];
    }

    public function test_payload_is_timestamp_method_fullpath_rawbody_concatenation(): void
    {
        [$signer] = $this->makeSigner();

        $payload = $signer->buildPayload('1700000000', 'post', '/users/wallets/list', '');

        // Method uppercased, no separators, empty body appends nothing.
        $this->assertSame('1700000000POST/users/wallets/list', $payload);
    }

    public function test_payload_includes_query_string_and_raw_body(): void
    {
        [$signer] = $this->makeSigner();

        $body = '{"a":1}';
        $payload = $signer->buildPayload('1700000000', 'GET', '/market/orders/list?fromId=123', $body);

        $this->assertSame('1700000000GET/market/orders/list?fromId=123{"a":1}', $payload);
    }

    public function test_signature_verifies_against_the_public_key(): void
    {
        [$signer, , $publicHdr, $pubRaw] = $this->makeSigner();

        $ts = 1700000000;
        $method = 'POST';
        $path = '/users/wallets/list';
        $body = '';

        $headers = $signer->signRequest($method, $path, $body, $ts);

        // The three headers are present and consistent with the inputs.
        $this->assertSame($publicHdr, $headers['Nobitex-Key']);
        $this->assertSame((string) $ts, $headers['Nobitex-Timestamp']);
        $this->assertArrayHasKey('Nobitex-Signature', $headers);

        $sig = $headers['Nobitex-Signature'];

        // Signature is url-safe base64 WITH padding preserved: Nobitex requires
        // the '=' padding (an unpadded signature returns HTTP 400). A 64-byte
        // Ed25519 signature encodes to 88 chars ending in '=='.
        $this->assertSame(88, strlen($sig), 'Padded base64 of a 64-byte signature is 88 chars.');
        $this->assertStringEndsWith('==', $sig, 'Padding must be preserved, not stripped.');
        // url-safe alphabet only — never the standard '+'/'/'.
        $this->assertDoesNotMatchRegularExpression('#[+/]#', $sig);

        // Decoding as url-safe, padded base64 round-trips to the raw 64-byte sig.
        $sigRaw = base64_decode(strtr($sig, '-_', '+/'), true);
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($sigRaw));
        $this->assertSame(64, strlen($sigRaw));

        // It verifies against the seed-derived Ed25519 public key over the exact
        // documented payload string.
        $payload = $ts.$method.$path.$body;
        $this->assertTrue(
            sodium_crypto_sign_verify_detached($sigRaw, $payload, $pubRaw),
            'Ed25519 signature must verify against the derived public key.'
        );
    }

    public function test_a_tampered_payload_fails_verification(): void
    {
        [$signer, , , $pubRaw] = $this->makeSigner();

        $headers = $signer->signRequest('POST', '/users/wallets/list', '', 1700000000);
        $sigRaw = base64_decode(strtr($headers['Nobitex-Signature'], '-_', '+/'));

        // Same signature, different payload (body mutated) must NOT verify.
        $tampered = '1700000000POST/users/wallets/list{"x":1}';
        $this->assertFalse(sodium_crypto_sign_verify_detached($sigRaw, $tampered, $pubRaw));
    }

    public function test_from_config_reads_keys_from_trading_config(): void
    {
        $seed = str_repeat("\x02", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $privateKey = $this->urlSafeB64Encode($seed);

        config([
            'trading.nobitex.api_public_key' => 'cfg-public',
            'trading.nobitex.api_private_key' => $privateKey,
        ]);

        $signer = NobitexRequestSigner::fromConfig();
        $headers = $signer->signRequest('POST', '/users/wallets/list', '', 1700000000);

        $this->assertSame('cfg-public', $headers['Nobitex-Key']);

        $pubRaw = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        $sigRaw = base64_decode(strtr($headers['Nobitex-Signature'], '-_', '+/'));
        $this->assertTrue(
            sodium_crypto_sign_verify_detached($sigRaw, '1700000000POST/users/wallets/list', $pubRaw)
        );
    }

    public function test_missing_keys_throw(): void
    {
        $signer = new NobitexRequestSigner('', '');

        $this->expectException(\RuntimeException::class);
        $signer->signRequest('POST', '/users/wallets/list', '');
    }

    public function test_wrong_length_seed_throws(): void
    {
        // 16-byte "seed" — not a valid Ed25519 seed.
        $bad = $this->urlSafeB64Encode(str_repeat("\x03", 16));
        $signer = new NobitexRequestSigner('pub', $bad);

        $this->expectException(\RuntimeException::class);
        $signer->signRequest('POST', '/users/wallets/list', '');
    }

    private function urlSafeB64Encode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
