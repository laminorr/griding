<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NobitexWebSocketService;
use App\Support\MarketSymbols;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NobitexWsConsumer extends Command
{
    protected $signature = 'nobitex:ws-consumer 
        {symbols=BTCIRT,ETHIRT,USDTIRT : Comma-separated symbols}
        {--force : Ignore single-instance lock}
        {--debug : Verbose stdout for live troubleshooting}';

    protected $description = 'Run Nobitex WebSocket consumer and cache market data in real-time';

    public function handle(NobitexWebSocketService $service): int
    {
        $requested = array_map('trim', explode(',', (string) $this->argument('symbols')));

        // Only seed/subscribe symbols in trading.exchange.allowed_symbols: the
        // cron line passes BTCIRT,ETHIRT,USDTIRT, and a disallowed symbol only
        // produces failed REST seeds. One WARNING per start, then skipped.
        ['allowed' => $symbols, 'skipped' => $skipped] = MarketSymbols::partitionAllowed($requested);
        if ($skipped !== []) {
            $msg = '[WS] Skipping symbols not in trading.exchange.allowed_symbols';
            $ctx = ['skipped' => $skipped, 'subscribing' => $symbols];
            try {
                Log::channel('nobitex')->warning($msg, $ctx);
            } catch (\Throwable) {
                Log::warning($msg, $ctx);
            }
            $this->warn('[CMD] Skipping disallowed symbols: '.implode(',', $skipped));
        }
        $force   = (bool) $this->option('force');
        $debug   = (bool) $this->option('debug');

        if ($debug) {
            $this->info('[CMD] Debug mode ON');
            $service->enableStdout(true);
        }

        $this->info('[CMD] Starting WS consumer for: '.implode(',', $symbols));
        try {
            $service->run($symbols, $force);
        } catch (\Throwable $e) {
            $this->error('[CMD] Fatal: '.$e->getMessage());
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
