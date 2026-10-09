<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditReportRenderer;
use App\Services\Audit\BotAuditor;
use Illuminate\Console\Command;

/**
 * Forensic, READ-ONLY audit of one bot: every order, fee, cycle, balance,
 * timing and log event, written to storage/app/audits/bot{ID}-{YmdHi}/
 * (report.md, data.json, orders.csv, cycles.csv, events.csv).
 *
 *   php artisan bot:audit 48 --start-rls=126387058 --start-btc=0.000299721
 *   php artisan bot:audit 48 --no-exchange          zero HTTP calls
 *
 * Never writes the DB, never places / cancels / modifies an order. Exchange
 * reads (order status, wallet, candles) are >= 300 ms apart. See
 * App\Services\Audit\BotAuditor for what each report section computes.
 */
class BotAudit extends Command
{
    protected $signature = 'bot:audit
                            {botId : Bot id to audit}
                            {--from= : Window start (app timezone; default: first order / started_at)}
                            {--to= : Window end (default: now)}
                            {--start-rls= : Pre-bot rial balance snapshot, for the balance reconciliation}
                            {--start-btc= : Pre-bot BTC balance snapshot}
                            {--no-exchange : Skip every exchange call (order status, wallet, candles)}
                            {--candles=1h : Candle resolution for the market section (1m,5m,15m,30m,1h,3h,4h,6h,12h,1d)}
                            {--synced= : Extra row ids synced by grid:sync-precision (e.g. 275-279,281)}
                            {--log-dir= : Log directory (default: storage/logs)}
                            {--rate-ms=300 : Minimum delay between exchange calls (never below 300)}';

    protected $description = 'Read-only forensic audit of a bot: orders, fees, cycles, dust, balances, logs, market replay';

    public function handle(BotAuditor $auditor, AuditReportRenderer $renderer): int
    {
        $botId = (int) $this->argument('botId');

        foreach (['start-rls', 'start-btc'] as $k) {
            $v = $this->option($k);
            if ($v !== null && ! preg_match('/^\d+(\.\d+)?$/', (string) $v)) {
                $this->error("--{$k} must be a plain non-negative decimal (got '{$v}').");
                return self::FAILURE;
            }
        }
        if (($this->option('start-rls') === null) !== ($this->option('start-btc') === null)) {
            $this->error('--start-rls and --start-btc must be given together.');
            return self::FAILURE;
        }

        try {
            $result = $auditor->audit($botId, [
                'from'        => $this->option('from') ?: null,
                'to'          => $this->option('to') ?: null,
                'start_rls'   => $this->option('start-rls'),
                'start_btc'   => $this->option('start-btc'),
                'no_exchange' => (bool) $this->option('no-exchange'),
                'candles'     => (string) ($this->option('candles') ?: '1h'),
                'synced'      => self::parseIds((string) ($this->option('synced') ?? '')),
                'log_dir'     => (string) ($this->option('log-dir') ?: storage_path('logs')),
                'rate_ms'     => (int) ($this->option('rate-ms') ?: 300),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $dir   = storage_path('app/audits/bot' . $botId . '-' . now()->format('Ymd-Hi'));
        $paths = $renderer->write($result, $dir);

        $this->info('Report: ' . $paths['report.md']);
        foreach ($result['summary'] as $line) {
            $this->line('  ' . $line);
        }

        return self::SUCCESS;
    }

    /** "275-279,281" → [275,276,277,278,279,281] */
    public static function parseIds(string $spec): array
    {
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m) && (int) $m[2] >= (int) $m[1] && (int) $m[2] - (int) $m[1] < 10000) {
                $ids = array_merge($ids, range((int) $m[1], (int) $m[2]));
            } elseif (ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }
        return array_values(array_unique($ids));
    }
}
