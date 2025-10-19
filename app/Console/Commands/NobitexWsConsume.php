<?php

namespace App\Console\Commands;

use App\Services\NobitexWebSocketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NobitexWsConsume extends Command
{
    protected $signature = 'ws:nobitex {--symbols= : CSV e.g. BTCIRT,ETHIRT,USDTIRT}';
    protected $description = 'Consume Nobitex WebSocket and cache last prices (WS-first).';

    public function handle(NobitexWebSocketService $ws): int
    {
        $symbols = $this->option('symbols');
        if (!$symbols) {
            $symbols = implode(',', config('trading.exchange.allowed_symbols', ['BTCIRT']));
        }
        $symbols = array_values(array_filter(array_map('trim', explode(',', $symbols))));

        $ttl = (int) config('trading.cache.price_ttl', 30);

        $this->info('Connecting to Nobitex WS …');
        $ws->connect();

        foreach ($symbols as $sym) {
            $this->info("Subscribing to orderbook:{$sym}");
            $ws->subscribeOrderbook($sym);
        }

        $ws->onTick(function (array $tick) use ($ttl) {
            // انتظار داریم شکل نرمالایز شده: ['symbol'=>'BTCIRT','last'=>int/float,'ts'=>int]
            $symbol = $tick['symbol'] ?? null;
            $last   = $tick['last']   ?? null;

            if (!$symbol || !$last || $last <= 0) {
                return;
            }

            $key = config('trading.cache.prefix', 'grid_') . "last_price:{$symbol}";
            Cache::put($key, $last, now()->addSeconds($ttl));

            // لاگ سبک برای عیب‌یابی
            Log::channel('nobitex')->debug('WS tick', ['symbol'=>$symbol,'last'=>$last]);
        });

        // حلقه‌ی اصلی (داخل سرویس مدیریت ping/pong و reconnect با backoff انجام میشه)
        $this->info('Consuming… Press Ctrl+C to stop.');
        $ws->run(); // بلاکینگ؛ تا وقتی پردازش می‌کنه برنمی‌گرده

        return self::SUCCESS;
    }
}
