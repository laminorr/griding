<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotConfig;
use App\Models\GridOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator tool for fills whose cycle exit was DEFINITIVELY rejected and
 * blocked (Fee model Phase 5, log event EXIT_BLOCKED). Read-only against the
 * exchange — it only flips local flags.
 *
 *   php artisan grid:exit-blocked                 list blocked fills
 *   php artisan grid:exit-blocked --bot=46        one bot only
 *   php artisan grid:exit-blocked --retry=123     un-block fill #123: the next
 *                                                 CheckTradesJob run re-sizes
 *                                                 and re-places its exit
 *   php artisan grid:exit-blocked --retry=all     un-block every listed fill
 *   php artisan grid:exit-blocked --clear=123     mark #123 resolved by hand
 *                                                 (never re-paired again)
 *
 * Fix the cause first (top up BTC/IRT, adjust the bot), THEN --retry; a retry
 * that fails again simply re-blocks.
 */
class GridExitBlocked extends Command
{
    protected $signature = 'grid:exit-blocked
                            {--bot= : Only this bot id}
                            {--retry= : Fill id to un-block (or "all")}
                            {--clear= : Fill id to mark resolved by hand}';

    protected $description = 'List / retry / clear filled orders whose exit order was definitively rejected (EXIT_BLOCKED)';

    public function handle(): int
    {
        $botId = $this->option('bot') !== null ? (int) $this->option('bot') : null;

        if ($this->option('clear') !== null) {
            return $this->clear((int) $this->option('clear'));
        }
        if ($this->option('retry') !== null) {
            return $this->retry((string) $this->option('retry'), $botId);
        }

        $rows = $this->blocked($botId)->get();
        if ($rows->isEmpty()) {
            $this->info('No blocked exits.');
            return self::SUCCESS;
        }

        $this->table(
            ['fill id', 'bot', 'side', 'price', 'filled', 'fee', 'fee cur', 'reason', 'blocked at'],
            $rows->map(fn (GridOrder $o) => [
                $o->id,
                $o->bot_config_id,
                $o->type,
                (string) $o->price,
                (string) ($o->filled_amount ?? $o->amount),
                $o->fee_amount !== null ? rtrim(rtrim((string) $o->fee_amount, '0'), '.') : '—',
                $o->fee_currency ?? '—',
                (string) $o->exit_blocked_reason,
                $o->exit_blocked_at?->toDateTimeString() ?? '—',
            ])->all()
        );
        $this->line('Fix the cause, then: php artisan grid:exit-blocked --retry=<id|all>   (or --clear=<id> if handled by hand)');

        return self::SUCCESS;
    }

    private function blocked(?int $botId)
    {
        return GridOrder::query()
            ->where('exit_state', 'blocked')
            ->when($botId !== null, fn ($q) => $q->where('bot_config_id', $botId))
            ->orderBy('id');
    }

    private function retry(string $which, ?int $botId): int
    {
        $query = $this->blocked($botId);
        if ($which !== 'all') {
            $query->whereKey((int) $which);
        }
        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->error('No matching blocked fill.');
            return self::FAILURE;
        }

        foreach ($rows as $o) {
            $o->forceFill(['exit_state' => null, 'exit_blocked_reason' => null, 'exit_blocked_at' => null])->save();
            Log::channel('trading')->warning('EXIT_BLOCK_RETRY', ['bot_id' => $o->bot_config_id, 'filled_order_id' => $o->id]);
            $this->info("Fill #{$o->id} un-blocked; its exit is re-sized and re-placed on the next CheckTradesJob run.");
            $this->clearBotHealthIfDone((int) $o->bot_config_id);
        }

        return self::SUCCESS;
    }

    private function clear(int $id): int
    {
        $o = GridOrder::whereKey($id)->where('exit_state', 'blocked')->first();
        if (! $o) {
            $this->error("Fill #{$id} is not blocked.");
            return self::FAILURE;
        }
        $o->forceFill(['exit_state' => 'cleared'])->save();
        Log::channel('trading')->warning('EXIT_BLOCK_CLEARED', [
            'bot_id' => $o->bot_config_id, 'filled_order_id' => $o->id, 'reason' => $o->exit_blocked_reason,
        ]);
        $this->info("Fill #{$id} marked as resolved by hand; it will not be re-paired.");
        $this->clearBotHealthIfDone((int) $o->bot_config_id);

        return self::SUCCESS;
    }

    /** Drop the bot's EXIT_BLOCKED health flag once it has no blocked fills left. */
    private function clearBotHealthIfDone(int $botId): void
    {
        if (GridOrder::where('bot_config_id', $botId)->where('exit_state', 'blocked')->exists()) {
            return;
        }
        BotConfig::whereKey($botId)->where('last_error_code', 'EXIT_BLOCKED')
            ->update(['last_error_code' => null, 'last_error_message' => null]);
    }
}
