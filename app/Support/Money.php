<?php
declare(strict_types=1);

namespace App\Support;

/**
 * کلاس کمکی برای محاسبات پولی با دقت بالا (bcmath) و رعایت tick-size مارکت.
 * - تمام مقادیر پولی/قیمتی به‌صورت string در bcmath نگه‌داری و پردازش می‌شوند.
 */
final class Money
{
    /**
     * گرد کردن مقدار به دقت اعشاری مشخص (round half-up).
     */
    public static function round(string $value, int $scale): string
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException('Scale must be non-negative.');
        }
        $factor = bcpow('10', (string) $scale, 0);
        $rounded = bcdiv((string) round((float) bcmul($value, $factor, $scale + 1)), $factor, $scale);
        return self::trimZeros($rounded);
    }

    /**
     * نشاندن مقدار روی tick-size (مرحله قیمتی مارکت)
     * - mode: floor|ceil|round
     */
    public static function alignToTick(string $value, string $tickSize, string $mode = 'floor'): string
    {
        if (bccomp($tickSize, '0', 8) <= 0) {
            throw new \InvalidArgumentException('Tick size must be > 0');
        }

        $div = bcdiv($value, $tickSize, 8);
        switch (strtolower($mode)) {
            case 'ceil':
                $div = (string) ceil((float) $div);
                break;
            case 'round':
                $div = (string) round((float) $div);
                break;
            default: // floor
                $div = (string) floor((float) $div);
        }
        $aligned = bcmul($div, $tickSize, 8);
        return self::trimZeros($aligned);
    }

    /**
     * تبدیل ریال (IRT) به BTC/USDT/... با دقت بالا.
     * @param int $priceIRT قیمت 1 واحد ارز پایه به ریال (IRT)
     */
    public static function irtToBase(int $irtAmount, int $priceIRT, int $scale = 8): string
    {
        if ($priceIRT <= 0) {
            throw new \InvalidArgumentException('Price must be > 0');
        }
        return self::trimZeros(bcdiv((string) $irtAmount, (string) $priceIRT, $scale));
    }

    /**
     * تبدیل BTC/USDT/... به ریال (IRT) با دقت بالا.
     * @param string $amountBase مقدار ارز پایه (decimal string)
     * @param int $priceIRT قیمت 1 واحد ارز پایه به ریال (IRT)
     */
    public static function baseToIrt(string $amountBase, int $priceIRT, int $scale = 0): string
    {
        if ($priceIRT <= 0) {
            throw new \InvalidArgumentException('Price must be > 0');
        }
        return self::trimZeros(bcmul($amountBase, (string) $priceIRT, $scale));
    }

    /**
     * جمع دو مقدار پولی.
     */
    public static function add(string $a, string $b, int $scale = 8): string
    {
        return self::trimZeros(bcadd($a, $b, $scale));
    }

    /**
     * تفریق دو مقدار پولی.
     */
    public static function sub(string $a, string $b, int $scale = 8): string
    {
        return self::trimZeros(bcsub($a, $b, $scale));
    }

    /**
     * ضرب دو مقدار پولی.
     */
    public static function mul(string $a, string $b, int $scale = 8): string
    {
        return self::trimZeros(bcmul($a, $b, $scale));
    }

    /**
     * تقسیم دو مقدار پولی (با safe-div)
     */
    public static function div(string $a, string $b, int $scale = 8): string
    {
        if (bccomp($b, '0', $scale) === 0) {
            throw new \DivisionByZeroError('Division by zero.');
        }
        return self::trimZeros(bcdiv($a, $b, $scale));
    }

    /**
     * حذف صفرهای اضافه در انتهای رشته اعشاری.
     */
    public static function trimZeros(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value;
    }
}
