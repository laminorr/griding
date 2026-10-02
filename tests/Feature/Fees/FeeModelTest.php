<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Models\BotConfig;
use App\Services\FeeModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Fee model Phase 1 — FeeModel is the single source of fee rates/currencies.
 *
 * Oracles are hand-derived (docblocks) or copied from docs/fee-audit.md
 * (§C1, §C4); FeeModel is never used to define its own expectation.
 * No database: BotConfig instances are built in memory.
 */
final class FeeModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.fees.buy_fee_bps'       => '25',
            'trading.fees.sell_fee_bps'      => '25',
            'trading.fees.buy_fee_currency'  => 'base',
            'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.drift_warn_bps'    => '5',
            'trading.fees.classify_max_bps'  => '100',
            'trading.fees.fee_scale'         => 10,
            'trading.fees.restore_inventory_on_buy_exit' => true,
        ]);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function fm(): FeeModel
    {
        return new FeeModel();
    }

    private function bot(array $attrs = []): BotConfig
    {
        $bot = new BotConfig();
        $bot->forceFill($attrs + ['id' => 46]);
        return $bot;
    }

    private function spyTradingLog(): LoggerInterface
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('trading')->andReturn($logger);
        return $logger;
    }

    // ── rateFor precedence ─────────────────────────────────────────────────

    public function test_rate_precedence_bot_override_then_config_then_builtin(): void
    {
        config(['trading.fees.buy_fee_bps' => '30', 'trading.fees.sell_fee_bps' => '40']);

        // No bot → config.
        $this->assertSame('30', $this->fm()->rateFor(null, 'buy'));
        $this->assertSame('40', $this->fm()->rateFor(null, 'sell'));

        // Bot with NULL overrides → config. Legacy fee_bps (35) is NOT read.
        $plain = $this->bot(['fee_bps' => 35, 'buy_fee_bps' => null, 'sell_fee_bps' => null]);
        $this->assertSame('30', $this->fm()->rateFor($plain, 'buy'));
        $this->assertSame('40', $this->fm()->rateFor($plain, 'sell'));

        // Per-side overrides win independently (DECIMAL(8,4) reads as "17.5000").
        $over = $this->bot(['fee_bps' => 35, 'buy_fee_bps' => '17.5000', 'sell_fee_bps' => null]);
        $this->assertSame('17.5', $this->fm()->rateFor($over, 'buy'));
        $this->assertSame('40', $this->fm()->rateFor($over, 'sell'));

        // A zero override is taken literally (zero-fee bot), not as "unset".
        $zero = $this->bot(['buy_fee_bps' => 0, 'sell_fee_bps' => '0.0000']);
        $this->assertSame('0', $this->fm()->rateFor($zero, 'buy'));
        $this->assertSame('0', $this->fm()->rateFor($zero, 'sell'));

        // Fraction form.
        $this->assertSame('0.003', $this->fm()->rateFraction(null, 'buy'));
        $this->assertSame('0.00175', $this->fm()->rateFraction($over, 'buy'));
    }

    public function test_invalid_values_fall_back_and_warn(): void
    {
        $log = $this->spyTradingLog();

        // Invalid bot override → config.
        $this->assertSame('25', $this->fm()->rateFor($this->bot(['buy_fee_bps' => '-3']), 'buy'));
        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_BOT_OVERRIDE_INVALID')->once();

        // Invalid config → built-in 25.
        config(['trading.fees.sell_fee_bps' => 'abc']);
        $this->assertSame('25', $this->fm()->rateFor(null, 'sell'));
        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_CONFIG_INVALID')->once();

        // >= 10000 bps (100%) is nonsense → built-in.
        config(['trading.fees.sell_fee_bps' => '10000']);
        $this->assertSame('25', $this->fm()->rateFor(null, 'sell'));
    }

    public function test_default_config_is_25_bps_both_sides(): void
    {
        // The shipped config file defaults (no env override in phpunit.xml).
        $cfg = require base_path('config/trading.php');
        $this->assertSame('25', $cfg['fees']['buy_fee_bps']);
        $this->assertSame('25', $cfg['fees']['sell_fee_bps']);
        $this->assertSame('base', $cfg['fees']['buy_fee_currency']);
        $this->assertSame('quote', $cfg['fees']['sell_fee_currency']);
    }

    public function test_unknown_side_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fm()->rateFor(null, 'short');
    }

    // ── expectedCurrency ───────────────────────────────────────────────────

    public function test_expected_currency_defaults_and_is_configurable(): void
    {
        $this->assertSame('base', $this->fm()->expectedCurrency('buy'));
        $this->assertSame('quote', $this->fm()->expectedCurrency('sell'));

        config(['trading.fees.sell_fee_currency' => 'BASE']);
        $this->assertSame('base', $this->fm()->expectedCurrency('sell'));

        config(['trading.fees.sell_fee_currency' => 'eur']); // invalid → default
        $this->assertSame('quote', $this->fm()->expectedCurrency('sell'));
    }

    // ── classifyActualFee ──────────────────────────────────────────────────

    /**
     * V1 — order 5566181467 (VERIFIED on host): buy 0.0003 BTC at
     * 111,939,999,980, totalPrice 33,581,999.994, fee "0.00000075".
     * fee/amount = 0.0025 = 25 bps (configured) → base, no warning.
     */
    public function test_classifies_the_verified_live_buy_fee_as_base(): void
    {
        $log = $this->spyTradingLog();

        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0.00000075', $this->bot());

        $this->assertSame('base', $r['currency']);
        $this->assertSame('25', $r['effective_bps']);
        $this->assertFalse($r['unexpected']);
        $this->assertFalse($r['drift']);
        $log->shouldNotHaveReceived('warning');
    }

    /** A rial sell fee of 0.25% of total: fee/total = 25 bps → quote, as expected. */
    public function test_classifies_a_rial_sell_fee_as_quote(): void
    {
        $log = $this->spyTradingLog();

        // total 33,581,999.994 × 0.0025 = 83,954.999985
        $r = $this->fm()->classifyActualFee('sell', '0.0003', '33581999.994', '83954.999985', $this->bot());

        $this->assertSame('quote', $r['currency']);
        $this->assertSame('25', $r['effective_bps']);
        $this->assertFalse($r['unexpected']);
        $log->shouldNotHaveReceived('warning');
    }

    /**
     * Reality differs from the docs: a SELL charged in BTC. Detected by
     * magnitude, FEE_CURRENCY_UNEXPECTED logged once per bot/side/day.
     */
    public function test_unexpected_sell_fee_currency_warns_once_per_day(): void
    {
        $log = $this->spyTradingLog();
        $bot = $this->bot();

        for ($i = 0; $i < 3; $i++) {
            $r = $this->fm()->classifyActualFee('sell', '0.0003', '33581999.994', '0.00000075', $bot);
            $this->assertSame('base', $r['currency']);
            $this->assertTrue($r['unexpected']);
        }
        $log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $c = []) => $m === 'FEE_CURRENCY_UNEXPECTED'
                && $c['bot_id'] === 46 && $c['expected_currency'] === 'quote' && $c['detected_currency'] === 'base')
            ->once();

        // Another bot is throttled separately.
        $this->fm()->classifyActualFee('sell', '0.0003', '33581999.994', '0.00000075', $this->bot(['id' => 47]));
        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_CURRENCY_UNEXPECTED')->twice();

        // Next UTC day → warns again.
        Carbon::setTestNow(Carbon::parse('2026-10-03 00:00:01', 'UTC'));
        $this->fm()->classifyActualFee('sell', '0.0003', '33581999.994', '0.00000075', $bot);
        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_CURRENCY_UNEXPECTED')->times(3);
    }

    /** And the other way: a BUY charged in rial. */
    public function test_unexpected_buy_fee_in_quote_is_detected(): void
    {
        $log = $this->spyTradingLog();

        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '83954.999985', $this->bot());

        $this->assertSame('quote', $r['currency']);
        $this->assertTrue($r['unexpected']);
        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_CURRENCY_UNEXPECTED')->once();
    }

    /**
     * Tier change: 0.0003 BTC charged 0.0000012 BTC = 40 bps vs configured 25
     * → drift 15 > 5 → FEE_RATE_DRIFT, throttled to once per bot/side/day.
     * 27 bps (drift 2) is within tolerance and silent.
     */
    public function test_rate_drift_warns_once_and_small_drift_is_silent(): void
    {
        $log = $this->spyTradingLog();
        $bot = $this->bot();

        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0.0000012', $bot);
        $this->assertSame('base', $r['currency']);
        $this->assertSame('40', $r['effective_bps']);
        $this->assertTrue($r['drift']);
        $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0.0000012', $bot);

        $log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $c = []) => $m === 'FEE_RATE_DRIFT' && $c['drift_bps'] === '15' && $c['configured_bps'] === '25')
            ->once();

        // 0.0003 × 0.0027 = 0.00000081 → 27 bps, drift 2 → no new warning.
        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0.00000081', $this->bot(['id' => 99]));
        $this->assertFalse($r['drift']);
        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_RATE_DRIFT')->once();
    }

    public function test_drift_is_measured_against_the_bot_override(): void
    {
        $log = $this->spyTradingLog();

        // Bot negotiated 15 bps; a 25-bps fill drifts by 10.
        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0.00000075', $this->bot(['buy_fee_bps' => 15]));
        $this->assertTrue($r['drift']);
        $log->shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === 'FEE_RATE_DRIFT' && $c['configured_bps'] === '15')->once();
    }

    public function test_garbage_fee_is_not_classified(): void
    {
        $log = $this->spyTradingLog();

        // 0.1 BTC fee on 0.0003 BTC (333× the amount) — no plausible reading.
        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0.1', $this->bot());
        $this->assertNull($r['currency']);
        $this->assertNull($r['effective_bps']);

        $r = $this->fm()->classifyActualFee('buy', '0', '0', '0.00000075', $this->bot());
        $this->assertNull($r['currency']);

        $log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'FEE_UNCLASSIFIABLE')->twice();
    }

    public function test_zero_fee_assumes_expected_currency_and_flags_drift(): void
    {
        $this->spyTradingLog();
        $r = $this->fm()->classifyActualFee('buy', '0.0003', '33581999.994', '0', $this->bot());
        $this->assertSame('base', $r['currency']);
        $this->assertSame('0', $r['effective_bps']);
        $this->assertTrue($r['drift']);
    }

    // ── estimate / feeToQuote ──────────────────────────────────────────────

    public function test_estimate_rounds_base_fee_up_and_values_it_at_the_fill_price(): void
    {
        // 0.00002315 × 0.0025 = 0.000000057875 (12 dp) → ceil to 10 dp = 0.0000000579
        $e = $this->fm()->estimate('buy', '0.00002315', '216000000000');
        $this->assertSame('0.0000000579', $e['amount']);
        $this->assertSame('base', $e['currency']);
        $this->assertSame('12506.4', $e['quote']); // 0.0000000579 × 216e9
        $this->assertSame('estimated', $e['source']);

        // Exact at 10 dp → unchanged: 0.00578704 × 0.0025 = 0.0000144676
        $this->assertSame('0.0000144676', $this->fm()->estimate('buy', '0.00578704', '216000000000')['amount']);

        // Quote estimate is exact: 0.00578704 × 219,240,000,000 × 0.0025 = 3,171,876.624
        $s = $this->fm()->estimate('sell', '0.00578704', '219240000000');
        $this->assertSame('3171876.624', $s['amount']);
        $this->assertSame('quote', $s['currency']);
        $this->assertSame('3171876.624', $s['quote']);
    }

    // ── breakEvenSpacing (docs/fee-audit.md §C4) ──────────────────────────

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:string}>
     *   fb bps, fs bps, buy-first gross-sell %, net-sell %, sell-first %, restore %
     */
    public static function auditC4Rows(): array
    {
        return [
            '25/25 (V1+DOCS)'  => ['25', '25', '0.5013', '0.5019', '0.4988', '0.4994'],
            '25/35'            => ['25', '35', '0.6021', '0.6027', '0.5985', '0.5991'],
            '35/35 (old)'      => ['35', '35', '0.7025', '0.7037', '0.6976', '0.6988'],
            '15/15 (tier)'     => ['15', '15', '0.3005', '0.3007', '0.2996', '0.2998'],
        ];
    }

    #[DataProvider('auditC4Rows')]
    public function test_break_even_matches_audit_c4_to_four_decimals(string $fb, string $fs, string $grossPct, string $netPct, string $sfPct, string $restorePct): void
    {
        config(['trading.fees.buy_fee_bps' => $fb, 'trading.fees.sell_fee_bps' => $fs]);

        $be = $this->fm()->breakEvenSpacing();

        $this->assertSame($grossPct, $be['buy_first_gross_sell_pct']);
        $this->assertSame($netPct, $be['buy_first_net_sell_pct']);
        $this->assertSame($sfPct, $be['sell_first_pct']);
        $this->assertSame($restorePct, $be['sell_first_restore_pct']);

        // The bot runs net-sized sells and (default) inventory-restoring buys.
        $this->assertSame($netPct, $be['buy_first_pct']);
        $this->assertSame($restorePct, $be['sell_first_effective_pct']);
        $this->assertSame($netPct, $be['max_pct']); // net-sell is the larger of the two
    }

    public function test_break_even_without_restore_uses_plain_sell_first_and_bot_overrides(): void
    {
        config(['trading.fees.restore_inventory_on_buy_exit' => false]);
        $be = $this->fm()->breakEvenSpacing($this->bot(['buy_fee_bps' => 35, 'sell_fee_bps' => 35]));

        $this->assertSame('0.6976', $be['sell_first_effective_pct']);
        $this->assertSame('0.7037', $be['max_pct']);
    }

    // ── cycleEstimate (docs/fee-audit.md §C1/§C2) ─────────────────────────

    /**
     * §C1 large case, correct-rate formula at 25/25: q = 0.00578704, P = 216e9,
     * buyN = 1,250,000,640, sellN = 1,268,750,649.6;
     * fee = 3,125,001.6 + 3,171,876.624 = 6,296,878.224;
     * gross = 18,750,009.6; net = 12,453,131.376 (audit: 12,453,131.38).
     */
    public function test_cycle_estimate_reproduces_audit_c1_correct_rate_net(): void
    {
        $c = $this->fm()->cycleEstimate('buy', '1250000640', '0.015');

        $this->assertSame('3125001.6', $c['buy_fee']);
        $this->assertSame('3171876.624', $c['sell_fee']);
        $this->assertSame('6296878.224', $c['fee']);
        $this->assertSame('18750009.6', $c['gross']);
        $this->assertSame('12453131.376', $c['net']);
        $this->assertSame('12453131.38', FeeModel::roundHalfUp($c['net'], 2));
    }

    /** Sell level: buyN = n(1−s), sellN = n. */
    public function test_cycle_estimate_for_a_sell_level_uses_the_sell_first_legs(): void
    {
        // n = 100,000,000, s = 1%: buyN = 99,000,000, sellN = 100,000,000
        // fee = 0.0025×99e6 + 0.0025×100e6 = 247,500 + 250,000 = 497,500
        $c = $this->fm()->cycleEstimate('sell', '100000000', '0.01');
        $this->assertSame('247500', $c['buy_fee']);
        $this->assertSame('250000', $c['sell_fee']);
        $this->assertSame('1000000', $c['gross']);
        $this->assertSame('502500', $c['net']);
    }

    public function test_round_half_up_is_string_exact(): void
    {
        $this->assertSame('0.5013', FeeModel::roundHalfUp('0.50125313283208', 4));
        $this->assertSame('3', FeeModel::roundHalfUp('2.5', 0));
        $this->assertSame('-3', FeeModel::roundHalfUp('-2.5', 0));
        $this->assertSame('2', FeeModel::roundHalfUp('2.4999999999', 0));
    }
}
