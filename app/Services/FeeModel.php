<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotConfig;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY place in the codebase that decides fee rates and fee currencies.
 *
 * Every other component (planner, sizing, booking, panel, calculator, CLI)
 * asks this class instead of reading config or bot columns directly. All
 * arithmetic is bcmath on decimal strings (App\Support\Money) — no floats.
 *
 * Vocabulary
 *  - side       'buy' | 'sell' — the side of the order that paid the fee.
 *  - bps        basis points as a decimal string ("25" = 0.25%).
 *  - rate       the same value as a fraction ("0.0025").
 *  - currency   'base' (BTC for BTCIRT) | 'quote' (rial for BTCIRT).
 *
 * Facts this model encodes (see docs/fees.md):
 *  - VERIFIED: a BUY on Nobitex is charged in BASE at 0.25% of the matched
 *    amount (order 5566181467: fee 0.00000075 on 0.0003 BTC).
 *  - DOCS ONLY: a SELL is charged in QUOTE. Not trusted blindly — every real
 *    fill is classified by magnitude in classifyActualFee(), and a mismatch
 *    with the configured expectation logs FEE_CURRENCY_UNEXPECTED.
 */
class FeeModel
{
    public const SIDE_BUY  = 'buy';
    public const SIDE_SELL = 'sell';

    public const CURRENCY_BASE  = 'base';
    public const CURRENCY_QUOTE = 'quote';

    public const SOURCE_ACTUAL    = 'actual';
    public const SOURCE_ESTIMATED = 'estimated';

    /** Built-in fallback when config holds an unusable value. */
    private const DEFAULT_BPS = '25';

    // ------------------------------------------------------------------
    // Rates
    // ------------------------------------------------------------------

    /**
     * Fee rate in bps for one side of $bot.
     *
     * Precedence: bot override (bot_configs.buy_fee_bps / sell_fee_bps, NULL
     * = not overridden) → config('trading.fees.{side}_fee_bps') → 25.
     * The legacy bot_configs.fee_bps column is deliberately NOT read.
     * $bot may be null for bot-agnostic previews (planner, calculator).
     */
    public function rateFor(?BotConfig $bot, string $side): string
    {
        $side = $this->assertSide($side);

        $override = $bot?->getAttribute("{$side}_fee_bps");
        if ($override !== null && $override !== '') {
            $bps = $this->validBps((string) $override);
            if ($bps !== null) {
                return $bps;
            }
            Log::channel('trading')->warning('FEE_BOT_OVERRIDE_INVALID', [
                'bot_id' => $bot?->id,
                'side'   => $side,
                'value'  => (string) $override,
                'note'   => 'Ignoring invalid bot fee override; using config.',
            ]);
        }

        $configured = (string) config("trading.fees.{$side}_fee_bps", self::DEFAULT_BPS);
        $bps = $this->validBps($configured);
        if ($bps === null) {
            Log::channel('trading')->warning('FEE_CONFIG_INVALID', [
                'side'  => $side,
                'value' => $configured,
                'note'  => 'Invalid trading.fees rate; using built-in ' . self::DEFAULT_BPS . ' bps.',
            ]);
            return self::DEFAULT_BPS;
        }

        return $bps;
    }

    /** Fee rate as a fraction ("25" bps → "0.0025"). */
    public function rateFraction(?BotConfig $bot, string $side): string
    {
        return Money::div($this->rateFor($bot, $side), '10000');
    }

    /** Where the fee for $side is expected to be charged: 'base' | 'quote'. */
    public function expectedCurrency(string $side): string
    {
        $side    = $this->assertSide($side);
        $default = $side === self::SIDE_BUY ? self::CURRENCY_BASE : self::CURRENCY_QUOTE;
        $value   = strtolower(trim((string) config("trading.fees.{$side}_fee_currency", $default)));

        return in_array($value, [self::CURRENCY_BASE, self::CURRENCY_QUOTE], true) ? $value : $default;
    }

    /** Is the expected sell/buy currency layout the documented one (buy→base, sell→quote)? */
    public function hasStandardCurrencies(): bool
    {
        return $this->expectedCurrency(self::SIDE_BUY) === self::CURRENCY_BASE
            && $this->expectedCurrency(self::SIDE_SELL) === self::CURRENCY_QUOTE;
    }

    // ------------------------------------------------------------------
    // Estimates (fallback when no actual fee is known)
    // ------------------------------------------------------------------

    /**
     * Estimated fee for a fill of $amount base at $price, in the side's
     * expected currency, using the configured rate.
     *
     * Base-currency estimates are rounded UP to trading.fees.fee_scale digits
     * so a sell sized as (filled − fee) can never exceed the BTC credited.
     * Quote estimates are exact (they never drive a quantity).
     *
     * @return array{amount:string, currency:string, quote:string, rate_bps:string, source:string}
     */
    public function estimate(string $side, string $amount, string $price, ?BotConfig $bot = null): array
    {
        $side     = $this->assertSide($side);
        $rate     = $this->rateFraction($bot, $side);
        $currency = $this->expectedCurrency($side);

        if ($currency === self::CURRENCY_BASE) {
            $fee = Money::ceilToScale(Money::mul($amount, $rate), $this->feeScale());
        } else {
            $fee = Money::mul(Money::mul($amount, $price), $rate);
        }

        return [
            'amount'   => $fee,
            'currency' => $currency,
            'quote'    => $this->feeToQuote($fee, $currency, $price),
            'rate_bps' => $this->rateFor($bot, $side),
            'source'   => self::SOURCE_ESTIMATED,
        ];
    }

    /** Value a fee in quote (rial). A base fee is valued at $price (the fill price). */
    public function feeToQuote(string $feeAmount, string $currency, string $price): string
    {
        return $currency === self::CURRENCY_BASE ? Money::mul($feeAmount, $price) : $feeAmount;
    }

    // ------------------------------------------------------------------
    // Actual fees
    // ------------------------------------------------------------------

    /**
     * Decide which currency an exchange-reported fee is denominated in, by
     * magnitude: whichever of fee/amount (base reading) or fee/total (quote
     * reading) lies closer to the configured rate wins, closeness measured as
     * a RATIO (max(r/c, c/r)), not a difference — otherwise a garbage fee
     * whose quote reading is ~0 would always look "close" to a small rate.
     * For BTCIRT the two
     * readings differ by the price (~10^11), so the decision is unambiguous
     * for any real fee; a reading above classify_max_bps is rejected.
     *
     * Side effects (each throttled to once per bot, side and UTC day):
     *  - FEE_CURRENCY_UNEXPECTED when the detected currency differs from
     *    expectedCurrency($side);
     *  - FEE_RATE_DRIFT when the effective rate differs from the configured
     *    rate by more than trading.fees.drift_warn_bps.
     *
     * @param string $amount matched base amount the fee was charged on
     * @param string $total  matched quote total (amount × average price)
     * @param string $fee    fee as reported by the exchange
     * @return array{currency:?string, effective_bps:?string, configured_bps:string, expected_currency:string, unexpected:bool, drift:bool}
     *         currency/effective_bps are null when the fee cannot be classified.
     */
    public function classifyActualFee(string $side, string $amount, string $total, string $fee, ?BotConfig $bot = null): array
    {
        $side       = $this->assertSide($side);
        $configured = $this->rateFor($bot, $side);
        $expected   = $this->expectedCurrency($side);

        $result = [
            'currency'          => null,
            'effective_bps'     => null,
            'configured_bps'    => $configured,
            'expected_currency' => $expected,
            'unexpected'        => false,
            'drift'             => false,
        ];

        if (Money::isNegative($fee) || ! Money::isPositive($amount) || ! Money::isPositive($total)) {
            Log::channel('trading')->warning('FEE_UNCLASSIFIABLE', [
                'bot_id' => $bot?->id, 'side' => $side, 'amount' => $amount, 'total' => $total, 'fee' => $fee,
                'note'   => 'Non-positive amount/total or negative fee — cannot classify.',
            ]);
            return $result;
        }

        if (Money::isZero($fee)) {
            // A zero fee reads the same in both currencies; assume the expected
            // one. A zero-fee fill still trips the drift check below.
            $currency = $expected;
            $effBps   = '0';
        } else {
            $baseBps  = Money::mul(Money::div($fee, $amount), '10000');
            $quoteBps = Money::mul(Money::div($fee, $total), '10000');
            $dBase    = $this->rateDistance($baseBps, $configured);
            $dQuote   = $this->rateDistance($quoteBps, $configured);

            [$currency, $effBps] = Money::compare($dBase, $dQuote) <= 0
                ? [self::CURRENCY_BASE, $baseBps]
                : [self::CURRENCY_QUOTE, $quoteBps];

            if (Money::compare($effBps, $this->configBps('classify_max_bps', '100')) > 0) {
                Log::channel('trading')->warning('FEE_UNCLASSIFIABLE', [
                    'bot_id' => $bot?->id, 'side' => $side, 'amount' => $amount, 'total' => $total, 'fee' => $fee,
                    'base_reading_bps' => $baseBps, 'quote_reading_bps' => $quoteBps,
                    'note'   => 'Neither reading is a plausible fee rate.',
                ]);
                return $result;
            }
        }

        $result['currency']      = $currency;
        $result['effective_bps'] = $effBps;

        if ($currency !== $expected) {
            $result['unexpected'] = true;
            $this->warnOncePerDay('FEE_CURRENCY_UNEXPECTED', $bot, $side, [
                'side'              => $side,
                'expected_currency' => $expected,
                'detected_currency' => $currency,
                'amount'            => $amount,
                'total'             => $total,
                'fee'               => $fee,
                'effective_bps'     => $effBps,
                'action'            => 'Check docs/fees.md runbook; set TRADING_' . strtoupper($side) . "_FEE_CURRENCY={$currency} if confirmed.",
            ]);
        }

        $driftBps = Money::abs(Money::sub($effBps, $configured));
        if (Money::compare($driftBps, $this->configBps('drift_warn_bps', '5')) > 0) {
            $result['drift'] = true;
            $this->warnOncePerDay('FEE_RATE_DRIFT', $bot, $side, [
                'side'           => $side,
                'configured_bps' => $configured,
                'effective_bps'  => $effBps,
                'drift_bps'      => $driftBps,
                'currency'       => $currency,
                'amount'         => $amount,
                'fee'            => $fee,
                'action'         => 'Fee tier may have changed; review TRADING_' . strtoupper($side) . '_FEE_BPS / bot override.',
            ]);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Per-fill fee fields (grid_orders persistence)
    // ------------------------------------------------------------------

    /**
     * The fee columns to persist on a grid_orders row for a (partial or full)
     * fill. Used by EVERY fill path — poller and W4 single-order (via the
     * shared CheckTradesJob handlers) and simulation — so they all record the
     * same shape.
     *
     * Source priority: the exchange-reported cumulative $actualFee (classified
     * by magnitude) → otherwise a FeeModel estimate (fee_source 'estimated').
     * An actual fee that cannot be classified also falls back to the estimate.
     *
     * @param string      $side         'buy' | 'sell' (the order's side)
     * @param string      $filled       cumulative matched base amount
     * @param string      $limitPrice   the order's limit price (fallback fill price)
     * @param string|null $averagePrice exchange averagePrice, if reported
     * @param string|null $actualFee    exchange cumulative fee, if reported
     * @param string|null $totalPrice   exchange matched quote total, if reported
     * @return array{fee_amount:?string, fee_currency:?string, fee_asset:?string, fee_source:?string, fee_quote:?string, avg_fill_price:?string, net_base_delta:?string}
     */
    public function fillFields(
        ?BotConfig $bot,
        string $side,
        string $symbol,
        string $filled,
        string $limitPrice,
        ?string $averagePrice = null,
        ?string $actualFee = null,
        ?string $totalPrice = null,
    ): array {
        $side = $this->assertSide($side);

        $empty = [
            'fee_amount' => null, 'fee_currency' => null, 'fee_asset' => null, 'fee_source' => null,
            'fee_quote' => null, 'avg_fill_price' => null, 'net_base_delta' => null,
        ];
        if (! Money::isPositive($filled)) {
            return $empty;
        }

        $fillPrice = ($averagePrice !== null && Money::isPositive($averagePrice)) ? $averagePrice : $limitPrice;
        $total     = ($totalPrice !== null && Money::isPositive($totalPrice)) ? $totalPrice : Money::mul($filled, $fillPrice);

        $fee = null;
        $currency = null;
        $source = self::SOURCE_ESTIMATED;
        if ($actualFee !== null) {
            $class = $this->classifyActualFee($side, $filled, $total, $actualFee, $bot);
            if ($class['currency'] !== null) {
                $fee      = Money::trimZeros($actualFee);
                $currency = $class['currency'];
                $source   = self::SOURCE_ACTUAL;
            }
        }
        if ($fee === null) {
            $est      = $this->estimate($side, $filled, $fillPrice, $bot);
            $fee      = $est['amount'];
            $currency = $est['currency'];
        }

        [$baseAsset, $quoteAsset] = self::assetsOf($symbol);
        $baseFee = $currency === self::CURRENCY_BASE ? $fee : '0';
        $gross   = $side === self::SIDE_BUY ? $filled : Money::sub('0', $filled);

        return [
            'fee_amount'     => $fee,
            'fee_currency'   => $currency,
            'fee_asset'      => $currency === self::CURRENCY_BASE ? $baseAsset : $quoteAsset,
            'fee_source'     => $source,
            'fee_quote'      => $this->feeToQuote($fee, $currency, $fillPrice),
            'avg_fill_price' => $fillPrice,
            'net_base_delta' => Money::sub($gross, $baseFee),
        ];
    }

    /**
     * Lower-case [base, quote] asset codes as the private API names them
     * (BTCIRT → ['btc', 'rls']).
     *
     * @return array{0:string,1:string}
     */
    public static function assetsOf(string $symbol): array
    {
        try {
            return GridOrderExecutor::splitSymbol($symbol);
        } catch (\InvalidArgumentException) {
            return ['base', 'quote'];
        }
    }

    // ------------------------------------------------------------------
    // Break-even spacing
    // ------------------------------------------------------------------

    /**
     * Minimum profitable grid spacing (fractions, e.g. "0.0050125...") for
     * $bot's rates, using the exact formulas of docs/fee-audit.md §C4 with
     * fb = buy rate (charged in base) and fs = sell rate (charged in quote):
     *
     *   buy_first_gross_sell : (fb + fs) / (1 − fs)           exit sells the gross amount
     *   buy_first_net_sell   : 1 / ((1 − fb)(1 − fs)) − 1     exit sells the fee-net amount (what the bot does)
     *   sell_first           : (fb + fs) / (1 + fb)           exit buys back the sold amount
     *   sell_first_restore   : 1 − (1 − fb)(1 − fs)           exit buys ceil(sold / (1 − fb))
     *
     * 'buy_first' / 'sell_first_effective' are the two the bot actually runs
     * (net-sized sells; restore per trading.fees.restore_inventory_on_buy_exit)
     * and 'max' is the larger of them — the spacing below which some cycle
     * direction loses money.
     *
     * @return array<string,string> fractions; also '*_pct' percent strings rounded to 4 dp
     */
    public function breakEvenSpacing(?BotConfig $bot = null): array
    {
        $fb  = $this->rateFraction($bot, self::SIDE_BUY);
        $fs  = $this->rateFraction($bot, self::SIDE_SELL);
        $one = '1';

        $out = [
            'buy_first_gross_sell' => Money::div(Money::add($fb, $fs), Money::sub($one, $fs)),
            'buy_first_net_sell'   => Money::sub(Money::div($one, Money::mul(Money::sub($one, $fb), Money::sub($one, $fs))), $one),
            'sell_first'           => Money::div(Money::add($fb, $fs), Money::add($one, $fb)),
            'sell_first_restore'   => Money::sub($one, Money::mul(Money::sub($one, $fs), Money::sub($one, $fb))),
        ];

        $out['buy_first']            = $out['buy_first_net_sell'];
        $out['sell_first_effective'] = $this->restoreInventoryOnBuyExit() ? $out['sell_first_restore'] : $out['sell_first'];
        $out['max']                  = Money::max($out['buy_first'], $out['sell_first_effective']);

        foreach (array_keys($out) as $key) {
            $out["{$key}_pct"] = self::roundHalfUp(Money::mul($out[$key], '100'), 4);
        }

        return $out;
    }

    /**
     * Fee of one complete cycle on a level of $notional (quote), in quote,
     * using the configured rates and the base fee valued at the buy price.
     *
     *   buy-first  (level is a buy at P, exit sell at P(1+s)): buyN = n,        sellN = n(1+s)
     *   sell-first (level is a sell at P, exit buy at P(1−s)): buyN = n(1−s),   sellN = n
     *   fee = fb·buyN + fs·sellN; gross = n·s. (docs/fee-audit.md §C1/§C2)
     *
     * @param string $spacing fraction (0.015 for 1.5%)
     * @return array{gross:string, fee:string, net:string, buy_fee:string, sell_fee:string}
     */
    public function cycleEstimate(string $levelSide, string $notional, string $spacing, ?BotConfig $bot = null): array
    {
        $levelSide = $this->assertSide($levelSide);
        $fb = $this->rateFraction($bot, self::SIDE_BUY);
        $fs = $this->rateFraction($bot, self::SIDE_SELL);

        [$buyN, $sellN] = $levelSide === self::SIDE_BUY
            ? [$notional, Money::mul($notional, Money::add('1', $spacing))]
            : [Money::mul($notional, Money::sub('1', $spacing)), $notional];

        $buyFee  = Money::mul($fb, $buyN);
        $sellFee = Money::mul($fs, $sellN);
        $fee     = Money::add($buyFee, $sellFee);
        $gross   = Money::mul($notional, $spacing);

        return [
            'gross'    => $gross,
            'fee'      => $fee,
            'net'      => Money::sub($gross, $fee),
            'buy_fee'  => $buyFee,
            'sell_fee' => $sellFee,
        ];
    }

    // ------------------------------------------------------------------
    // Config helpers
    // ------------------------------------------------------------------

    public function feeScale(): int
    {
        $scale = (int) config('trading.fees.fee_scale', 10);
        return $scale >= 0 && $scale <= 18 ? $scale : 10;
    }

    public function restoreInventoryOnBuyExit(): bool
    {
        return (bool) config('trading.fees.restore_inventory_on_buy_exit', true);
    }

    public function modelVersion(): int
    {
        return (int) config('trading.fees.model_version', 1);
    }

    /** Round half-up on decimal strings (no float). */
    public static function roundHalfUp(string $value, int $scale): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';
        return Money::isNegative($value)
            ? Money::sub('0', Money::floorToScale(Money::add(Money::abs($value), $half), $scale))
            : Money::floorToScale(Money::add($value, $half), $scale);
    }

    private function assertSide(string $side): string
    {
        $side = strtolower(trim($side));
        if ($side !== self::SIDE_BUY && $side !== self::SIDE_SELL) {
            throw new \InvalidArgumentException("FeeModel: side must be 'buy' or 'sell', got '{$side}'.");
        }
        return $side;
    }

    /**
     * Multiplicative distance of a reading from the configured rate:
     * max(r/c, c/r) >= 1. With a zero configured rate (zero-fee override)
     * there is no ratio, so the absolute value of the reading is used.
     */
    private function rateDistance(string $readingBps, string $configuredBps): string
    {
        if (! Money::isPositive($configuredBps)) {
            return Money::abs($readingBps);
        }
        if (! Money::isPositive($readingBps)) {
            return '1' . str_repeat('0', 30); // effectively infinite
        }
        return Money::compare($readingBps, $configuredBps) >= 0
            ? Money::div($readingBps, $configuredBps)
            : Money::div($configuredBps, $readingBps);
    }

    /** A usable bps value (numeric, 0 <= bps < 10000) as a trimmed string, else null. */
    private function validBps(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        if (Money::compare($value, '10000') >= 0) {
            return null;
        }
        return Money::trimZeros($value);
    }

    private function configBps(string $key, string $default): string
    {
        return $this->validBps((string) config("trading.fees.{$key}", $default)) ?? $default;
    }

    /**
     * Log a WARNING at most once per (event, bot, side, UTC day). Cache::add
     * is atomic, so concurrent workers cannot both emit it.
     */
    private function warnOncePerDay(string $event, ?BotConfig $bot, string $side, array $context): void
    {
        $key = sprintf('fee-warn:%s:%s:%s:%s', $event, $bot?->id ?? 'none', $side, now()->utc()->format('Y-m-d'));

        try {
            $first = Cache::add($key, 1, now()->addDay());
        } catch (\Throwable $e) {
            $first = true; // never let a cache outage hide a fee alert
        }

        if ($first) {
            Log::channel('trading')->warning($event, ['bot_id' => $bot?->id] + $context);
        }
    }
}
