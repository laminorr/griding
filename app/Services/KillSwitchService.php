<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\BotConfig;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 11, Step 3 — Kill Switch.
 *
 * Wires the two previously-unused risk thresholds on bot_configs —
 * stop_loss_percent and max_drawdown_percent — into the trading decision
 * path. When either threshold is breached the switch trips: the bot is set
 * inactive (is_active = false) so no NEW orders are created (initial grid,
 * rebalance, and pair creation all gate on is_active), while EXISTING
 * cycle_exit sell orders are deliberately left untouched so open cycles can
 * still close normally.
 *
 * The action is intentionally conservative and one-way: a tripped switch is
 * NOT auto-recovered. Once killed the bot stays killed until a human sets
 * is_active = true again — auto-recovery would defeat the switch's purpose.
 *
 * All monetary/percentage arithmetic goes through the bcmath {@see Money}
 * helper so ~20-digit IRT prices and 8-dp crypto profits are exact (no float
 * drift, no 32-bit truncation).
 */
class KillSwitchService
{
    public function __construct(private MarketDataLayer $marketData)
    {
    }

    /**
     * Evaluate both thresholds and, if either is breached on a currently-active
     * bot, trip the switch (log + set is_active = false + save).
     *
     * The stop-loss anchor is bot_configs.grid_center_price (the stable mid
     * price captured at initial grid placement), NOT the moving center_price.
     *
     * @return array{triggered: bool, reason: 'stop_loss'|'max_drawdown'|null, details: array}
     */
    public function checkAndTrigger(BotConfig $bot): array
    {
        $noTrigger = ['triggered' => false, 'reason' => null, 'details' => []];

        $stopLossSet     = $bot->stop_loss_percent !== null && (float) $bot->stop_loss_percent > 0;
        $maxDrawdownSet  = $bot->max_drawdown_percent !== null && (float) $bot->max_drawdown_percent > 0;

        // No behaviour change when neither threshold is configured.
        if (! $stopLossSet && ! $maxDrawdownSet) {
            return $noTrigger;
        }

        // 1) Stop-loss: price distance from the grid anchor.
        if ($stopLossSet) {
            $stopLoss = $this->evaluateStopLoss($bot);
            if ($stopLoss !== null) {
                return $this->trip($bot, 'stop_loss', $stopLoss);
            }
        }

        // 2) Max-drawdown: realized net loss as a percentage of total capital.
        if ($maxDrawdownSet) {
            $drawdown = $this->evaluateMaxDrawdown($bot);
            if ($drawdown !== null) {
                return $this->trip($bot, 'max_drawdown', $drawdown);
            }
        }

        return $noTrigger;
    }

    /**
     * Stop-loss check. Returns a details array when breached, or null otherwise
     * (threshold not breached, no anchor yet, or the live price is unavailable).
     */
    private function evaluateStopLoss(BotConfig $bot): ?array
    {
        $anchor = $bot->grid_center_price;

        // No grid initialized yet (or a zero/blank anchor) → nothing to measure
        // against, so the stop-loss simply does not apply on this pass.
        if ($anchor === null || $anchor === '' || ! Money::isPositive(Money::normalize($anchor))) {
            return null;
        }

        try {
            $currentPrice = $this->marketData->getLastPrice($bot->symbol ?? 'BTCIRT');
        } catch (Throwable $e) {
            // A transient market-data failure must NOT trip the switch — we
            // cannot prove the threshold is breached without a price.
            Log::channel('trading')->warning('KILL_SWITCH_PRICE_UNAVAILABLE', [
                'bot_id' => $bot->id,
                'symbol' => $bot->symbol ?? 'BTCIRT',
                'error'  => $e->getMessage(),
            ]);
            return null;
        }

        $anchorStr  = Money::normalize($anchor);
        $currentStr = Money::normalize($currentPrice);

        // abs((current - anchor) / anchor * 100)
        $distancePct = Money::abs(
            Money::mul(Money::div(Money::sub($currentStr, $anchorStr), $anchorStr), '100')
        );

        if (Money::compare($distancePct, Money::normalize($bot->stop_loss_percent)) > 0) {
            return [
                'current_price'     => $currentStr,
                'center_price'      => $anchorStr,
                'distance_pct'      => $distancePct,
                'stop_loss_percent' => (string) $bot->stop_loss_percent,
            ];
        }

        return null;
    }

    /**
     * Max-drawdown check. Sums the net_profit of the bot's LOSING completed
     * trades (net_profit < 0) and expresses that realized loss as a percentage
     * of total_capital. Returns a details array when breached, else null.
     */
    private function evaluateMaxDrawdown(BotConfig $bot): ?array
    {
        $totalCapital = $bot->total_capital;

        // Cannot express a drawdown percentage without a positive capital base.
        if ($totalCapital === null || ! Money::isPositive(Money::normalize($totalCapital))) {
            return null;
        }

        // Sum losses with bcmath across the raw decimal strings (Phase 10
        // territory — never native-float SUM, which would drift on 8-dp values).
        $lossSum = '0';
        $bot->completedTrades()
            ->where('net_profit', '<', 0)
            ->select('net_profit')
            ->cursor()
            ->each(function ($trade) use (&$lossSum) {
                $lossSum = Money::add($lossSum, Money::normalize($trade->net_profit));
            });

        // No realized losses → no drawdown.
        if (! Money::isNegative($lossSum)) {
            return null;
        }

        // abs(lossSum / total_capital * 100)
        $drawdownPct = Money::abs(
            Money::mul(Money::div($lossSum, Money::normalize($totalCapital)), '100')
        );

        if (Money::compare($drawdownPct, Money::normalize($bot->max_drawdown_percent)) > 0) {
            return [
                'realized_loss'        => $lossSum,
                'total_capital'        => Money::normalize($totalCapital),
                'drawdown_pct'         => $drawdownPct,
                'max_drawdown_percent' => (string) $bot->max_drawdown_percent,
            ];
        }

        return null;
    }

    /**
     * Trip the switch. Logs a loud KILL_SWITCH_TRIGGERED audit entry and, if the
     * bot is currently active, sets is_active = false and persists.
     *
     * Existing orders are deliberately NOT cancelled — open cycle_exit sells
     * must survive so in-flight cycles can still close (Phase 9 Step 6).
     *
     * When the bot is already inactive we still return triggered = true (so a
     * caller such as initializeGrid aborts) but skip the redundant save/dirty
     * write — the switch has already done its one-way job.
     *
     * @param 'stop_loss'|'max_drawdown' $reason
     * @return array{triggered: bool, reason: string, details: array}
     */
    private function trip(BotConfig $bot, string $reason, array $details): array
    {
        if ($bot->is_active) {
            Log::channel('trading')->warning('KILL_SWITCH_TRIGGERED', array_merge([
                'bot_id' => $bot->id,
                'reason' => $reason,
            ], $details));

            $bot->is_active = false;
            $bot->stop_reason = 'kill_switch:' . $reason;
            $bot->stopped_at = now();
            $bot->save();
        }

        return [
            'triggered' => true,
            'reason'    => $reason,
            'details'   => $details,
        ];
    }
}
