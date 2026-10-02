<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DefinitiveOrderRejection;
use App\Exceptions\InsufficientBalanceRejection;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use App\Support\QtyPrecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What to do when the exchange DEFINITIVELY rejects a cycle exit order
 * (audit D2 / E2). Before this, every such rejection was parked as
 * submission_unknown; the reconciler later cancelled it and unlinked the
 * fill, and CheckTradesJob re-placed the same order — a loop every ~10–15
 * minutes that never closed the cycle.
 *
 *  1. Exit SELL rejected for InsufficientBalance (live only) → self-heal ONCE:
 *     read the free balance (getBalances, activeBalance semantics) and, if it
 *     is >= trading.fees.self_heal_min_ratio (0.98) of the intended amount,
 *     re-place the sell at floor_qty(free) and record the shortfall in the
 *     bot's base_dust (negative), so the ledger matches the account.
 *  2. Anything else (or a failed self-heal) → block: the exit row becomes
 *     'cancelled' with last_error_code; its fill is unlinked and marked
 *     exit_state = 'blocked' (+ reason) so CheckTradesJob stops re-selecting
 *     it; CRITICAL EXIT_BLOCKED is logged and the bot's health surface set.
 *     Operator tool: `php artisan grid:exit-blocked`.
 *
 * An AMBIGUOUS failure of the self-heal retry keeps the row in
 * submission_unknown (reconciler territory), exactly like any other
 * ambiguous placement.
 */
class ExitRejectionHandler
{
    public function __construct(private ExitSizer $sizer) {}

    /**
     * @return string 'self_healed' | 'blocked' | 'submission_unknown'
     */
    public function handle(GridOrder $exitRow, GridOrder $fill, BotConfig $bot, DefinitiveOrderRejection $e): string
    {
        $symbol = (string) ($bot->symbol ?? 'BTCIRT');

        $exitRow->forceFill([
            'last_error_code'    => $e->errorCode(),
            'last_error_message' => mb_substr($e->getMessage(), 0, 255),
        ])->save();

        Log::channel('trading')->warning('EXIT_REJECTED', [
            'bot_id'          => $bot->id,
            'exit_order_id'   => $exitRow->id,
            'filled_order_id' => $fill->id,
            'side'            => $exitRow->type,
            'amount'          => (string) $exitRow->amount,
            'price'           => (string) $exitRow->price,
            'code'            => $e->errorCode(),
            'message'         => $e->getMessage(),
        ]);

        if ($e instanceof InsufficientBalanceRejection && $exitRow->type === 'sell' && ! $bot->simulation) {
            return $this->selfHeal($exitRow, $fill, $bot, $symbol, $e);
        }

        $this->block($exitRow, $fill, $bot, $e->errorCode(), $e->getMessage());
        return 'blocked';
    }

    private function selfHeal(GridOrder $exitRow, GridOrder $fill, BotConfig $bot, string $symbol, DefinitiveOrderRejection $e): string
    {
        /** @var NobitexService $nobitex */
        $nobitex  = app(NobitexService::class);
        $intended = ExitSizer::dec($exitRow->amount);
        [$baseAsset] = FeeModel::assetsOf($symbol);

        try {
            $free = ExitSizer::dec($nobitex->getBalances()[$baseAsset]['available'] ?? '0');
        } catch (\Throwable $balanceError) {
            $this->block($exitRow, $fill, $bot, 'InsufficientBalance', 'self-heal balance check failed: ' . $balanceError->getMessage());
            return 'blocked';
        }

        $ratio    = (string) config('trading.fees.self_heal_min_ratio', '0.98');
        $ratio    = is_numeric($ratio) ? $ratio : '0.98';
        $minFree  = Money::mul($intended, $ratio);
        $retry    = QtyPrecision::floor(Money::min($intended, $free), $symbol);
        $notional = Money::mul($retry, ExitSizer::dec($exitRow->price));
        $minIrt   = (string) (int) (config('trading.min_order_value_irt') ?: 3_000_000);

        if (Money::compare($free, $minFree) < 0 || ! Money::isPositive($retry) || Money::compare($notional, $minIrt) < 0) {
            $this->block($exitRow, $fill, $bot, 'InsufficientBalance', sprintf(
                'free %s %s < %s × intended %s (or below min notional) — not self-healing',
                $free, $baseAsset, $ratio, $intended
            ));
            return 'blocked';
        }

        // Record the retry amount and the shortfall BEFORE calling the exchange,
        // so an ambiguous outcome leaves a row the reconciler can match, and a
        // later cancel+unlink reverts the whole dust move via exit_dust_delta.
        $shortfall = Money::sub($intended, $retry);
        DB::transaction(function () use ($exitRow, $bot, $retry, $shortfall) {
            $row    = BotConfig::whereKey($bot->id)->lockForUpdate()->first();
            $before = ExitSizer::dec($row?->getAttribute('base_dust'));
            $after  = Money::sub($before, $shortfall);
            BotConfig::whereKey($bot->id)->update(['base_dust' => $after]);
            $exitRow->forceFill([
                'amount'          => $retry,
                'status'          => 'pending',
                'exit_dust_delta' => Money::sub(ExitSizer::dec($exitRow->exit_dust_delta), $shortfall),
            ])->save();
        });

        try {
            $resp = $nobitex->placeOrder($symbol, 'sell', (int) ExitSizer::dec($exitRow->price), $retry, $exitRow->client_order_id);
            $id   = $resp['order']['id'] ?? null;
            if (($resp['status'] ?? null) !== 'ok' || ! $id) {
                throw new \RuntimeException('self-heal placement returned no order id');
            }
        } catch (DefinitiveOrderRejection $again) {
            $exitRow->forceFill(['last_error_code' => $again->errorCode(), 'last_error_message' => mb_substr($again->getMessage(), 0, 255)])->save();
            $this->block($exitRow, $fill, $bot, $again->errorCode(), 'self-heal retry rejected: ' . $again->getMessage());
            return 'blocked';
        } catch (\Throwable $ambiguous) {
            $exitRow->forceFill(['status' => 'submission_unknown'])->save();
            Log::channel('trading')->error('EXIT_SELF_HEAL_AMBIGUOUS', [
                'bot_id' => $bot->id, 'exit_order_id' => $exitRow->id, 'amount' => $retry, 'error' => $ambiguous->getMessage(),
            ]);
            return 'submission_unknown';
        }

        $exitRow->forceFill(['status' => 'placed', 'nobitex_order_id' => (string) $id])->save();

        Log::channel('trading')->warning('EXIT_SELF_HEALED', [
            'bot_id'          => $bot->id,
            'exit_order_id'   => $exitRow->id,
            'filled_order_id' => $fill->id,
            'intended'        => $intended,
            'free_balance'    => $free,
            'placed'          => $retry,
            'shortfall'       => $shortfall,
            'note'            => 'Shortfall recorded in bot_configs.base_dust (negative) and recovered from later exits.',
            'trigger'         => $e->getMessage(),
        ]);

        return 'self_healed';
    }

    /**
     * Cancel the exit row, unlink its fill and mark the fill exit_state =
     * 'blocked' so it is not re-selected every minute; undo the row's dust
     * reservation; log CRITICAL; surface on the bot's health fields.
     */
    public function block(GridOrder $exitRow, GridOrder $fill, BotConfig $bot, string $code, string $reason): void
    {
        $reasonText = mb_substr("{$code}: {$reason}", 0, 255);

        DB::transaction(function () use ($exitRow, $fill, $reasonText) {
            $exitRow->forceFill(['status' => 'cancelled'])->save();

            $parent = GridOrder::whereKey($fill->id)->lockForUpdate()->first();
            if ($parent && ($parent->paired_order_id === null || (int) $parent->paired_order_id === (int) $exitRow->id)) {
                $parent->forceFill([
                    'paired_order_id'     => null,
                    'exit_state'          => 'blocked',
                    'exit_blocked_reason' => $reasonText,
                    'exit_blocked_at'     => now(),
                ])->save();
            }
        });
        $this->sizer->revertDust($exitRow->fresh() ?? $exitRow);

        BotConfig::whereKey($bot->id)->update([
            'last_error_code'    => 'EXIT_BLOCKED',
            'last_error_message' => mb_substr("Exit for fill #{$fill->id} blocked — {$reasonText}. Run: php artisan grid:exit-blocked", 0, 255),
        ]);

        Log::channel('trading')->critical('EXIT_BLOCKED', [
            'bot_id'          => $bot->id,
            'filled_order_id' => $fill->id,
            'exit_order_id'   => $exitRow->id,
            'side'            => $exitRow->type,
            'amount'          => (string) $exitRow->amount,
            'price'           => (string) $exitRow->price,
            'reason'          => $reasonText,
            'action'          => 'Fill is no longer re-paired automatically. Inspect with `php artisan grid:exit-blocked`, then --retry or --clear.',
        ]);
    }
}
