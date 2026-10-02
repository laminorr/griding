<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\FeeModel;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fee model Phase 8 — bring legacy completed_trades (booked with one 35-bps
 * rate on limit prices, before the fee model) onto the new columns WITHOUT
 * ever silently overwriting history.
 *
 * Targets rows with fee_model_version NULL (never processed) or 0 (stamped).
 * Rows booked by the fee model (version >= 1) are never touched.
 *
 *   php artisan fees:backfill --dry-run          show what would change; writes nothing
 *   php artisan fees:backfill                    STAMP only: copy profit/net_profit into
 *                                                profit_v0/net_profit_v0, set
 *                                                fee_source='estimated', fee_model_version=0.
 *                                                Profit figures are NOT changed.
 *   php artisan fees:backfill --apply            stamp + RECOMPUTE with FeeModel (same code
 *                                                as live booking: CompletedTrade::computeBooking)
 *                                                into profit / net_profit / fee / gross_profit
 *                                                and the per-leg breakdown columns.
 *   --bot=46                                     limit to one bot
 *
 * The original values always survive in profit_v0 / net_profit_v0 (copied
 * once, never overwritten). Legacy orders carry no captured fees, so every
 * recomputed row is fee_source 'estimated' at the CURRENT FeeModel rates.
 */
class FeesBackfill extends Command
{
    protected $signature = 'fees:backfill
                            {--dry-run : Show the summary only; write nothing}
                            {--apply : Also recompute profit/net/fee with FeeModel (originals kept in *_v0)}
                            {--bot= : Only this bot id}';

    protected $description = 'Stamp (and optionally recompute) legacy completed_trades with the fee model — never silently overwrites';

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply') && ! $dry;
        $botId = $this->option('bot') !== null ? (int) $this->option('bot') : null;
        $mode  = $dry ? 'DRY-RUN (no writes)' : ($apply ? 'APPLY (stamp + recompute)' : 'STAMP ONLY');

        $this->info("fees:backfill — {$mode}");
        $fm = app(FeeModel::class);
        $this->line(sprintf('FeeModel: buy %s bps (%s), sell %s bps (%s), model version %d',
            $fm->rateFor(null, 'buy'), $fm->expectedCurrency('buy'),
            $fm->rateFor(null, 'sell'), $fm->expectedCurrency('sell'), $fm->modelVersion()));

        /** @var array<int,array<string,mixed>> $sum per bot */
        $sum = [];

        $query = CompletedTrade::query()
            ->where(fn ($q) => $q->whereNull('fee_model_version')->orWhere('fee_model_version', 0))
            ->when($botId !== null, fn ($q) => $q->where('bot_config_id', $botId))
            ->orderBy('id');

        $query->chunkById(200, function ($trades) use (&$sum, $dry, $apply) {
            foreach ($trades as $t) {
                // Read through the model's decimal casts (profit decimal:0,
                // net_profit decimal:8): exact on MySQL DECIMAL, and correctly
                // rounded to the column scale on engines that return floats.
                $oldProfit = self::dec($t->profit);
                $oldNet    = self::dec($t->net_profit ?? $t->profit);
                $new       = $this->recompute($t);

                $b = $t->bot_config_id;
                $sum[$b] ??= ['rows' => 0, 'old_net' => '0', 'new_net' => '0', 'flipped' => 0, 'losses_old' => 0, 'losses_new' => 0];
                $sum[$b]['rows']++;
                $sum[$b]['old_net'] = Money::add($sum[$b]['old_net'], $oldNet);
                $sum[$b]['new_net'] = Money::add($sum[$b]['new_net'], $new['net_profit']);
                $sum[$b]['losses_old'] += Money::isNegative($oldNet) ? 1 : 0;
                $sum[$b]['losses_new'] += Money::isNegative($new['net_profit']) ? 1 : 0;
                $sum[$b]['flipped']    += (Money::isNegative($oldNet) !== Money::isNegative($new['net_profit'])) ? 1 : 0;

                if ($dry) {
                    continue;
                }

                DB::transaction(function () use ($t, $oldProfit, $oldNet, $new, $apply) {
                    $row = CompletedTrade::whereKey($t->id)->lockForUpdate()->first();
                    if (! $row || ($row->fee_model_version !== null && (int) $row->fee_model_version >= 1)) {
                        return; // booked by the fee model meanwhile — never touch
                    }

                    // Audit trail: copy the ORIGINAL values once; never overwrite.
                    $update = [];
                    if ($row->getRawOriginal('profit_v0') === null) {
                        $update['profit_v0']     = $oldProfit;
                        $update['net_profit_v0'] = $oldNet;
                    }

                    if ($apply) {
                        $update += array_diff_key($new, array_flip(['buy_fill_price', 'sell_fill_price']));
                    } else {
                        $update['fee_source']        = 'estimated';
                        $update['fee_model_version'] = 0;
                    }

                    CompletedTrade::whereKey($row->id)->update($update);
                });
            }
        });

        if ($sum === []) {
            $this->info('No legacy completed_trades to process.');
            return self::SUCCESS;
        }

        $sims = BotConfig::whereIn('id', array_keys($sum))->pluck('simulation', 'id');
        $rows = [];
        $tot  = ['rows' => 0, 'old' => '0', 'new' => '0', 'flip' => 0];
        foreach ($sum as $bot => $s) {
            $rows[] = [
                $bot,
                ($sims[$bot] ?? null) ? 'sim' : 'live',
                $s['rows'],
                FeeModel::roundHalfUp($s['old_net'], 0),
                FeeModel::roundHalfUp($s['new_net'], 0),
                FeeModel::roundHalfUp(Money::sub($s['new_net'], $s['old_net']), 0),
                "{$s['losses_old']} → {$s['losses_new']}",
                $s['flipped'],
            ];
            $tot['rows'] += $s['rows'];
            $tot['old']   = Money::add($tot['old'], $s['old_net']);
            $tot['new']   = Money::add($tot['new'], $s['new_net']);
            $tot['flip'] += $s['flipped'];
        }
        $rows[] = ['TOTAL', '', $tot['rows'], FeeModel::roundHalfUp($tot['old'], 0), FeeModel::roundHalfUp($tot['new'], 0),
            FeeModel::roundHalfUp(Money::sub($tot['new'], $tot['old']), 0), '', $tot['flip']];

        $this->table(['bot', 'kind', 'rows', 'Σ net (current, v0)', 'Σ net (fee model)', 'Δ', 'losing rows', 'sign flips'], $rows);

        $this->line(match (true) {
            $dry   => 'Nothing written. Run without --dry-run to stamp, or with --apply to stamp + recompute.',
            $apply => 'Applied: originals kept in profit_v0 / net_profit_v0; recomputed rows have fee_source=estimated, fee_model_version=' . $fm->modelVersion() . '.',
            default => 'Stamped: originals copied to *_v0, fee_source=estimated, fee_model_version=0. Profit unchanged. Use --apply to recompute.',
        });

        Log::channel('trading')->info('FEES_BACKFILL', ['mode' => $mode, 'bot' => $botId, 'rows' => $tot['rows'],
            'old_net' => $tot['old'], 'new_net' => $tot['new']]);

        return self::SUCCESS;
    }

    /**
     * Recompute one legacy row with the live booking code. Uses the row's own
     * orders when both still exist, else legs synthesised from the row
     * (equal quantities at the recorded prices). Legacy legs have no captured
     * fees, so the result is fee_source 'estimated'.
     */
    private function recompute(CompletedTrade $t): array
    {
        $buy  = $t->buy_order_id ? GridOrder::find($t->buy_order_id) : null;
        $sell = $t->sell_order_id ? GridOrder::find($t->sell_order_id) : null;

        if (! $buy || ! $sell) {
            $bot = BotConfig::find($t->bot_config_id);
            $buy = (new GridOrder())->forceFill([
                'bot_config_id' => $t->bot_config_id, 'type' => 'buy',
                'price' => self::dec($t->buy_price), 'amount' => self::dec($t->amount),
            ]);
            $sell = (new GridOrder())->forceFill([
                'bot_config_id' => $t->bot_config_id, 'type' => 'sell',
                'price' => self::dec($t->sell_price), 'amount' => self::dec($t->amount),
            ]);
            $buy->setRelation('botConfig', $bot);
            $sell->setRelation('botConfig', $bot);
        }

        // Legacy legs are equal (gross-sized); their residual is the known
        // D1 shortfall — don't log one warning per historic row.
        return CompletedTrade::computeBooking($buy, $sell, logResidual: false);
    }

    private static function dec(mixed $v): string
    {
        return ($v === null || $v === '') ? '0' : Money::trimZeros(Money::normalize($v));
    }
}
