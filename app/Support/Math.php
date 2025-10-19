<?php
declare(strict_types=1);

namespace App\Support;

/**
 * کلاس کمکی برای محاسبات عمومی ریاضی (grid spacing، درصد، safe-div و ...).
 * - از bcmath برای دقت بالا استفاده می‌شود.
 */
final class Math
{
    /**
     * محاسبه درصد تغییر از مقدار a به b.
     * نتیجه: (b - a) / a * 100
     */
    public static function percentChange(string $a, string $b, int $scale = 4): string
    {
        if (bccomp($a, '0', $scale) === 0) {
            throw new \DivisionByZeroError('Division by zero in percentChange.');
        }
        return bcmul(bcdiv(bcsub($b, $a, $scale + 2), $a, $scale + 2), '100', $scale);
    }

    /**
     * محاسبه مقدار a به اضافه/کم کردن درصدی مشخص.
     * percent مثبت: افزایش، منفی: کاهش.
     */
    public static function applyPercent(string $a, string $percent, int $scale = 8): string
    {
        return bcadd($a, bcmul($a, bcdiv($percent, '100', $scale + 2), $scale + 2), $scale);
    }

    /**
     * فاصله بین دو قیمت بر حسب درصد (همیشه مقدار مثبت).
     */
    public static function gapPercent(string $a, string $b, int $scale = 4): string
    {
        if (bccomp($a, '0', $scale) === 0) {
            throw new \DivisionByZeroError('Division by zero in gapPercent.');
        }
        $gap = bcdiv(bcsub($b, $a, $scale + 2), $a, $scale + 2);
        return bcmul(abs((float) $gap), '100', $scale);
    }

    /**
     * تقسیم امن (Safe-div) — اگر مخرج صفر بود، استثنا می‌اندازد.
     */
    public static function safeDiv(string $a, string $b, int $scale = 8): string
    {
        if (bccomp($b, '0', $scale) === 0) {
            throw new \DivisionByZeroError('Division by zero.');
        }
        return bcdiv($a, $b, $scale);
    }

    /**
     * محاسبه قیمت یک سطح گرید بر اساس فرمول لگاریتمی:
     * price = centerPrice * (1 ± spacingPercent/100)^level
     * direction: +1 برای فروش (بالا)، -1 برای خرید (پایین)
     */
    public static function gridLevelPrice(string $centerPrice, string $spacingPercent, int $level, int $direction, int $scale = 0): string
    {
        if (!in_array($direction, [1, -1], true)) {
            throw new \InvalidArgumentException('Direction must be 1 (sell) or -1 (buy).');
        }
        $multiplier = bcpow(
            bcadd('1', bcmul((string) $direction, bcdiv($spacingPercent, '100', $scale + 4), $scale + 4), $scale + 4),
            (string) $level,
            $scale + 4
        );
        return bcadd('0', bcmul($centerPrice, $multiplier, $scale), $scale);
    }
}
