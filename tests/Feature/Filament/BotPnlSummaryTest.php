<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BotMonitoring;
use App\Models\BotConfig;
use App\Services\BotPnlSummary;
use App\Services\MarketDataLayer;
use Database\Factories\BotConfigFactory;
use Database\Factories\CompletedTradeFactory;
use Database\Factories\GridOrderFactory;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * «پول من» — capital, realized + paper P&L and value now. Fixture numbers are
 * bot 48's live audit of 2026-10-24 (bot:audit §7): two open long cycles at
 * 214,900,000,000 rial → unrealized −232,213 and −92,569; realized 841,557.
 */
final class BotPnlSummaryTest extends TestCase
{
    use BuildsGridSchema;

    private const PRICE_NOW = '214900000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        config(['trading.fees.buy_fee_bps' => '25', 'trading.fees.sell_fee_bps' => '25']);
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    private function bot48Shape(): BotConfig
    {
        $bot = BotConfigFactory::new()->active()->create(['total_capital' => '50000000', 'symbol' => 'BTCIRT']);

        // Long cycle 1: re-arm buy 312 filled → exit sell 313 (0.000043) open.
        $p1 = GridOrderFactory::new()->buy()->create([
            'bot_config_id' => $bot->id, 'role' => 'rearm', 'status' => 'filled', 'filled_at' => now()->subDay(),
            'price' => '219213636950', 'amount' => '0.000044', 'filled_amount' => '0.000044',
            'avg_fill_price' => '219213636950', 'fee_amount' => '0.00000011', 'fee_currency' => 'base',
        ]);
        GridOrderFactory::new()->sell()->create([
            'bot_config_id' => $bot->id, 'role' => 'cycle_exit', 'status' => 'placed',
            'price' => '222501841510', 'amount' => '0.000043', 'filled_amount' => '0', 'paired_order_id' => $p1->id,
        ]);

        // Long cycle 2: grid buy 303 filled → exit sell 315 (0.000044) open.
        $p2 = GridOrderFactory::new()->buy()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'status' => 'filled', 'filled_at' => now()->subHours(9),
            'price' => '215925432400', 'amount' => '0.000044', 'filled_amount' => '0.000044',
            'avg_fill_price' => '215925432400', 'fee_amount' => '0.00000011', 'fee_currency' => 'base',
        ]);
        GridOrderFactory::new()->sell()->create([
            'bot_config_id' => $bot->id, 'role' => 'cycle_exit', 'status' => 'placed',
            'price' => '219164313890', 'amount' => '0.000044', 'filled_amount' => '0', 'paired_order_id' => $p2->id,
        ]);

        // A plain grid sell on the book is inventory, not a cycle: no P&L.
        GridOrderFactory::new()->sell()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'status' => 'placed',
            'price' => '229278547350', 'amount' => '0.000044',
        ]);

        CompletedTradeFactory::new()->create(['bot_config_id' => $bot->id, 'net_profit' => '500000', 'profit' => '500000']);
        CompletedTradeFactory::new()->create(['bot_config_id' => $bot->id, 'net_profit' => '341557', 'profit' => '341557']);

        return $bot;
    }

    public function test_matches_bot48_audit_numbers(): void
    {
        $bot = $this->bot48Shape();

        $s = app(BotPnlSummary::class)->summarize($bot, self::PRICE_NOW);

        $this->assertSame(841557, (int) round((float) $s['realized']));
        $this->assertSame(-324782, (int) round((float) $s['unrealized']));
        $this->assertSame(516775, (int) round((float) $s['net']));
        $this->assertSame(50516775, (int) round((float) $s['value_now']));
        $this->assertEqualsWithDelta(1.0336, (float) $s['net_percent'], 0.0001);
        $this->assertSame(2, $s['open_cycles']);
    }

    public function test_no_price_gives_null_paper_figures_not_zero(): void
    {
        $bot = $this->bot48Shape();

        $s = app(BotPnlSummary::class)->summarize($bot, null);

        $this->assertSame(841557, (int) round((float) $s['realized']));
        $this->assertNull($s['unrealized']);
        $this->assertNull($s['net']);
        $this->assertNull($s['value_now']);
        $this->assertNull($s['net_percent']);
    }

    public function test_short_cycle_is_valued_at_buy_back_cost(): void
    {
        $bot = BotConfigFactory::new()->active()->create(['total_capital' => '50000000']);
        $sell = GridOrderFactory::new()->sell()->create([
            'bot_config_id' => $bot->id, 'role' => 'initial_grid', 'status' => 'filled', 'filled_at' => now(),
            'price' => '225890194430', 'amount' => '0.000044', 'filled_amount' => '0.000044',
            'avg_fill_price' => '225890194430', 'fee_amount' => '24847.9213873', 'fee_currency' => 'quote',
        ]);
        GridOrderFactory::new()->buy()->create([
            'bot_config_id' => $bot->id, 'role' => 'cycle_exit', 'status' => 'placed',
            'price' => '222501841510', 'amount' => '0.000045', 'paired_order_id' => $sell->id,
        ]);

        $s = app(BotPnlSummary::class)->summarize($bot, '220000000000');

        // proceeds 9,939,168.55 − 24,847.92 ; buy back 0.000044/0.9975 × 220e9
        $proceeds = 0.000044 * 225890194430 - 24847.9213873;
        $buyBack = 0.000044 / 0.9975 * 220000000000;
        $this->assertEqualsWithDelta($proceeds - $buyBack, (float) $s['unrealized'], 1.0);
        $this->assertSame(1, $s['open_cycles']);
    }

    public function test_page_payload_carries_pnl_from_cached_live_price(): void
    {
        $bot = $this->bot48Shape();
        Cache::put(MarketDataLayer::CACHE_PREFIX_PRICE . 'BTCIRT', ['price' => (int) self::PRICE_NOW, 'ts' => time()], 60);

        $page = new BotMonitoring();
        $page->selectedBotId = $bot->id;
        $row = collect($page->botDataPayload())->keyBy('id')[$bot->id];

        $this->assertSame(self::PRICE_NOW, (string) $row['pnl']['price_now']);
        $this->assertSame(516775, (int) round((float) $row['pnl']['net']));
    }
}
