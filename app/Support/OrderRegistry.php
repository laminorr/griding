<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class OrderRegistry
{
    protected function key(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        return "gridbot:open_orders:{$symbol}";
    }

    /**
     * @return array<int,array{id?:string,side:string,price:int,quantity:string}>
     */
    public function getOpen(string $symbol): array
    {
        return array_values((array) Cache::get($this->key($symbol), []));
    }

    /** @param array{id?:string,side:string,price:int,quantity:string} $order */
    public function remember(string $symbol, array $order): void
    {
        $key = $this->key($symbol);
        $bag = (array) Cache::get($key, []);
        $id  = (string) ($order['id'] ?? uniqid('L'));
        $bag[$id] = ['id'=>$id] + $order;
        Cache::forever($key, $bag);
    }

    public function forget(string $symbol, string $id): void
    {
        $key = $this->key($symbol);
        $bag = (array) Cache::get($key, []);
        unset($bag[$id]);
        Cache::forever($key, $bag);
    }

    /** bulk replace (اختیاری) */
    public function replaceAll(string $symbol, array $orders): void
    {
        $key = $this->key($symbol);
        $bag = [];
        foreach ($orders as $o) {
            $id = (string) ($o['id'] ?? uniqid('L'));
            $bag[$id] = ['id'=>$id] + $o;
        }
        Cache::forever($key, $bag);
    }
}
