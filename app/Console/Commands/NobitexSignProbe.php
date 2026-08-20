<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * NobitexSignProbe (ISOLATED THROWAWAY PROBE — PART 1)
 * ------------------------------------------------------------------
 * Proves the new Nobitex Ed25519 request-signing auth scheme end-to-end
 * WITHOUT touching NobitexService / config / anything else.
 *
 * New auth scheme (apidocs.nobitex.ir/api_key/api-key-guide):
 *   Headers instead of `Authorization: Token <key>`:
 *     - Nobitex-Key:       public key
 *     - Nobitex-Signature: Ed25519 signature of the payload, URL-safe Base64
 *     - Nobitex-Timestamp: Unix seconds, UTC (must be within 30s of server)
 *
 *   payload = timestamp + METHOD + full_path + raw_body    (NO separators)
 *
 * Signing key = private key (URL-safe base64). libsodium wants a 64-byte
 * secret key (seed+public); the base64 secretKey may be a 32-byte SEED, so
 * we branch on the decoded length.
 *
 * Keys come from NEW env vars so existing config is untouched:
 *   NOBITEX_API_PUBLIC_KEY   (apiKey / public "key")
 *   NOBITEX_API_PRIVATE_KEY  (secretKey / privateKey, base64 url-safe)
 *
 * Run on the host: ea-php83 artisan nobitex:sign-probe
 */
class NobitexSignProbe extends Command
{
    protected $signature = 'nobitex:sign-probe';
    protected $description = 'ISOLATED probe: prove Nobitex Ed25519 request signing returns 200 (does NOT touch NobitexService)';

    private const BASE_URL   = 'https://apiv2.nobitex.ir';
    private const USER_AGENT = 'TraderBot/Griding-1.0';

    public function handle(): int
    {
        $this->info('==========================================');
        $this->info(' Nobitex Ed25519 sign probe (isolated)');
        $this->info('==========================================');

        if (!extension_loaded('sodium')) {
            $this->error('ext-sodium is NOT loaded. Cannot sign Ed25519 payloads.');
            $this->line('  → Enable ext-sodium (bundled since PHP 7.2) and re-run.');
            return self::FAILURE;
        }
        $this->line('ext-sodium: loaded ✔');

        $publicKey  = (string) env('NOBITEX_API_PUBLIC_KEY', '');
        $privateKey = (string) env('NOBITEX_API_PRIVATE_KEY', '');

        if ($publicKey === '' || $privateKey === '') {
            $this->error('Missing keys. Set BOTH env vars in .env before running:');
            $this->line('  NOBITEX_API_PUBLIC_KEY   = the apiKey / public "key"');
            $this->line('  NOBITEX_API_PRIVATE_KEY  = the secretKey / privateKey (base64 url-safe)');
            return self::FAILURE;
        }

        $this->line('Public key (first 12): ' . substr($publicKey, 0, 12) . '...');

        // Derive the 64-byte libsodium secret key once (seed vs full key handled here).
        try {
            $secretKey64 = $this->deriveSecretKey($privateKey);
        } catch (\Throwable $e) {
            $this->error('Failed to derive signing key: ' . $e->getMessage());
            return self::FAILURE;
        }

        // --- Test 1: GET /users/profile (READ) --------------------------------
        $this->newLine();
        $this->info('--- Test 1: GET /users/profile ---');
        $ok1 = $this->probe($publicKey, $secretKey64, 'GET', '/users/profile', null);

        // --- Test 2: POST /users/wallets/list (balances) ----------------------
        // raw_body for the signature MUST equal the exact bytes sent. Try empty
        // body first; if it 400s for a missing body, retry signing "{}".
        $this->newLine();
        $this->info('--- Test 2: POST /users/wallets/list (empty body) ---');
        $status2 = $this->probe($publicKey, $secretKey64, 'POST', '/users/wallets/list', '');

        if ($status2 === 400) {
            $this->newLine();
            $this->warn('Empty body returned 400 — retrying with raw_body = "{}"');
            $this->info('--- Test 2b: POST /users/wallets/list (body "{}") ---');
            $this->probe($publicKey, $secretKey64, 'POST', '/users/wallets/list', '{}');
        }

        $this->newLine();
        $this->info($ok1 === 200
            ? 'SUCCESS: /users/profile returned 200. Auth scheme proven.'
            : 'Not a 200 on /users/profile yet — see debug output above.');

        return $ok1 === 200 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Decode the URL-safe base64 private key and return a 64-byte libsodium
     * secret key. 32 bytes = seed → derive keypair; 64 bytes = use directly.
     */
    private function deriveSecretKey(string $privateKeyB64): string
    {
        $raw = $this->urlSafeB64Decode($privateKeyB64);
        $len = strlen($raw);
        $this->line("Decoded private key length: {$len} bytes " . match (true) {
            $len === 32 => '(32 = SEED → deriving full keypair)',
            $len === 64 => '(64 = full secret key → using directly)',
            default     => '(UNEXPECTED length!)',
        });

        if ($len === 32) {
            $keypair = sodium_crypto_sign_seed_keypair($raw);
            return sodium_crypto_sign_secretkey($keypair);
        }
        if ($len === 64) {
            return $raw;
        }

        throw new \RuntimeException("Unexpected decoded private-key length: {$len} (expected 32 or 64)");
    }

    /**
     * Decode URL-safe base64 tolerantly (with or without padding, and falling
     * back to standard base64 alphabet) so a real key value decodes cleanly.
     */
    private function urlSafeB64Decode(string $b64): string
    {
        $b64 = trim($b64);
        $variants = [
            SODIUM_BASE64_VARIANT_URLSAFE,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            SODIUM_BASE64_VARIANT_ORIGINAL,
            SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING,
        ];
        foreach ($variants as $variant) {
            try {
                return sodium_base642bin($b64, $variant, '');
            } catch (\Throwable) {
                // try next variant
            }
        }
        throw new \RuntimeException('Could not base64-decode NOBITEX_API_PRIVATE_KEY with any known variant.');
    }

    /**
     * Sign + send one request, print debug, return the HTTP status (0 on
     * transport failure).
     */
    private function probe(string $publicKey, string $secretKey64, string $method, string $fullPath, ?string $rawBody): int
    {
        $timestamp = (string) time();
        $bodyForSig = $rawBody ?? '';                 // EMPTY string when no body
        $payload    = $timestamp . strtoupper($method) . $fullPath . $bodyForSig;

        $sigBin = sodium_crypto_sign_detached($payload, $secretKey64);
        $sigB64 = sodium_bin2base64($sigBin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->line("timestamp   : {$timestamp}");
        $this->line("method      : " . strtoupper($method));
        $this->line("full_path   : {$fullPath}");
        $this->line('raw_body    : ' . ($rawBody === null ? '(none — empty string)' : "\"{$rawBody}\""));
        $this->line("payload     : {$payload}");
        $this->line('signature   : ' . substr($sigB64, 0, 12) . '... (' . strlen($sigB64) . ' chars)');

        $headers = [
            'Nobitex-Key'       => $publicKey,
            'Nobitex-Signature' => $sigB64,
            'Nobitex-Timestamp' => $timestamp,
            'User-Agent'        => self::USER_AGENT,
        ];

        try {
            $req = Http::withHeaders($headers)->timeout(15);

            if (strtoupper($method) === 'GET') {
                $response = $req->get(self::BASE_URL . $fullPath);
            } else {
                // Send the EXACT raw body bytes we signed (or nothing at all).
                if ($rawBody === null || $rawBody === '') {
                    $response = $req->send($method, self::BASE_URL . $fullPath);
                } else {
                    $response = $req
                        ->withBody($rawBody, 'application/json')
                        ->send($method, self::BASE_URL . $fullPath);
                }
            }

            $status = $response->status();
            $this->line("HTTP status : {$status}");
            $this->line('response    : ' . substr($response->body(), 0, 200));
            return $status;
        } catch (\Throwable $e) {
            $this->error('Transport error (container may not reach ' . self::BASE_URL . '): ' . $e->getMessage());
            return 0;
        }
    }
}
