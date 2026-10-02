<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Services\ExitSizer;
use App\Services\FeeModel;
use App\Support\Money;
use App\Support\QtyPrecision;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fee model Phase 4 — property test: 1,000 randomized buy-first and
 * sell-first cycles through ExitSizer::compute() (the same function every
 * pairing path uses), against an independent model of the EXCHANGE ACCOUNT:
 *
 *   - quantities random within [min_order_value, 2e9 IRT] at random prices;
 *   - every fill is split into 1–5 random partial trades;
 *   - the exchange charges each trade its own fee (exact, or rounded UP at
 *     10 dp as a pessimistic exchange would) and reports the cumulative fee;
 *   - buy fees in BTC, sell fees in rial (never touch BTC).
 *
 * Invariants asserted after EVERY cycle:
 *   I1  no exit sell exceeds the BTC the account actually holds
 *       (credited + the bot's own dust) — i.e. it would never be rejected;
 *   I2  the dust ledger equals the account's real BTC drift exactly;
 *   I3  cumulative BTC drift stays within ±(cycles × 1 qty step);
 *   I4  dust never goes below −1 step.
 * Seeded mt_rand → deterministic and reproducible.
 */
final class ExitSizerPropertyTest extends TestCase
{
    private const STEP = '0.00000001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.fees.buy_fee_bps'  => '25',
            'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency'  => 'base',
            'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.fee_scale' => 10,
            'trading.fees.restore_inventory_on_buy_exit' => true,
            'trading.min_order_value_irt' => 3_000_000,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    /** @return array<string,array{0:int,1:bool}> seed, exchange rounds each trade fee UP at 10 dp */
    public static function scenarios(): array
    {
        return [
            'exact per-trade fees'        => [20261002, false],
            'per-trade fees rounded up'   => [424242, true],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_one_thousand_random_cycles_never_oversize_and_track_drift_exactly(int $seed, bool $roundUp): void
    {
        mt_srand($seed);
        $sizer = new ExitSizer(new FeeModel());
        $rate  = '0.0025';

        $seedInventory = '1';         // BTC held for sell-first levels (never oversold by them)
        $account       = $seedInventory;
        $dust          = '0';
        $cycles        = 0;
        $buyFirst      = 0;

        for ($i = 0; $i < 1000; $i++) {
            $cycles++;
            $price    = (string) mt_rand(100_000, 250_000) . '000000';         // 1.0e11 … 2.5e11
            $notional = (string) mt_rand(3_000_000, 2_000_000_000);
            $q        = QtyPrecision::ceil(Money::div($notional, $price, 18), 'BTCIRT'); // >= min notional
            $spacing  = Money::div((string) mt_rand(50, 300), '10000');               // 0.5% … 3%

            if (mt_rand(0, 1) === 1) {
                // ── buy-first: buy q, exit sell sized by ExitSizer, sell fills ─
                $buyFirst++;
                $fee      = $this->exchangeFills($q, $rate, $roundUp);
                $account  = Money::add($account, Money::sub($q, $fee));
                $exitP    = (string) (int) FeeModel::roundHalfUp(Money::mul($price, Money::add('1', $spacing)), 0);

                $s = $sizer->compute(null, 'BTCIRT', 'buy', $q, $fee, 'base', $price, $exitP, $dust);

                // I1: the sell fits in the real account, and within credited + own dust.
                $this->assertLessThanOrEqual(0, Money::compare($s['amount'], Money::add(Money::sub($q, $fee), $dust)), "cycle {$i}: exceeds credited+dust");
                $this->assertLessThanOrEqual(0, Money::compare(Money::add($s['amount'], $seedInventory), $account), "cycle {$i}: would dip into inventory");
                $this->assertTrue(Money::isPositive($s['amount']));

                $account = Money::sub($account, $s['amount']); // exit sell fills (rial fee only)
                $dust    = $s['dust_after'];
            } else {
                // ── sell-first: sell q from inventory, exit buy restores it ────
                $account = Money::sub($account, $q);            // sell fee is rial
                $exitP   = (string) (int) FeeModel::roundHalfUp(Money::mul($price, Money::sub('1', $spacing)), 0);

                $s = $sizer->compute(null, 'BTCIRT', 'sell', $q, Money::mul(Money::mul($q, $price), $rate), 'quote', $price, $exitP, $dust);
                $this->assertGreaterThanOrEqual(0, Money::compare(Money::mul($s['amount'], '0.9975'), $q), "cycle {$i}: restore buy too small");

                $feeB     = $this->exchangeFills($s['amount'], $rate, $roundUp);
                $credited = Money::sub($s['amount'], $feeB);
                $account  = Money::add($account, $credited);
                // ExitSizer::settleExitBuyFill: dust += credited − sold
                $dust     = Money::add($dust, Money::sub($credited, $q));
            }

            $drift = Money::sub($account, $seedInventory);
            // I2: the ledger IS the real drift.
            $this->assertSame(0, Money::compare($dust, $drift), "cycle {$i}: ledger {$dust} != real drift {$drift}");
            // I3: bounded drift.
            $bound = Money::mul((string) $cycles, self::STEP);
            $this->assertLessThanOrEqual(0, Money::compare(Money::abs($drift), $bound), "cycle {$i}: drift {$drift} beyond {$bound}");
            // I4: dust never below −1 step.
            $this->assertGreaterThanOrEqual(0, Money::compare($dust, Money::sub('0', self::STEP)), "cycle {$i}: dust {$dust}");
        }

        $this->assertGreaterThan(400, $buyFirst);
        $this->assertLessThan(600, $buyFirst);
    }

    /**
     * Negative control: the OLD sizing (exit sell = gross filled amount) breaks
     * invariant I1 on the very first buy-first cycle with no spare BTC — the
     * test above would catch a regression to it.
     */
    public function test_old_gross_sizing_violates_the_invariant_immediately(): void
    {
        $q       = '0.00578704';
        $fee     = $this->exchangeFills($q, '0.0025', false);
        $account = Money::sub($q, $fee); // zero spare BTC
        $this->assertSame(1, Money::compare($q, $account), 'gross sell exceeds the BTC the buy credited');

        $s = (new ExitSizer(new FeeModel()))->compute(null, 'BTCIRT', 'buy', $q, $fee, 'base', '216000000000', '219240000000', '0');
        $this->assertLessThanOrEqual(0, Money::compare($s['amount'], $account));
    }

    /**
     * Split $q into 1–5 random partial trades (each a whole number of steps)
     * and return the cumulative fee the exchange reports for them.
     */
    private function exchangeFills(string $q, string $rate, bool $roundUp): string
    {
        $steps = (int) Money::floorToScale(Money::div($q, self::STEP), 0);
        $parts = mt_rand(1, min(5, max(1, $steps)));
        $left  = $steps;
        $fee   = '0';
        for ($p = $parts; $p >= 1; $p--) {
            $take = $p === 1 ? $left : mt_rand(1, $left - ($p - 1));
            $left -= $take;
            $tradeFee = Money::mul(Money::mul((string) $take, self::STEP), $rate);
            if ($roundUp) {
                $tradeFee = Money::ceilToScale($tradeFee, 10);
            }
            $fee = Money::add($fee, $tradeFee);
        }
        return $fee;
    }
}
