<?php
declare(strict_types=1);

namespace App\Enums;

/**
 * نوع اجرای سفارش: مارکت یا لیمیت.
 * - در داخل کد فقط از این enum استفاده می‌کنیم.
 * - برای API نوبیتکس از toApiString()/fromApiString() استفاده می‌شود.
 */
enum ExecutionType: string
{
    case MARKET = 'MARKET';
    case LIMIT  = 'LIMIT';

    /**
     * نگاشت به رشته‌ی API نوبیتکس (lowercase): "market" | "limit".
     */
    public function toApiString(): string
    {
        return match ($this) {
            self::MARKET => 'market',
            self::LIMIT  => 'limit',
        };
    }

    /**
     * نگاشت از مقدار متنی API به enum امن.
     * مقادیر ناشناخته → InvalidArgumentException
     */
    public static function fromApiString(string $value): self
    {
        $v = strtolower(trim($value));
        return match ($v) {
            'market' => self::MARKET,
            'limit'  => self::LIMIT,
            default  => throw new \InvalidArgumentException("Unknown ExecutionType from API: {$value}"),
        };
    }

    /**
     * آیا از نوع مارکت است؟
     */
    public function isMarket(): bool
    {
        return $this === self::MARKET;
    }

    /**
     * آیا از نوع لیمیت است؟
     */
    public function isLimit(): bool
    {
        return $this === self::LIMIT;
    }

    /**
     * آیا قیمت الزامی‌ست؟ (برای مارکت خیر، برای لیمیت بله)
     */
    public function isPriceRequired(): bool
    {
        return $this === self::LIMIT;
    }
}
