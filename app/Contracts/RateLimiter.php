<?php
// ==========================
// FILE: app/Contracts/RateLimiter.php
// ==========================

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\RateLimitExceededException;

/**
 * قرارداد لیمیت‌کنندهٔ نرخ (fixed-window counter, cache-backed).
 *
 * الگو:
 *  - acquire(): تلاش برای دریافت فوری یک/چند permit؛ true اگر موفق.
 *  - reserve(): تعداد میلی‌ثانیه‌ای که باید صبر کنیم تا permit آزاد شود.
 *  - available(): تعداد permitهای باقی‌ماندهٔ پنجرهٔ فعلی.
 *  - block(): دروازهٔ مسدودکننده — تا آزاد شدن permit یا سررسید maxWait صبر می‌کند.
 *
 * The original thin primitives (acquire/reserve/available) are kept. A fourth
 * method — block() — is added: the enforcing gate Nobitex needs is a
 * blocking-with-deadline operation that cannot be expressed by the immediate
 * primitives alone. The interface previously had zero implementations and zero
 * consumers, so adding a method breaks nothing.
 */
interface RateLimiter
{
    /**
     * تلاش فوری برای دریافت permit؛ در صورت موفقیت permit مصرف می‌شود.
     */
    public function acquire(string $key, int $tokens = 1): bool;

    /**
     * تعداد میلی‌ثانیه تا آزاد شدن permit بعدی؛ 0 یعنی هم‌اکنون در دسترس است.
     * این متد permit مصرف نمی‌کند.
     */
    public function reserve(string $key, int $tokens = 1): int;

    /** تعداد permitهای فعلاً قابل برداشت در پنجرهٔ جاری. */
    public function available(string $key): int;

    /**
     * تا زمانی که یک permit آزاد شود مسدود می‌ماند و آن را مصرف می‌کند؛
     * اگر ظرف $maxWaitMs میلی‌ثانیه permit فراهم نشود استثنا پرتاب می‌کند.
     *
     * @throws RateLimitExceededException وقتی سقف انتظار سپری شود بدون گرفتن permit.
     */
    public function block(string $key, int $maxWaitMs, int $tokens = 1): void;
}
