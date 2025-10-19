<?php
// ==========================
// FILE: app/Contracts/RateLimiter.php (اختیاری)
// ==========================

declare(strict_types=1);

namespace App\Contracts;

/**
 * قرارداد لیمیت‌کنندهٔ نرخ (Token Bucket/Leaky Bucket).
 *
 * الگوی پیشنهادی:
 *  - reserve(): تعداد میلی‌ثانیه‌ای که باید صبر کنیم تا توکن‌ها فراهم شود.
 *  - acquire(): تلاش برای دریافت فوری؛ true اگر موفق، false اگر باید صبر کنیم.
 */
interface RateLimiter
{
    /**
     * درخواست n توکن؛ مقدار delay موردنیاز (ms) را برمی‌گرداند.
     * اگر 0 بود یعنی همین حالا قابل اجرا است.
     */
    public function reserve(string $key, int $tokens = 1): int;

    /** تلاش فوری برای دریافت؛ موفق/ناموفق */
    public function acquire(string $key, int $tokens = 1): bool;

    /** تعداد توکن‌های فعلاً قابل برداشت */
    public function available(string $key): int;
}
