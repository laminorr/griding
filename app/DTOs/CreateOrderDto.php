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



// داخل CreateOrderDto.php
private function canonCurrency(string $c): string
{
    $c = strtolower(trim($c));
    return $c === 'irt' ? 'rls' : $c; // ← IRT را به RLS نگاشت کن
}

public function toApiPayload(): array
{
    $payload = [
        'type'        => $this->side->toApiString(),
        'execution'   => $this->execution->toApiString(),
        'srcCurrency' => $this->canonCurrency($this->srcCurrency),
        'dstCurrency' => $this->canonCurrency($this->dstCurrency),
        'amount'      => $this->amountBase,
    ];
    if ($this->execution->isPriceRequired()) {
        $payload['price'] = (string) $this->priceIRT;
    }
    if ($this->clientRef) {
        $payload['client_ref'] = $this->clientRef;
    }
    return $payload;
}


    /** نگاشت آماده برای API نوبیتکس */
    public function toApiPayload(): array
    {
        $payload = [
            'type'        => $this->side->toApiString(),        // buy|sell
            'execution'   => $this->execution->toApiString(),   // market|limit
            'srcCurrency' => strtolower($this->srcCurrency),
            'dstCurrency' => strtolower($this->dstCurrency),
            'amount'      => $this->amountBase,
        ];
        if ($this->execution->isPriceRequired()) {
            $payload['price'] = (string) $this->priceIRT;       // IRT به‌صورت string
        }
        if ($this->clientRef) {
            $payload['client_ref'] = $this->clientRef;
        }
        return $payload;