<?php
declare(strict_types=1);

namespace App\DTOs;

use App\Enums\GridOrderStatus;
use App\Enums\OrderSide;
use App\Enums\ExecutionType;

/**
 * DTO وضعیت سفارش.
 * - نگه‌داری داده‌های اصلی یک سفارش ثبت‌شده در صرافی.
 */
final readonly class OrderStatusDto
{
    /**
     * @param string $orderId شناسه سفارش در نوبیتکس
     * @param GridOrderStatus $status وضعیت سفارش (داخلی)
     * @param OrderSide $side سمت سفارش (BUY/SELL)
     * @param ExecutionType $execution نوع اجرا (MARKET/LIMIT)
     * @param string $amountBase مقدار سفارش به ارز پایه (decimal string)
     * @param string $filledBase مقدار پر شده به ارز پایه (decimal string)
     * @param int|null $priceIRT قیمت سفارش (IRT) — برای MARKET ممکن است null باشد
     * @param int $createdAtTs زمان ایجاد (epoch ms)
     * @param int|null $updatedAtTs آخرین بروزرسانی (epoch ms)
     */
    public function __construct(
        public string $orderId,
        public GridOrderStatus $status,
        public OrderSide $side,
        public ExecutionType $execution,
        public string $amountBase,
        public string $filledBase,
        public ?int $priceIRT,
        public int $createdAtTs,
        public ?int $updatedAtTs = null,
    ) {}

    /**
     * ساخت از دادهٔ API نوبیتکس.
     */
    public static function fromApi(array $row): self
    {
        $orderId   = (string) ($row['id'] ?? $row['order'] ?? '');
        $status    = GridOrderStatus::fromString((string) ($row['status'] ?? ''));
        $side      = OrderSide::fromApiString((string) ($row['type'] ?? ''));
        $execution = ExecutionType::fromApiString((string) ($row['execution'] ?? ''));
        $amount    = (string) ($row['amount'] ?? '0');
        $filled    = (string) ($row['matchedAmount'] ?? $row['filled'] ?? '0');
        $price     = isset($row['price']) ? (int) $row['price'] : null;

        // timestamps ممکن است ثانیه یا میلی‌ثانیه باشند → به ms نرمال می‌کنیم
        $createdAt = isset($row['createdAt']) ? self::normalizeTs($row['createdAt']) : (int) round(microtime(true) * 1000);
        $updatedAt = isset($row['updatedAt']) ? self::normalizeTs($row['updatedAt']) : null;

        return new self($orderId, $status, $side, $execution, $amount, $filled, $price, $createdAt, $updatedAt);
    }

    private static function normalizeTs(int|string $ts): int
    {
        $ts = (int) $ts;
        return $ts > 1_000_000_000_000 ? $ts : $ts * 1000;
    }

    public function isFilled(): bool
    {
        return $this->status === GridOrderStatus::FILLED;
    }

    public function isActive(): bool
    {
        return $this->status === GridOrderStatus::ACTIVE;
    }

    public function toArray(): array
    {
        return [
            'orderId'      => $this->orderId,
            'status'       => $this->status->value,
            'side'         => $this->side->value,
            'execution'    => $this->execution->value,
            'amountBase'   => $this->amountBase,
            'filledBase'   => $this->filledBase,
            'priceIRT'     => $this->priceIRT,
            'createdAtTs'  => $this->createdAtTs,
            'updatedAtTs'  => $this->updatedAtTs,
        ];
    }
}