<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\ExitSizer;
use App\Services\NobitexService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off data repair for rows placed before market precision was enforced
 * (docs/market-precision.md). Nobitex silently truncates an off-step amount
 * and moves an off-tick price, so a row can disagree with the order the
 * exchange actually holds (bot 48: amount 0.00004504 stored, 0.000045 on the
 * book; exit buy price …136 stored, …140 on the book).
 *
 *   php artisan grid:sync-precision 48           dry-run: print the diff table
 *   php artisan grid:sync-precision 48 --apply   write the exchange values
 *
 * Scope is deliberately narrow:
 *   - only the bot's OPEN rows (placed / partially_filled) that carry a real
 *     nobitex_order_id; FILLED, cancelled and SIM-* rows are never touched;
 *   - only `amount` and `price` are written — never filled_amount, fees or
 *     anything in completed_trades;
 *   - read-only against the exchange (POST /market/orders/status): it never
 *     cancels, replaces or places an order.
 */
class GridSyncPrecision extends Command
{
    public const OPEN_STATUSES = ['placed', 'partially_filled'];

    protected $signature = 'grid:sync-precision
                            {botId : Bot id whose open orders are compared with the exchange}
                            {--apply : Write the exchange amount/price to the local rows (default: dry-run)}';

    protected $description = 'Compare open grid_orders amount/price with what Nobitex holds; --apply syncs the local rows';

    public function handle(NobitexService $nobitex): int
    {
        $botId = (int) $this->argument('botId');
        $apply = (bool) $this->option('apply');

        if (! BotConfig::whereKey($botId)->exists()) {
            $this->error("Bot {$botId} not found.");
            return self::FAILURE;
        }

        $rows = GridOrder::where('bot_config_id', $botId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('nobitex_order_id')
            ->where('nobitex_order_id', 'not like', 'SIM-%')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("Bot {$botId}: no open orders with a nobitex_order_id.");
            return self::SUCCESS;
        }

        $table   = [];
        $changed = 0;
        $synced  = 0;
        $errors  = 0;

        foreach ($rows as $row) {
            $oid = (string) $row->nobitex_order_id;

            try {
                $dto = $nobitex->getOrdersStatus([$oid])[0] ?? null;
            } catch (\Throwable $e) {
                $dto = null;
            }

            $localAmount = ExitSizer::dec($row->amount);
            $localPrice  = ExitSizer::dec($row->price);

            if ($dto === null) {
                $errors++;
                $table[] = [$row->id, $oid, $row->type, $row->status, $localAmount, '?', $localPrice, '?', 'status unavailable'];
                continue;
            }

            $exAmount = ExitSizer::dec($dto->amountBase);
            $exPrice  = $dto->priceIRT !== null ? (string) $dto->priceIRT : null;

            $updates = [];
            if (Money::isPositive($exAmount) && Money::compare($exAmount, $localAmount) !== 0) {
                $updates['amount'] = $exAmount;
            }
            if ($exPrice !== null && (int) $exPrice > 0 && Money::compare($exPrice, $localPrice) !== 0) {
                $updates['price'] = $exPrice;
            }

            $note = $updates === [] ? 'in sync' : implode('+', array_keys($updates)) . ' differ';
            if ($updates !== []) {
                $changed++;
                if ($apply) {
                    $this->applyRow($row, $updates, $localAmount, $localPrice);
                    $synced++;
                    $note .= ' → synced';
                }
            }

            $table[] = [$row->id, $oid, $row->type, $row->status, $localAmount, $exAmount, $localPrice, $exPrice ?? '?', $note];
        }

        $this->table(['row', 'nobitex id', 'side', 'status', 'local amount', 'exchange amount', 'local price', 'exchange price', 'result'], $table);

        $mode = $apply ? 'APPLIED' : 'DRY-RUN (use --apply to write)';
        $this->line(sprintf('%s — bot %d: %d open row(s), %d differ, %d synced, %d unavailable.', $mode, $botId, $rows->count(), $changed, $synced, $errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,string> $updates */
    private function applyRow(GridOrder $row, array $updates, string $localAmount, string $localPrice): void
    {
        // Re-check the status at write time: a row that filled between the
        // read above and now must keep its booked values.
        $fresh = GridOrder::whereKey($row->id)->whereIn('status', self::OPEN_STATUSES)->first();
        if (! $fresh) {
            return;
        }

        // Model save (not a raw update) so GridOrderObserver recomputes the
        // bot's capital_locked_irt from the corrected amount/price.
        $fresh->forceFill($updates)->save();

        Log::channel('trading')->info('PRECISION_ROW_SYNCED', [
            'bot_id'           => $row->bot_config_id,
            'grid_order_id'    => $row->id,
            'nobitex_order_id' => $row->nobitex_order_id,
            'side'             => $row->type,
            'amount_before'    => $localAmount,
            'amount_after'     => $updates['amount'] ?? $localAmount,
            'price_before'     => $localPrice,
            'price_after'      => $updates['price'] ?? $localPrice,
        ]);
    }
}
