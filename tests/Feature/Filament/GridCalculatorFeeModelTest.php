<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\GridCalculator;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fee model — the panel calculator reads every fee figure from FeeModel
 * (per-side rates, buy-first vs sell-first cycle per level) and renders.
 */
final class GridCalculatorFeeModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.fees.buy_fee_bps'  => '25',
            'trading.fees.sell_fee_bps' => '35',
            'trading.min_order_value_irt' => 3_000_000,
            'trading.ticks.BTCIRT' => 10,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    public function test_per_cycle_and_round_totals_use_feemodel_per_level_side(): void
    {
        // 4 levels, both mode, 100% of 4,000,000,000 → 4 levels × 1,000,000,000.
        // s = 1%: buy level fee = 0.0025×1e9 + 0.0035×1.01e9 = 2,500,000 + 3,535,000 = 6,035,000
        //         sell level fee = 0.0025×0.99e9 + 0.0035×1e9 = 2,475,000 + 3,500,000 = 5,975,000
        // gross per level = 10,000,000.
        $page = new GridCalculator();
        $page->centerPrice   = 216_000_000_000;
        $page->totalCapital  = 4_000_000_000;
        $page->activePercent = 100;
        $page->gridSpacing   = 1;
        $page->gridLevels    = 4;
        $page->mode          = 'both';
        $page->calculate();

        $this->assertNull($page->calcError);
        $this->assertSame('25', $page->buyFeeBps);
        $this->assertSame('35', $page->sellFeeBps);
        $this->assertSame(1_000_000_000, $page->repNotional);  // first item is a buy level
        $this->assertSame(10_000_000, $page->grossPerCycle);
        $this->assertSame(6_035_000, $page->feePerCycle);
        $this->assertSame(3_965_000, $page->netPerCycle);

        $this->assertSame(4, $page->roundCycles);
        $this->assertSame(40_000_000, $page->roundGrossTotal);
        $this->assertSame(2 * 6_035_000 + 2 * 5_975_000, $page->roundFeeTotal);
        $this->assertSame(40_000_000 - 24_020_000, $page->roundNetTotal);
    }

    public function test_page_renders_with_per_side_rates(): void
    {
        $this->actingAs(new User(['name' => 't', 'email' => 't@example.com']));

        Livewire::test(GridCalculator::class)
            ->set('centerPrice', 216_000_000_000)
            ->set('totalCapital', 4_000_000_000)
            ->set('activePercent', 100)
            ->set('gridSpacing', 1)
            ->set('gridLevels', 4)
            ->call('calculate')
            ->assertSee('کارمزد خرید')
            ->assertSee('کارمزد فروش');
    }
}
