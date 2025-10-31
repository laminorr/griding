<?php
declare(strict_types=1);

namespace App\DTOs;

use App\Enums\OrderSide;
use App\Enums\ExecutionType;

/**
 * DTO ورودی ثبت سفارش (Spot / Limit/Market).
 * - priceIRT: عدد صحیح ریالی (برای LIMIT ضروری است)
 * - amountBase: رشتهٔ ده‌دهی با دقت کوین (مثلاً BTC تا 8 رقم)
 * - src/dst: حروف کوچک (btc/irt, eth/usdt, ...)
 */
final readonly class CreateOrderDto
{
    public function __construct(
        public OrderSide $side,            // BUY | SELL
        public ExecutionType $execution,   // MARKET | LIMIT
        public string $srcCurrency,        // e.g. 'btc'
        public string $dstCurrency,        // e.g. 'irt'
        public string $amountBase,         // decimal string (e.g. '0.0015')
        public ?int $priceIRT = null,      // required for LIMIT orders
        public ?string $clientRef = null,  // شناسهٔ مرجع داخلی (اختیاری)
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->execution->isPriceRequired() && ($this->priceIRT === null || $this->priceIRT <= 0)) {
            throw new \InvalidArgumentException('CreateOrderDto: priceIRT is required for LIMIT orders.');
        }
        if (trim($this->amountBase) === '' || bccomp($this->amountBase, '0', 8) <= 0) {
            throw new \InvalidArgumentException('CreateOrderDto: amountBase must be > 0.');
        }
        if ($this->srcCurrency === '' || $this->dstCurrency === '') {
            throw new \InvalidArgumentException('CreateOrderDto: src/dst currency are required.');
        }
    }

public function toApiPayload(): array
{
    // Get precision from config (default 8 for BTC)
    $amountPrecision = 8; // BTC standard

    // Ensure we're working with strings
    $amountStr = (string) $this->amountBase;

    // Truncate amount to proper precision (don't round, truncate)
    if (str_contains($amountStr, '.')) {
        [$integer, $decimal] = explode('.', $amountStr, 2);
        $decimal = substr($decimal, 0, $amountPrecision);
        $amountStr = $integer . '.' . rtrim($decimal, '0');
        $amountStr = rtrim($amountStr, '.'); // Remove trailing dot if no decimals left
    }

    $payload = [
        'type'        => $this->side->toApiString(),
        'execution'   => $this->execution->toApiString(),
        'srcCurrency' => strtolower($this->srcCurrency),
        'dstCurrency' => strtolower($this->dstCurrency),
        'amount'      => $amountStr,  // Clean string: "0.0001678"
    ];

    if ($this->execution->isPriceRequired()) {
        // Ensure price is integer string (remove any .0)
        $priceStr = (string) $this->priceIRT;
        if (str_contains($priceStr, '.')) {
            $priceStr = explode('.', $priceStr, 2)[0];
        }
        $payload['price'] = $priceStr;   // Clean integer string: "122169745338"
    }

    if ($this->clientRef) {
        $payload['client_ref'] = $this->clientRef;
    }

    return $payload;
}
}