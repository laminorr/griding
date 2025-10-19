<?php
declare(strict_types=1);

namespace App\Enums;

/**
 * سمت سفارش: خرید یا فروش.
 * - در کد داخلی همیشه از این enum استفاده می‌کنیم.
 * - برای API نوبیتکس، از toApiString()/fromApiString() استفاده می‌کنیم.
 */
enum OrderSide: string
{
    case BUY  = 'BUY';
    case SELL = 'SELL';

    /**
     * نگاشت استاندارد به رشته‌ی API نوبیتکس (lowercase).
     * Nobitex expects: "buy" | "sell"
     */
    public function toApiString(): string
    {
        return match ($this) {
            self::BUY  => 'buy',
            self::SELL => 'sell',
        };
    }

    /**
     * نگاشت از مقدار متنی API به enum امن ما.
     * مقادیر ناشناخته → InvalidArgumentException
     */
    public static function fromApiString(string $value): self
    {
        $v = strtolower(trim($value));
        return match ($v) {
            'buy'  => self::BUY,
            'sell' => self::SELL,
            default => throw new \InvalidArgumentException("Unknown OrderSide from API: {$value}"),
        };
    }

    /**
     * آیا سمت خرید است؟
     */
    public function isBuy(): bool
    {
        return $this === self::BUY;
    }

    /**
     * آیا سمت فروش است؟
     */
    public function isSell(): bool
    {
        return $this === self::SELL;
    }

    /**
     * سمت معکوس (برای سفارشِ جایگزین پس از fill).
     */
    public function opposite(): self
    {
        return $this === self::BUY ? self::SELL : self::BUY;
    }
}
