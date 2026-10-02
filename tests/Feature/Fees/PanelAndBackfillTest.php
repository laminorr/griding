<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Filament\Pages\GridCalculator;
use App\Filament\Resources\BotConfigResource;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Models\User;
use App\Services\FeeModel;
use App\Support\Money;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Fee model Phase 8 — panel / calculator consistency (D7, D8, D11) and the
 * fees:backfill command (E7).
 */
final class PanelAndBackfillTest extends TestCase
{
    use BuildsGridSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        config([
            'trading.fees.buy_fee_bps' => '25', 'trading.fees.sell_fee_bps' => '25',
            'trading.fees.buy_fee_currency' => 'base', 'trading.fees.sell_fee_currency' => 'quote',
            'trading.fees.spacing_margin_bps' => '10',
            'trading.fees.restore_inventory_on_buy_exit' => true,
            'trading.fees.model_version' => 1,
            'trading.min_order_value_irt' => 3_000_000,
            'trading.ticks.BTCIRT' => 10,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    private function bot(array $over = []): BotConfig
    {
        return BotConfig::create(array_merge(['name' => 'p8', 'symbol' => 'BTCIRT', 'simulation' => false, 'is_active' => true, 'grid_spacing' => 1.5], $over));
    }

    // ── D8: profitable filter ──────────────────────────────────────────────

    public function test_profitable_filter_no_longer_subtracts_the_fee_twice(): void
    {
        $netWinner = $this->bot(['name' => 'net-winner']);
        $loser     = $this->bot(['name' => 'loser']);
        // profit is already NET (100 after a 150 fee). Old filter: 100 − 150 < 0 → hidden.
        DB::table('completed_trades')->insert([
            ['bot_config_id' => $netWinner->id, 'buy_price' => 1, 'sell_price' => 2, 'amount' => '0.001', 'profit' => 100, 'fee' => 150, 'created_at' => now(), 'updated_at' => now()],
            ['bot_config_id' => $loser->id, 'buy_price' => 1, 'sell_price' => 2, 'amount' => '0.001', 'profit' => -50, 'fee' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ids = BotConfigResource::applyProfitableFilter(BotConfig::query())->pluck('id')->all();

        $this->assertSame([$netWinner->id], $ids);
    }

    // ── break-even / spacing warnings (D7) ────────────────────────────────

    public function test_minimum_spacing_is_max_break_even_plus_margin(): void
    {
        $fm = app(FeeModel::class);
        // 25/25: max break-even = 1/0.9975² − 1 = 0.50188…% ; + 0.10% → 0.60188… → ceil4 0.6019
        $this->assertSame('0.6019', $fm->minimumSpacingPct());
        $this->assertTrue($fm->spacingBelowMinimum('0.6'));
        $this->assertFalse($fm->spacingBelowMinimum('0.61'));

        // Form helper honours the bot's fee overrides.
        $this->assertNotNull(BotConfigResource::spacingWarning('0.6'));
        $this->assertNull(BotConfigResource::spacingWarning('1.5'));
        $this->assertNull(BotConfigResource::spacingWarning('0.6', '15', '15')); // cheaper tier: min ≈ 0.40%
        $this->assertNotNull(BotConfigResource::spacingWarning('0.8', '35', '35')); // 0.7037% + 0.10%
        $this->assertNull(BotConfigResource::spacingWarning(''));
    }

    public function test_calculator_shows_both_break_evens_and_warns_below_minimum(): void
    {
        $page = new GridCalculator();
        $page->centerPrice = 216_000_000_000;
        $page->totalCapital = 4_000_000_000;
        $page->activePercent = 100;
        $page->gridLevels = 4;
        $page->gridSpacing = 0.5;
        $page->calculate();

        $this->assertSame('0.5019', $page->breakEvenBuyFirstPct);   // audit C4, net-sized sell
        $this->assertSame('0.4994', $page->breakEvenSellFirstPct);  // audit C4, restoring buy
        $this->assertSame('0.6019', $page->minSpacingPct);
        $this->assertTrue($page->spacingTooTight);

        $page->gridSpacing = 1.5;
        $page->calculate();
        $this->assertFalse($page->spacingTooTight);
    }

    public function test_calculator_renders_the_warning(): void
    {
        $this->actingAs(new User(['name' => 't', 'email' => 't@example.com']));

        Livewire::test(GridCalculator::class)
            ->set('centerPrice', 216_000_000_000)
            ->set('totalCapital', 4_000_000_000)
            ->set('activePercent', 100)
            ->set('gridLevels', 4)
            ->set('gridSpacing', 0.5)
            ->call('calculate')
            ->assertSee('سربه‌سر')
            ->assertSee('کمتر از حداقل سودآور');
    }

    public function test_bot_form_offers_optional_fee_overrides(): void
    {
        $this->actingAs(new User(['name' => 't', 'email' => 't@example.com']));

        Livewire::test(\App\Filament\Resources\BotConfigResource\Pages\CreateBotConfig::class)
            ->assertFormFieldExists('buy_fee_bps')
            ->assertFormFieldExists('sell_fee_bps')
            ->fillForm(['grid_spacing' => 0.55])
            ->assertSee('کمتر از حداقل سودآور');
    }

    // ── fees:backfill ──────────────────────────────────────────────────────

    /** A legacy row as the old model booked it (35 bps both legs on limit prices). */
    private function legacyTrade(BotConfig $bot, bool $withOrders = true): CompletedTrade
    {
        $buyId = $sellId = null;
        if ($withOrders) {
            $buyId = GridOrder::create(['bot_config_id' => $bot->id, 'type' => 'buy', 'status' => 'filled', 'price' => '216000000000',
                'amount' => '0.00578704', 'filled_amount' => '0.00578704', 'client_order_id' => uniqid('lb')])->id;
            $sellId = GridOrder::create(['bot_config_id' => $bot->id, 'type' => 'sell', 'status' => 'filled', 'price' => '219240000000',
                'amount' => '0.00578704', 'filled_amount' => '0.00578704', 'client_order_id' => uniqid('ls')])->id;
        }
        // audit C1 "recorded today": gross 18,750,009.60 / fee 8,815,629.51 / net 9,934,380.09
        $id = DB::table('completed_trades')->insertGetId([
            'bot_config_id' => $bot->id, 'buy_order_id' => $buyId, 'sell_order_id' => $sellId,
            'buy_price' => '216000000000', 'sell_price' => '219240000000', 'amount' => '0.00578704',
            'profit' => '9934380', 'fee' => '8815630', 'gross_profit' => '18750009.6', 'net_profit' => '9934380.09',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return CompletedTrade::findOrFail($id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $bot = $this->bot();
        $t   = $this->legacyTrade($bot);

        $this->assertSame(0, Artisan::call('fees:backfill', ['--dry-run' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('DRY-RUN', $out);
        $this->assertStringContainsString('12453131', $out); // Σ net under the fee model (audit C1 true net, gross sizing)

        $fresh = $t->fresh();
        $this->assertNull($fresh->fee_model_version);
        $this->assertNull($fresh->profit_v0);
        $this->assertSame('9934380.09000000', (string) $fresh->net_profit);
    }

    public function test_stamp_only_copies_v0_and_marks_estimated_without_changing_profit(): void
    {
        $bot = $this->bot();
        $t   = $this->legacyTrade($bot);

        $this->assertSame(0, Artisan::call('fees:backfill'));

        $f = $t->fresh();
        $this->assertSame(0, $f->fee_model_version);
        $this->assertSame('estimated', $f->fee_source);
        $this->assertSame('9934380', Money::trimZeros((string) $f->profit_v0));
        $this->assertSame('9934380.09', Money::trimZeros((string) $f->net_profit_v0));
        $this->assertSame('9934380.09000000', (string) $f->net_profit); // unchanged
    }

    public function test_apply_recomputes_with_feemodel_and_keeps_the_originals(): void
    {
        $bot     = $this->bot();
        $t       = $this->legacyTrade($bot);                      // with its orders
        $orphan  = $this->legacyTrade($bot, withOrders: false);   // orders gone → legs from the row
        $modern  = $this->legacyTrade($bot);
        DB::table('completed_trades')->where('id', $modern->id)->update(['fee_model_version' => 1, 'net_profit' => '123']);

        $this->assertSame(0, Artisan::call('fees:backfill', ['--apply' => true]));

        foreach ([$t, $orphan] as $row) {
            $f = $row->fresh();
            $this->assertSame(1, $f->fee_model_version);
            $this->assertSame('estimated', $f->fee_source);
            // audit C1 true net at 25/25 with the (legacy) gross-sized sell
            $this->assertSame('12453131.38', FeeModel::roundHalfUp((string) $f->net_profit, 2));
            $this->assertSame('9934380.09', Money::trimZeros((string) $f->net_profit_v0)); // original kept
            $this->assertSame('base', $f->buy_fee_currency);
            $this->assertSame('-0.000014467600000000', (string) $f->base_residual); // the D1 shortfall, now visible
            $this->assertSame('0.00578704', (string) $f->amount);                    // identity untouched
        }

        // A row booked by the fee model is never touched.
        $this->assertSame('123.00000000', (string) $modern->fresh()->net_profit);
        $this->assertNull($modern->fresh()->profit_v0);

        // Re-running is idempotent: v0 is never overwritten.
        Artisan::call('fees:backfill', ['--apply' => true]);
        $this->assertSame('9934380.09', Money::trimZeros((string) $t->fresh()->net_profit_v0));
    }

    public function test_apply_after_stamp_keeps_the_true_originals(): void
    {
        $bot = $this->bot();
        $t   = $this->legacyTrade($bot);

        Artisan::call('fees:backfill');                        // stamp
        Artisan::call('fees:backfill', ['--apply' => true]);   // then recompute

        $f = $t->fresh();
        $this->assertSame('9934380.09', Money::trimZeros((string) $f->net_profit_v0));
        $this->assertSame(1, $f->fee_model_version);
    }
}
