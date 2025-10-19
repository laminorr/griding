<?php
// ==========================
// FILE: app/Contracts/CircuitBreaker.php (اختیاری)
// ==========================

declare(strict_types=1);

namespace App\Contracts;

/**
 * قرارداد مدارشکن برای کنترل خطاهای پیاپی سرویس‌های بیرونی.
 */
interface CircuitBreaker
{
    /** آیا مدار برای نام سرویس باز است (اجرا ممنوع)؟ */
    public function isOpen(string $name): bool;

    /** ثبت موفقیت یک تماس */
    public function onSuccess(string $name): void;

    /** ثبت شکست/خطا در یک تماس */
    public function onFailure(string $name): void;

    /**
     * اجرای امن یک فراخوانی زیر چتر مدارشکن.
     *
     * @param callable $fn  کدی که باید اجرا شود
     * @return mixed        نتیجهٔ فراخوانی در صورت مجاز بودن
     * @throws \RuntimeException اگر مدار باز باشد یا خطا رخ دهد
     */
    public function execute(string $name, callable $fn): mixed;
}
