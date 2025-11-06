<?php
declare(strict_types=1);

namespace App\Enums;

/**
 * وضعیت سفارش در سیستم گرید.
 * - مقادیر بر اساس حالت‌های متداول در چرخه سفارشات نوبیتکس + منطق داخلی ما.
 * - این enum در DB به‌صورت string ذخیره می‌شود (برای انعطاف در آینده).
 */
enum GridOrderStatus: string
{
    case PENDING   = 'PENDING';   // سفارش آماده ثبت در صرافی، هنوز ارسال نشده
    case ACTIVE    = 'ACTIVE';    // سفارش ثبت شده و در حال انتظار اجرا
    case FILLED    = 'FILLED';    // سفارش به طور کامل اجرا شده
    case CANCELED  = 'CANCELED';  // سفارش لغو شده (دستی یا سیستمی)
    case ERROR     = 'ERROR';     // خطا در ثبت یا پیگیری سفارش

    /**
     * آیا سفارش نهایی شده است؟ (Filled یا Canceled)
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::FILLED, self::CANCELED => true,
            default => false,
        };
    }

    /**
     * آیا سفارش در حالت فعال است؟
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * آیا سفارش با خطا مواجه شده؟
     */
    public function isError(): bool
    {
        return $this === self::ERROR;
    }

    /**
     * نگاشت از رشتهٔ API یا دیتابیس به enum امن.
     */
    public static function fromString(string $value): self
    {
        $v = strtoupper(trim($value));

        // Map Nobitex API status values to our internal enum
        $v = match ($v) {
            'DONE' => 'FILLED',                    // Nobitex: order fully matched
            'ACTIVE' => 'ACTIVE',                  // Nobitex: order is open
            'CANCELED', 'CANCELLED' => 'CANCELED', // Both spellings
            'INACTIVE' => 'CANCELED',              // Nobitex: order inactive/expired
            'PENDING' => 'PENDING',                // Order submitted but not confirmed
            default => $v,                         // Keep as-is
        };

        return match ($v) {
            'PENDING'   => self::PENDING,
            'ACTIVE'    => self::ACTIVE,
            'FILLED'    => self::FILLED,
            'CANCELED'  => self::CANCELED,
            'ERROR'     => self::ERROR,
            default     => throw new \InvalidArgumentException("Unknown GridOrderStatus: {$value}"),
        };
    }
}
