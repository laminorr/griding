<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Services\ExitSizer;
use App\Services\FeeModel;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fee model Phase 4 — golden numbers for ExitSizer::compute(), the single
 * exit-sizing function. Expected values are copied from docs/fee-audit.md
 * (§C1, §C2, §C5) and host checks V1/V4; ExitSizer never defines its own oracle.
 */
final class ExitSizerGoldenTest extends TestCase
{
    private const P      = '216000000000';
    private const SELL_P = '219240000000'; // P × 1.015
    private const BUY_P  = '212760000000'; // P × 0.985

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

    private function sizer(): ExitSizer
    {
        return new ExitSizer(new FeeModel());
    }

    private function buyFirst(string $filled, ?string $fee, string $dust = '0', ?string $cur = 'base', string $exitP = self::SELL_P): array
    {
        return $this->sizer()->compute(null, 'BTCIRT', 'buy', $filled, $fee, $fee === null ? null : $cur, self::P, $exitP, $dust);
    }

    // ── §C1 / §C5: buy-first exit SELL = floor8(filled − fee) ──────────────

    /** @return array<string,array{0:string,1:string,2:string,3:string}> q, actual fee, sell, dust */
    public static function auditC5(): array
    {
        return [
            'C1 large'        => ['0.00578704', '0.0000144676', '0.00577257', '0.0000000024'],
            'C1 small (12dp)' => ['0.00002315', '0.000000057875', '0.00002309', '0.000000002125'],
            'real 0.000223'   => ['0.000223', '0.0000005575', '0.00022244', '0.0000000025'],
            'real 0.000057'   => ['0.000057', '0.0000001425', '0.00005685', '0.0000000075'],
            'real 0.00002'    => ['0.00002', '0.00000005', '0.00001995', '0'],
            'multiple of 4e-8' => ['0.001', '0.0000025', '0.0009975', '0'],
        ];
    }

    #[DataProvider('auditC5')]
    public function test_buy_first_exit_sell_is_floor_of_credited_with_actual_fee(string $q, string $fee, string $sell, string $dust): void
    {
        $s = $this->buyFirst($q, $fee);

        $this->assertSame('sell', $s['side']);
        $this->assertSame($sell, $s['amount']);
        $this->assertSame($dust, $s['dust_after']);
        $this->assertSame('actual', $s['fee_source']);
        $this->assertSame(Money::sub($q, $fee), $s['net']); // credited
        // Never larger than what the buy credited.
        $this->assertLessThanOrEqual(0, Money::compare($s['amount'], Money::sub($q, $fee)));
    }

    /** The same fills with NO actual fee: estimate rounded UP → never above the true credited. */
    #[DataProvider('auditC5')]
    public function test_estimated_fee_never_oversizes(string $q, string $fee, string $sell, string $dust): void
    {
        $s = $this->buyFirst($q, null);

        $this->assertSame('estimated', $s['fee_source']);
        $this->assertSame($sell, $s['amount']);
        $this->assertLessThanOrEqual(0, Money::compare($s['amount'], Money::sub($q, $fee)));
        $this->assertGreaterThanOrEqual(0, Money::compare($s['fee'], $fee)); // estimate >= exact
    }

    /**
     * V4 (host): after buying 0.0003 BTC (fee 0.00000075 in BTC) the account
     * holds 0.000299721 BTC. The old gross exit sell (0.0003) exceeds it and
     * would be rejected; the fee-net sell (0.00029925) fits.
     */
    public function test_v4_gross_sell_exceeds_account_but_net_sell_fits(): void
    {
        $account = '0.000299721';
        $s = $this->buyFirst('0.0003', '0.00000075', exitP: '113619099980');

        $this->assertSame('0.00029925', $s['amount']);
        $this->assertSame(1, Money::compare('0.0003', $account), 'old gross sizing would be rejected');
        $this->assertLessThanOrEqual(0, Money::compare($s['amount'], $account));
    }

    public function test_buy_fee_reported_in_quote_does_not_reduce_the_sell(): void
    {
        // Unexpected currency (detected upstream): no BTC was taken, sell it all.
        $s = $this->buyFirst('0.0003', '83954.999985', cur: 'quote');
        $this->assertSame('0.0003', $s['amount']);
    }

    // ── dust ledger ────────────────────────────────────────────────────────

    public function test_dust_folds_into_the_sell_once_it_reaches_a_step(): void
    {
        // credited 0.0000568575 + dust 0.0000000076 = 0.0000568651 → 0.00005686
        $s = $this->buyFirst('0.000057', '0.0000001425', dust: '0.0000000076');
        $this->assertSame('0.00005686', $s['amount']);   // one step more than without dust
        $this->assertSame('0.0000000051', $s['dust_after']);
        $this->assertSame('-0.0000000025', $s['dust_delta']);

        // Below a step it just accumulates.
        $s = $this->buyFirst('0.000223', '0.0000005575', dust: '0.000000003');
        $this->assertSame('0.00022244', $s['amount']);
        $this->assertSame('0.0000000055', $s['dust_after']);
    }

    public function test_negative_dust_shortfall_shrinks_the_sell(): void
    {
        // A recorded shortfall of 0.0000003 BTC is taken out of the next sell.
        $s = $this->buyFirst('0.00578704', '0.0000144676', dust: '-0.0000003');
        $this->assertSame('0.00577227', $s['amount']);
        $this->assertSame('0.0000000024', $s['dust_after']);
        $this->assertFalse($s['deferred']);
    }

    public function test_negative_dust_fold_is_deferred_when_it_would_breach_min_notional(): void
    {
        // credited 0.0000149625 → floor 0.00001496 × 219.24e9 = 3,279,830.4 ≥ 3M,
        // but with dust −0.000002 → 0.00001296 × 219.24e9 = 2,841,350.4 < 3M.
        $s = $this->buyFirst('0.000015', '0.0000000375', dust: '-0.000002');

        $this->assertTrue($s['deferred']);
        $this->assertSame('0.00001496', $s['amount']);
        // dust keeps the shortfall and gains this cycle's remainder 0.0000000025
        $this->assertSame('-0.0000019975', $s['dust_after']);
    }

    // ── §C2: sell-first exit BUY ───────────────────────────────────────────

    public function test_sell_first_exit_buy_restores_inventory(): void
    {
        // audit C2: ceil8(q / (1 − 0.0025))
        $s = $this->sizer()->compute(null, 'BTCIRT', 'sell', '0.00578704', '3125001.6', 'quote', self::P, self::BUY_P, '0');
        $this->assertSame('buy', $s['side']);
        $this->assertSame('0.00580155', $s['amount']);
        $this->assertSame('restore_buy', $s['mode']);
        $this->assertSame('0', $s['dust_after']); // settled when the exit buy fills

        $s = $this->sizer()->compute(null, 'BTCIRT', 'sell', '0.00002315', '12502.16', 'quote', self::P, self::BUY_P, '0');
        $this->assertSame('0.00002321', $s['amount']);

        // Inventory after the exit buy's 0.25% BTC fee is >= what was sold:
        // 0.00580155 × 0.9975 = 0.005787046125 >= 0.00578704 (audit: +0.000000006125)
        $this->assertSame('0.000000006125', Money::sub(Money::mul('0.00580155', '0.9975'), '0.00578704'));
    }

    public function test_sell_first_without_restore_buys_back_the_sold_amount(): void
    {
        config(['trading.fees.restore_inventory_on_buy_exit' => false]);
        $s = $this->sizer()->compute(null, 'BTCIRT', 'sell', '0.00578704', '3125001.6', 'quote', self::P, self::BUY_P, '0');
        $this->assertSame('0.00578704', $s['amount']);
        $this->assertSame('plain_buy', $s['mode']);
    }

    public function test_sell_fee_charged_in_base_is_also_restored(): void
    {
        // Sell was charged 0.0000144676 BTC (unexpected): 0.0057871 + fee must come back.
        $s = $this->sizer()->compute(null, 'BTCIRT', 'sell', '0.00578704', '0.0000144676', 'base', self::P, self::BUY_P, '0');
        // (0.00578704 + 0.0000144676) / 0.9975 = 0.0058160477192… → ceil8 = 0.00581605
        $this->assertSame('0.00581605', $s['amount']);
    }
}
