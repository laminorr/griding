<?php
declare(strict_types=1);

namespace App\DTOs;

/**
 * DTO خروجی ثبت سفارش.
 * - نگه‌داری نتیجهٔ ثبت سفارش در API نوبیتکس.
 * - حاوی موفقیت/شکست، شناسهٔ سفارش و پیام/خطا.
 */
final readonly class CreateOrderResponse
{
    /**
     * @param bool $ok          آیا سفارش با موفقیت ثبت شد؟
     * @param string|null $orderId شناسهٔ سفارش (در صورت موفقیت)
     * @param string|null $message پیام اضافی یا خطا (در صورت وجود)
     */
    public function __construct(
        public bool $ok,
        public ?string $orderId = null,
        public ?string $message = null,
    ) {
    }

    /**
     * ساخت از پاسخ API نوبیتکس.
     * انتظار: { "status":"ok", "order":123456, ... }
     */
    public static function fromApi(array $payload): self
    {
        $ok = strtolower((string) ($payload['status'] ?? '')) === 'ok';
        $orderId = isset($payload['order']) ? (string) $payload['order'] : null;

        // در برخی پاسخ‌ها ممکن است "message" یا "error" یا "errors" وجود داشته باشد
        $msg = $payload['message']
            ?? $payload['error']
            ?? ($payload['errors'][0] ?? null)
            ?? null;

        return new self($ok, $orderId, $msg);
    }

    /**
     * آیا پاسخ موفق بوده است؟
     */
    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * آیا پاسخ دارای خطاست؟
     */
    public function isError(): bool
    {
        return !$this->ok;
    }

    /**
     * تبدیل به آرایه (برای لاگ یا ریسپانس داخلی)
     */
    public function toArray(): array
    {
        return [
            'ok'       => $this->ok,
            'orderId'  => $this->orderId,
            'message'  => $this->message,
        ];
    }
}
