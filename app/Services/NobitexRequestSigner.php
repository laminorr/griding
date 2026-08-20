<?php

declare(strict_types=1);

namespace App\Services;

/**
 * NobitexRequestSigner — Ed25519 request signing for Nobitex private endpoints.
 * ------------------------------------------------------------------
 * Nobitex replaced the legacy `Authorization: Token <key>` scheme with per-request
 * Ed25519 signatures. Every authenticated call carries three headers instead:
 *
 *     Nobitex-Key       : <public key>                 (config nobitex.api_public_key)
 *     Nobitex-Signature : <Ed25519 sig, url-safe base64, unpadded>
 *     Nobitex-Timestamp : <unix seconds, UTC>
 *
 * The signature is over the exact concatenation (NO separators):
 *
 *     payload = timestamp + METHOD + full_path + raw_body
 *
 *   • timestamp: the SAME string emitted in Nobitex-Timestamp
 *   • METHOD:    uppercase verb (GET/POST/…)
 *   • full_path: request path INCLUDING any query string
 *                (e.g. "/users/wallets/list" or "/market/orders/list?fromId=1")
 *   • raw_body:  the exact request-body bytes; "" when there is no body
 *
 * Key handling: nobitex.api_private_key is the url-safe base64 of a 32-byte
 * Ed25519 SEED (not the 64-byte secret key). We decode it, derive the keypair,
 * and sign with the derived 64-byte secret key.
 *
 * sodium note: the host PHP (ea-php83) has NO ext-sodium, so paragonie/sodium_compat
 * polyfills sodium_crypto_sign_* in pure PHP. We deliberately do url-safe base64
 * with plain PHP (strtr + base64_*) rather than sodium_bin2base64 /
 * sodium_base642bin, because those base64 helpers were unreliable through the
 * compat layer on the host — this plain-PHP path is the one proven to return
 * HTTP 200 against the live API.
 */
class NobitexRequestSigner
{
    public function __construct(
        private readonly string $publicKey,
        private readonly string $privateKey,
    ) {}

    /**
     * Build a signer from config (config/trading.php → config/services.php mirror).
     * Reads nobitex.api_public_key / nobitex.api_private_key, which resolve from
     * the NOBITEX_API_PUBLIC_KEY / NOBITEX_API_PRIVATE_KEY env vars.
     */
    public static function fromConfig(): self
    {
        return new self(
            (string) (config('trading.nobitex.api_public_key') ?? config('services.nobitex.api_public_key') ?? ''),
            (string) (config('trading.nobitex.api_private_key') ?? config('services.nobitex.api_private_key') ?? ''),
        );
    }

    /**
     * Compute the three Nobitex-* headers for one request.
     *
     * @param  string  $method  HTTP verb (any case; normalised to upper for the payload)
     * @param  string  $fullPath  Path INCLUDING query string when present, e.g. "/users/wallets/list"
     * @param  string  $rawBody  Exact body bytes as sent; "" for an empty body
     * @return array{Nobitex-Key:string, Nobitex-Signature:string, Nobitex-Timestamp:string}
     */
    public function signRequest(string $method, string $fullPath, string $rawBody, ?int $timestamp = null): array
    {
        if ($this->publicKey === '' || $this->privateKey === '') {
            throw new \RuntimeException(
                'NobitexRequestSigner: NOBITEX_API_PUBLIC_KEY / NOBITEX_API_PRIVATE_KEY are not configured.'
            );
        }

        $ts = (string) ($timestamp ?? time());
        $payload = $this->buildPayload($ts, $method, $fullPath, $rawBody);

        $seed = $this->urlSafeB64Decode($this->privateKey);
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \RuntimeException(sprintf(
                'NobitexRequestSigner: private key must decode to a %d-byte Ed25519 seed, got %d bytes.',
                SODIUM_CRYPTO_SIGN_SEEDBYTES,
                strlen($seed)
            ));
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        // NOTE: we deliberately do NOT call sodium_memzero() here. The host runs
        // paragonie/sodium_compat WITHOUT ext-sodium, and its pure-PHP memzero
        // throws SodiumException ("not possible to securely wipe memory from PHP")
        // — which would break signing on the very environment we target. PHP can't
        // truly wipe the string anyway; the locals fall out of scope on return.

        return [
            'Nobitex-Key' => $this->publicKey,
            'Nobitex-Signature' => $this->urlSafeB64Encode($signature),
            'Nobitex-Timestamp' => $ts,
        ];
    }

    /**
     * The exact string that gets signed: timestamp + METHOD + full_path + raw_body,
     * concatenated with no separators. Exposed so tests can lock the contract.
     */
    public function buildPayload(string $timestamp, string $method, string $fullPath, string $rawBody): string
    {
        return $timestamp.strtoupper($method).$fullPath.$rawBody;
    }

    /** url-safe base64 decode (RFC 4648 §5), tolerant of missing padding. */
    private function urlSafeB64Decode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }

    /** url-safe base64 encode, unpadded. */
    private function urlSafeB64Encode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
