<?php
// ==========================
// FILE: app/Contracts/ExchangeClient.php
// ==========================

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ApiOkDto;
use App\DTOs\BalanceDto;
use App\DTOs\CreateOrderDto;
use App\DTOs\CreateOrderResponse;
use App\DTOs\OrderBookDto;
use App\DTOs\OrderStatusDto;
use App\DTOs\WalletsDto;

/**
 * قرارداد کلاینت اکسچنج (REST) — ثبت/لغو سفارش، وضعیت سفارشات، کیف‌پول‌ها.
 *
 * نکته: دریافت قیمت/اوردر‌بوک «می‌تواند» اینجا باشد، اما در معماری ما
 * MarketData لایهٔ اصلی برای قیمت‌هاست؛ این متد به‌عنوان fallback/utility مفید است.
 */
interface ExchangeClient
{
    /** ثبت سفارش جدید */
    public function createOrder(CreateOrderDto $dto): CreateOrderResponse;

    /** لغو یک سفارش */
    public function cancelOrder(string $orderId): ApiOkDto;

    /**
     * وضعیت چند سفارش به‌صورت batch
     * @param array<int,string> $orderIds
     * @return array<int,OrderStatusDto>
     */
    public function getOrdersStatus(array $orderIds): array;

    /** موجودی یک ارز خاص */
    public function getBalance(string $currency): BalanceDto;

    /** فهرست کیف‌پول‌ها */
    public function getWallets(): WalletsDto;

    /** دریافت اوردر‌بوک/آخرین قیمت (اختیاری؛ برای fallback) */
    public function getOrderBook(string $symbol): OrderBookDto;
}
