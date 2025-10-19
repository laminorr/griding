<?php

declare(strict_types=1);

namespace App\DTOs;

final class OrderBookDto
{
    /**
     * @param array<int, array{price:int, quantity:string}> $bids  // descending
     * @param array<int, array{price:int, quantity:string}> $asks  // ascending
     */
    public function __construct(
        public string $symbol,
        public int $lastPrice,   // IRT (int)
        public int $ts,          // unix timestamp (seconds)
        public array $bids,
        public array $asks,
    ) {}

    /** ورودی خام API را به DTO امن تبدیل می‌کند */
    public static function fromApi(array $raw, string $symbol): self
    {
        $last = (int)($raw['lastTradePrice'] ?? $raw['last'] ?? $raw['lastPrice'] ?? 0);
        $ts   = (int)($raw['time'] ?? $raw['ts'] ?? time());

        $norm = function (?array $side): array {
            $side ??= [];
            return array_values(array_map(
                function ($row) {
                    // پشتیبانی از هر دو حالت ['price'=>..,'volume'=>..] یا [price,volume]
                    $price = isset($row['price']) ? (int)$row['price'] : (int)($row[0] ?? 0);
                    $qty   = isset($row['volume']) ? (string)$row['volume'] : (string)($row[1] ?? '0');
                    return ['price' => $price, 'quantity' => $qty];
                },
                $side
            ));
        };

        $bids = $norm($raw['bids'] ?? $raw['bid'] ?? $raw['buy']  ?? []);
        $asks = $norm($raw['asks'] ?? $raw['ask'] ?? $raw['sell'] ?? []);

        return new self(
            symbol: $symbol,
            lastPrice: $last,
            ts: $ts,
            bids: $bids,
            asks: $asks,
        );
    }

    public function midPrice(): int
    {
        $bestBid = $this->bids[0]['price'] ?? $this->lastPrice;
        $bestAsk = $this->asks[0]['price'] ?? $this->lastPrice;
        return (int) \intval(($bestBid + $bestAsk) / 2);
    }

    public function toArray(): array
    {
        return [
            'symbol'    => $this->symbol,
            'lastPrice' => $this->lastPrice,
            'ts'        => $this->ts,
            'bids'      => $this->bids,
            'asks'      => $this->asks,
        ];
    }
}
