<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NobitexAuthService
{
    private const CACHE_KEY = 'nobitex:api_token';

    public function getToken(bool $force = false): string
    {
        if (!$force) {
            $token = Cache::get(self::CACHE_KEY);
            if (is_string($token) && strlen($token) >= 32) {
                return $token;
            }
        }
        $token = $this->loginAndGetToken();
        // اگر remember=yes است، 29 روزه کش می‌کنیم (کمی کمتر از 30 روز برای حاشیه امن)
        $remember = (bool) (config('trading.nobitex.auth.remember') ?? true);
        $ttl = $remember ? now()->addDays(29) : now()->addHours(3); // توکن پیش‌فرض 4 ساعت است
        Cache::put(self::CACHE_KEY, $token, $ttl);
        return $token;
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function loginAndGetToken(): string
    {
        $base = rtrim((string) config('trading.nobitex.base_url'), '/');
        $url  = $base . '/auth/login/'; // ← حتماً اسلش پایانی
        $username = (string) config('trading.nobitex.auth.username');
        $password = (string) config('trading.nobitex.auth.password');
        $remember = (bool)  (config('trading.nobitex.auth.remember') ?? true);
        $totpSecret = (string) (config('trading.nobitex.auth.totp_secret') ?? '');

        if ($username === '' || $password === '') {
            throw new \RuntimeException('NobitexAuthService: username/password در .env تنظیم نشده است.');
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'captcha'  => 'api',
        ];
        if ($remember) {
            $payload['remember'] = 'yes';
        }

        $req = Http::asJson();
        // اگر 2FA فعال است و سکرت داریم، کد TOTP را محاسبه و در هدر بفرستیم
        if ($totpSecret !== '') {
            $req = $req->withHeaders(['X-TOTP' => $this->generateTotp($totpSecret)]);
        }

        $res = $req->post($url, $payload)->json();

        // پاسخ مستندات: { status: "success", key: "..." }
        // https://apidocs.nobitex.ir/  (بخش احراز هویت /auth/login/) 
        if (!is_array($res) || !isset($res['key'])) {
            $msg = is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : 'no-json';
            throw new \RuntimeException('NobitexAuthService: login failed: ' . $msg);
        }
        return (string) $res['key'];
    }

    /**
     * تولید کد TOTP (6 رقمی، دوره 30 ثانیه) از secret به فرمت Base32
     * بدون وابستگی خارجی
     */
    private function generateTotp(string $base32Secret, int $period = 30, int $digits = 6): string
    {
        $secret = $this->base32Decode(strtoupper($base32Secret));
        if ($secret === '') {
            throw new \RuntimeException('NobitexAuthService: TOTP secret نامعتبر است.');
        }
        $counter = intdiv(time(), $period);
        $binCounter = pack('J', $counter); // 64-bit unsigned, machine byte order
        if (pack('L', 1) === pack('N', 1)) { // big-endian check
            // already big-endian
        } else {
            $binCounter = strrev($binCounter);
        }
        $hash = hash_hmac('sha1', $binCounter, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;
        $code = $value % (10 ** $digits);
        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = preg_replace('/[^A-Z2-7]/', '', $data ?? '') ?? '';
        $bits = '';
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $val = strpos($alphabet, $data[$i]);
            if ($val === false) continue;
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
