<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\GridRearmer;
use Illuminate\Console\Command;

/**
 * Operator re-arm for ONE filled cycle exit whose level stayed dark (e.g. the
 * re-arm failed for the whole fill-age window — REARM_ABANDONED).
 *
 *   php artisan grid:rearm 294           dry-run: what WOULD be placed
 *   php artisan grid:rearm 294 --apply   place it (logs REARM_MANUAL)
 *
 * Goes through GridRearmer — every gate of the automatic path applies (flag on,
 * bot active, kill switch, EXIT_BLOCKED, parent / root, generation, level
 * free, sizing, minimum notional). The ONLY difference: the fill-age window
 * (trading.rearm.max_fill_age_minutes), which limits the automatic sweep, is
 * not applied. A dry-run writes nothing (kill switch read-only, sizing without
 * a dust move), and so does --apply when the dry-run says it would not place.
 *
 * Refuses when the exit already has a re-arm (rearm_order_id) or a decision
 * (rearm_state 'placed' / 'skipped:*'), or when the bot's rearm_exits flag is
 * off. A failure state ('retry:*' / 'abandoned:*') is exactly what this tool
 * is for, so it is accepted.
 */
class GridRearm extends Command
{
    protected $signature = 'grid:rearm
                            {exitOrderId : Id of the filled cycle_exit grid_orders row}
                            {--apply : Place the re-arm (default: dry-run)}';

    protected $description = 'Re-arm the level of one filled cycle exit by hand (dry-run unless --apply)';

    public function handle(GridRearmer $rearmer): int
    {
        $exit = GridOrder::find((int) $this->argument('exitOrderId'));
        if ($exit === null) {
            $this->error('No grid_orders row ' . $this->argument('exitOrderId') . '.');
            return self::FAILURE;
        }
        $bot = BotConfig::find($exit->bot_config_id);
        if ($bot === null) {
            $this->error("Exit {$exit->id}: bot {$exit->bot_config_id} not found.");
            return self::FAILURE;
        }

        $p = $rearmer->preview($exit, $bot);
        $apply = (bool) $this->option('apply');

        $this->line(sprintf('%s — bot %d (%s), exit %d: %s %s @ %s, filled %s (%s min ago; sweep window %d min%s)',
            $apply ? 'APPLY' : 'DRY-RUN', $bot->id, $p['simulation'] ? 'simulation' : 'LIVE', $exit->id,
            $exit->status, $exit->type, (string) $exit->price, $p['filled_at'] ?? '—', $p['fill_age_minutes'] ?? '—',
            $p['window_minutes'], $p['outside_window'] ? ' — outside it, bypassed by this command' : ''));
        $this->line(sprintf('  rearm_order_id=%s  rearm_state=%s', $p['rearm_order_id'] ?? 'null', $p['rearm_state'] ?? 'null'));

        if ($p['refusal'] !== null) {
            $this->error('Refused: ' . match ($p['refusal']) {
                'FLAG_OFF'        => "bot {$bot->id} has rearm_exits OFF.",
                'NOT_ELIGIBLE'    => "row {$exit->id} is not a filled cycle_exit (role={$exit->role}, status={$exit->status}).",
                'OTHER_BOT'       => 'exit / bot mismatch.',
                'ALREADY_DECIDED' => 'this exit already has a re-arm or a final decision (see rearm_order_id / rearm_state above).',
                default           => $p['refusal'],
            });
            return self::FAILURE;
        }

        $gen = $p['generation'];
        $this->table(['check', 'result'], [
            ['side',       $p['side'] ?? '—'],
            ['price',      $p['price'] ?? '—'],
            ['amount',     $p['amount'] ?? '—'],
            ['notional',   $p['notional'] ?? '—'],
            ['min notional', $p['below_min'] === null ? '—' : ($p['below_min'] ? 'BELOW' : 'ok')],
            ['generation', sprintf('level=%s bot=%d → %s', $gen['level'] ?? 'null', $gen['bot'], match ($gen['ok']) { true => 'ok', false => 'OLD', default => '—' })],
            ['level busy', is_array($p['level_busy'])
                ? "BUSY: order {$p['level_busy']['order_id']} ({$p['level_busy']['status']}, {$p['level_busy']['role']})"
                : ($p['level_busy'] === false ? 'free' : '—')],
            ['gates',      $p['skip'] === null ? 'all pass' : 'REFUSED: ' . $p['skip']],
        ]);

        if (! $p['would_place']) {
            // Nothing is written, not even with --apply: the automatic path would
            // record this refusal as final (rearm_state skipped:*), and an
            // operator should be able to fix the cause and run this again.
            $this->warn("Would NOT place: {$p['skip']}. Nothing written" . ($apply ? ' (--apply ignored)' : '') . '.');
            return self::FAILURE;
        }
        if (! $apply) {
            $this->info(sprintf('Would place: %s %s @ %s (role rearm). Run again with --apply to place it.', $p['side'], $p['amount'], $p['price']));
            return self::SUCCESS;
        }

        $result = $rearmer->rearmManually($exit, $bot);
        $exit->refresh();

        if ($result === 'placed') {
            $rearm = GridOrder::find($exit->rearm_order_id);
            $this->info(sprintf('Placed re-arm %d: %s %s @ %s, status %s%s.', $rearm->id, $rearm->type, (string) $rearm->amount,
                (string) $rearm->price, $rearm->status, $rearm->nobitex_order_id ? ", exchange id {$rearm->nobitex_order_id}" : ''));
            return self::SUCCESS;
        }

        $this->error("Not placed: {$result} (rearm_state=" . ($exit->rearm_state ?? 'null') . '). See the trading log (REARM_*).');
        return self::FAILURE;
    }
}
