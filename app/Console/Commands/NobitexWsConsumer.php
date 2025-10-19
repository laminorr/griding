<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NobitexWebSocketService;
use Illuminate\Console\Command;

class NobitexWsConsumer extends Command
{
    protected $signature = 'nobitex:ws-consumer 
        {symbols=BTCIRT,ETHIRT,USDTIRT : Comma-separated symbols}
        {--force : Ignore single-instance lock}
        {--debug : Verbose stdout for live troubleshooting}';

    protected $description = 'Run Nobitex WebSocket consumer and cache market data in real-time';

    public function handle(NobitexWebSocketService $service): int
    {
        $symbols = array_map('trim', explode(',', (string) $this->argument('symbols')));
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
