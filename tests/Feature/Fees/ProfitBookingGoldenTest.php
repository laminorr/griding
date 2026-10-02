<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\FeeModel;
use App\Services\KillSwitchService;
use App\Services\MarketDataLayer;
use App\Support\Money;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 6 — CompletedTrade::createFromOrders books from the real
 * per-leg fees, fill prices and filled quantities. Expected nets are the
 * audit's "true net" figures (docs/fee-audit.md §C1/§C2, BTC valued at the
 * buy price), to the rial (2 dp). Then the KillSwitch D3 scenario.
 */
final class ProfitBookingGoldenTest extends TestCase
{
    use BuildsGridSchema;

    private const P      = '216000000000';
    private const SELL_P = '219240000000';
    private const BUY_P  = '212760000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        config([
            'trading.fees.buy_fee_bps' => '25', 'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency' => 'base', 'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.model_version' => 1,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function bot(array $over = []): BotConfig
    {
        return BotConfig::create(array_merge(['name' => 'book', 'symbol' => 'BTCIRT', 'simulation' => false, 'is_active' => true, 'grid_spacing' => 1.5], $over));
    }

    /** A filled leg carrying its ACTUAL fee, as Phase 3 stores it. */
    private function leg(BotConfig $bot, string $side, string $price, string $qty, string $fee, string $cur): GridOrder
    {
        return GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => $side, 'status' => 'filled', 'price' => $price,
            'amount' => $qty, 'filled_amount' => $qty, 'avg_fill_price' => $price,
            'fee_amount' => $fee, 'fee_currency' => $cur, 'fee_source' => 'actual',
            'client_order_id' => uniqid($side), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:02:00',
        ]);
    }

    private static function rial(string $v): string
    {
        return FeeModel::roundHalfUp($v, 2);
    }

    /**
     * Buy-first. Buy q at P (BTC fee q×0.25%), sell $sellQty at P×1.015 with a
     * rial fee $fs of the sell notional.
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}> q, sellQty, fs, audit true net
     */
    public static function buyFirstCases(): array
    {
        return [
            'C1 large, fee-net sell, fs 0.25%'   => ['0.00578704', '0.00577257', '0.0025', '12414179.58'],
            'C1 large, fee-net sell, fs 0.35%'   => ['0.00578704', '0.00577257', '0.0035', '11148601.34'],
            'C1 large, gross sell, fs 0.25%'     => ['0.00578704', '0.00578704', '0.0025', '12453131.38'],
            'C1 large, gross sell, fs 0.35%'     => ['0.00578704', '0.00578704', '0.0035', '11184380.73'],
            'C1 small, fee-net sell, fs 0.25%'   => ['0.00002315', '0.00002309', '0.0025', '49654.97'],
            'C1 small, fee-net sell, fs 0.35%'   => ['0.00002315', '0.00002309', '0.0035', '44592.72'],
            'C1 small, gross sell, fs 0.25%'     => ['0.00002315', '0.00002315', '0.0025', '49816.49'],
            'C1 small, gross sell, fs 0.35%'     => ['0.00002315', '0.00002315', '0.0035', '44741.08'],
        ];
    }

    #[DataProvider('buyFirstCases')]
    public function test_buy_first_books_the_audit_true_net(string $q, string $sellQty, string $fs, string $expectedNet): void
    {
        $bot  = $this->bot();
        $buy  = $this->leg($bot, 'buy', self::P, $q, Money::mul($q, '0.0025'), 'base');
        $sell = $this->leg($bot, 'sell', self::SELL_P, $sellQty, Money::mul(Money::mul($sellQty, self::SELL_P), $fs), 'quote');

        $t = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame($expectedNet, self::rial((string) $t->net_profit));
        $this->assertSame('actual', $t->fee_source);
        $this->assertSame(1, $t->fee_model_version);
        $this->assertSame('base', $t->buy_fee_currency);
        $this->assertSame('quote', $t->sell_fee_currency);
        // fee = buy BTC fee valued at the buy price + rial sell fee; net = gross − fee.
        $this->assertSame(0, Money::compare(Money::sub((string) $t->gross_profit, (string) $t->fee), (string) $t->net_profit, 8));
        // residual = credited − sold
        $this->assertSame(0, Money::compare((string) $t->base_residual, Money::sub(Money::sub($q, Money::mul($q, '0.0025')), $sellQty)));
    }

    /**
     * Sell-first, buying back the SAME q (no restore): the BTC fee is valued
     * at the exit-buy price (= the buy fill price) → audit §C2 true net.
     *
     * @return array<string,array{0:string,1:string,2:string}> q, fs, audit true net
     */
    public static function sellFirstCases(): array
    {
        return [
            'C2 large, fs 0.25%' => ['0.00578704', '0.0025', '12546881.42'],
            'C2 large, fs 0.35%' => ['0.00578704', '0.0035', '11296880.78'],
            'C2 small, fs 0.25%' => ['0.00002315', '0.0025', '50191.52'],
            'C2 small, fs 0.35%' => ['0.00002315', '0.0035', '45191.12'],
        ];
    }

    #[DataProvider('sellFirstCases')]
    public function test_sell_first_books_the_audit_true_net(string $q, string $fs, string $expectedNet): void
    {
        $bot  = $this->bot();
        $sell = $this->leg($bot, 'sell', self::P, $q, Money::mul(Money::mul($q, self::P), $fs), 'quote');
        $buy  = $this->leg($bot, 'buy', self::BUY_P, $q, Money::mul($q, '0.0025'), 'base');

        $t = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame($expectedNet, self::rial((string) $t->net_profit));
    }

    /**
     * Sell-first with the inventory-restoring buy (audit §C2, fs 0.25%):
     * buy 0.00580155 → rial Δ 12,537,860.40 and BTC +0.000000006125;
     * true net = 12,537,860.40 + 0.000000006125 × 212,760,000,000
     *          = 12,537,860.40 + 1,303.155 = 12,539,163.555 → 12,539,163.56.
     */
    public function test_sell_first_restore_books_rial_delta_plus_residual_at_buy_price(): void
    {
        $bot  = $this->bot();
        $sell = $this->leg($bot, 'sell', self::P, '0.00578704', '3125001.6', 'quote');
        $buy  = $this->leg($bot, 'buy', self::BUY_P, '0.00580155', '0.000014503875', 'base');

        $t = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('12539163.56', self::rial((string) $t->net_profit));
        $this->assertSame('0.000000006125000000', (string) $t->base_residual);
        $this->assertSame('0.00578704', (string) $t->amount);                  // sold quantity
        $this->assertSame('0.005801550000000000', (string) $t->buy_filled_amount); // restoring buy
    }

    /** Price improvement: the booked gross uses the AVERAGE fill prices, not the limits. */
    public function test_average_fill_prices_drive_the_booking(): void
    {
        $bot  = $this->bot();
        $buy  = $this->leg($bot, 'buy', self::P, '0.001', '0.0000025', 'base');
        $buy->update(['avg_fill_price' => '215000000000']);   // filled 1e9 below the limit
        $sell = $this->leg($bot, 'sell', self::SELL_P, '0.0009975', '548127.99', 'quote');

        $t = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // gross = (219.24e9 − 215e9) × 0.0009975 = 4,229,400
        $this->assertSame('4229400.00000000', (string) $t->gross_profit);
        $this->assertSame('215000000000.00000000', (string) $t->buy_price);
        // fee = 0.0000025 × 215e9 + 548,127.99 = 537,500 + 548,127.99
        $this->assertSame('1085627.99000000', (string) $t->fee);
    }

    public function test_missing_leg_fees_are_estimated_and_marked(): void
    {
        $bot  = $this->bot();
        $buy  = $this->leg($bot, 'buy', self::P, '0.00578704', '0.0000144676', 'base');
        $sell = GridOrder::create([
            'bot_config_id' => $bot->id, 'type' => 'sell', 'status' => 'filled', 'price' => self::SELL_P,
            'amount' => '0.00577257', 'filled_amount' => '0.00577257', 'client_order_id' => 'est-sell',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:02:00',
        ]);

        $t = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('mixed', $t->fee_source);
        $this->assertSame('12414179.58', self::rial((string) $t->net_profit)); // estimate == 0.25% exactly
    }

    // ── KillSwitch (audit D3) ──────────────────────────────────────────────

    private function killSwitch(): KillSwitchService
    {
        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->never();
        return new KillSwitchService($md);
    }

    /**
     * Book 40 fee-net buy-first cycles of ~100M IRT at $spacing with the
     * given TRUE rates, then run the max-drawdown check (5% of 50M = 2.5M).
     */
    private function runCycles(BotConfig $bot, string $spacing, string $fb, string $fs): void
    {
        $q       = '0.00046296';                       // ≈ 100,000,000 IRT at 216e9
        $exitPx  = FeeModel::roundHalfUp(Money::mul(self::P, Money::add('1', $spacing)), 0);
        $buyFee  = Money::mul($q, $fb);
        $sellQty = Money::floorToScale(Money::sub($q, $buyFee), 8);
        for ($i = 0; $i < 40; $i++) {
            $buy  = $this->leg($bot, 'buy', self::P, $q, $buyFee, 'base');
            $sell = $this->leg($bot, 'sell', $exitPx, $sellQty, Money::mul(Money::mul($sellQty, $exitPx), $fs), 'quote');
            CompletedTrade::createFromOrders($buy, $sell);
        }
    }

    /**
     * D3: at 0.6% spacing with the real 25/25 fees every cycle truly makes
     * money (break-even ≈ 0.50%), so the drawdown check must NOT trip. Under
     * the old 35-bps booking (break-even 0.7025%) each cycle was booked as a
     * ~100k loss and 40 of them (~4M > 2.5M) stopped a profitable bot.
     */
    public function test_profitable_point_six_percent_bot_does_not_trip_max_drawdown(): void
    {
        $bot = $this->bot(['grid_spacing' => 0.6, 'total_capital' => 50_000_000, 'max_drawdown_percent' => 5, 'stop_loss_percent' => 0]);
        $this->runCycles($bot, '0.006', '0.0025', '0.0025');

        $this->assertSame(0, CompletedTrade::where('net_profit', '<', 0)->count(), 'every cycle is booked as a profit');
        $result = $this->killSwitch()->checkAndTrigger($bot);
        $this->assertFalse($result['triggered']);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /** A genuinely losing bot (0.6% spacing but the exchange really charges 35/35) still trips. */
    public function test_genuinely_losing_bot_trips_max_drawdown(): void
    {
        config(['trading.fees.buy_fee_bps' => '35', 'trading.fees.sell_fee_bps' => '35']);
        $bot = $this->bot(['grid_spacing' => 0.6, 'total_capital' => 50_000_000, 'max_drawdown_percent' => 5, 'stop_loss_percent' => 0]);
        $this->runCycles($bot, '0.006', '0.0035', '0.0035');

        $this->assertSame(40, CompletedTrade::where('net_profit', '<', 0)->count());
        $result = $this->killSwitch()->checkAndTrigger($bot);
        $this->assertTrue($result['triggered']);
        $this->assertSame('max_drawdown', $result['reason']);
    }

    /** And a 0.3% spacing at the real 25/25 fees (below break-even) loses and trips. */
    public function test_below_break_even_spacing_trips_at_real_rates(): void
    {
        $bot = $this->bot(['grid_spacing' => 0.3, 'total_capital' => 50_000_000, 'max_drawdown_percent' => 5, 'stop_loss_percent' => 0]);
        $this->runCycles($bot, '0.003', '0.0025', '0.0025');

        $result = $this->killSwitch()->checkAndTrigger($bot);
        $this->assertTrue($result['triggered']);
    }
}
