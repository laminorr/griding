<?php
declare(strict_types=1);

namespace App\DTOs;

use App\Enums\OrderSide;
use App\Enums\ExecutionType;
use App\Exceptions\DefinitiveInvalidArgumentRejection;
use App\Models\GridOrder;
use App\Support\QtyPrecision;

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
    // Determine symbol for config lookup
    // Assuming srcCurrency + dstCurrency in uppercase forms the symbol
    $symbol = strtoupper($this->srcCurrency . $this->dstCurrency);

    // Ensure we're working with strings from the start
    $priceStr = (string) $this->priceIRT;

    // Truncate amount DOWN to the market quantity step (never round up). The
    // shared helper is the same one NobitexService::placeOrder uses, so grid
    // orders and exit orders are fitted identically.
    $amountStr = QtyPrecision::floor((string) $this->amountBase, $symbol);

    // Ensure price is integer string (remove any .0)
    if (str_contains($priceStr, '.')) {
        $priceStr = explode('.', $priceStr, 2)[0];
    }

    // CRITICAL FIX: For IRT markets (BTCIRT, ETHIRT, USDTIRT), API expects 'rls'
    $dstForApi = strtolower($this->dstCurrency);
    if ($dstForApi === 'irt') {
        $dstForApi = 'rls';  // IRT is display label only; API uses 'rls'
    }

    $payload = [
        'type'        => $this->side->toApiString(),
        'execution'   => $this->execution->toApiString(),
        'srcCurrency' => strtolower($this->srcCurrency),
        'dstCurrency' => $dstForApi,  // 'rls' for IRT markets
        'amount'      => $amountStr,  // Clean string with proper precision
    ];

    if ($this->execution->isPriceRequired()) {
        $payload['price'] = $priceStr;   // Clean integer string
    }

    if ($this->clientRef !== null) {
        // Send boundary: refuse an id Nobitex would reject (> 32 chars or
        // outside [A-Za-z0-9-]) before anything leaves the process. Definitive
        // — no order can exist — so callers cancel the intent row instead of
        // parking it as submission_unknown.
        if (! GridOrder::isValidNobitexClientOrderId($this->clientRef)) {
            throw DefinitiveInvalidArgumentRejection::withCode(
                'LocalValidation',
                'Invalid clientOrderId for Nobitex (max 32 chars, [A-Za-z0-9-] only): ' . $this->clientRef
            );
        }

        // Official Nobitex field name for the client-supplied order tag.
        // (Documented as experimental — see NobitexService::getOrderByClientOrderId.)
        $payload['clientOrderId'] = $this->clientRef;
    }

    return $payload;
}
}