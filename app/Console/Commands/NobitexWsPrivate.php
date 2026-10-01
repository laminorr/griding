<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NobitexPrivateWsService;
use Illuminate\Console\Command;

/**
 * Observe-only consumer of the Nobitex private order/trade WebSocket channels.
 * Kept alive by scripts/ws-private-keepalive.sh (pgrep -f "nobitex:ws-private").
 * The name must never contain "ws-consumer" — scripts/ws-keepalive.sh pgreps
 * that string for the public market-data consumer.
 */
class NobitexWsPrivate extends Command
{
    protected $signature = 'nobitex:ws-private
        {--force : Ignore single-instance lock}
        {--debug : Verbose stdout for live troubleshooting (secrets stay masked)}';

    protected $description = 'Record Nobitex private order/trade WebSocket events (observe-only)';

    public function handle(NobitexPrivateWsService $service): int
    {
        if ((bool) $this->option('debug')) {
            $this->info('[CMD] Debug mode ON');
            $service->enableStdout(true);
        }

        $this->info('[CMD] Starting private WS consumer');
        try {
            $service->run((bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error('[CMD] Fatal: ' . $e->getMessage());
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
