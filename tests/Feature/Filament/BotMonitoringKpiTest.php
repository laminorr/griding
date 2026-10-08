<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BotMonitoring;
use App\Models\BotConfig;
use App\Models\GridOrder;
use Database\Factories\BotConfigFactory;
use Database\Factories\CompletedTradeFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Live audit (bot 48) — the «مانیتورینگ زنده» KPIs must be backed by real
 * trading data:
 *   • «چرخه‌های کامل» = COUNT(completed_trades), not order legs / polling runs;
 *   • «نرخ موفقیت»   = profitable / all completed trades, null («—») at 0 trades;
 *   • avg_cycle_duration = completed_trades.created_at − earlier leg filled_at;
 *   • profit figures = SUM(net_profit).
 */
final class BotMonitoringKpiTest extends TestCase
{
    use BuildsGridSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    /** @return array<string,mixed> the live-view payload row for $bot */
    private function payloadFor(BotConfig $bot): array
    {
        $page = new BotMonitoring();
        $page->selectedBotId = $bot->id;

        $rows = collect($page->botDataPayload())->keyBy('id');
        $this->assertTrue($rows->has($bot->id));

        return $rows[$bot->id];
    }

    /**
     * One closed cycle: a filled buy, its filled continuation sell (both legs
     * paired, as CheckTradesJob links them) and the booked completed trade.
     */
    private function closedCycle(BotConfig $bot, \DateTimeInterface $buyAt, \DateTimeInterface $sellAt, string $netProfit): void
    {
        $buy = GridOrderFactory::new()->buy()->create([
            'bot_config_id' => $bot->id, 'status' => 'filled', 'filled_at' => $buyAt,
        ]);
        $sell = GridOrderFactory::new()->sell()->create([
            'bot_config_id' => $bot->id, 'status' => 'filled', 'filled_at' => $sellAt,
            'paired_order_id' => $buy->id,
        ]);
        $buy->update(['paired_order_id' => $sell->id]);

        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id,
            'buy_order_id'  => $buy->id,
            'sell_order_id' => $sell->id,
            'net_profit'    => $netProfit,
            'profit'        => (string) (int) round((float) $netProfit),
            'created_at'    => $sellAt,
            'updated_at'    => $sellAt,
        ]);
    }

    /** Production shape of bot 48: 4 trades, 12 orders (8 filled, 4 open). */
    public function test_production_shape_reports_trades_not_order_legs(): void
    {
        $bot = BotConfigFactory::new()->active()->create();
        $t0  = now()->subDays(5);

        // SUM(net_profit) = 399,113.68 rial, all positive.
        foreach (['100000.00', '99000.50', '100113.18', '100000.00'] as $i => $net) {
            $this->closedCycle($bot, $t0->copy()->addHours($i * 10), $t0->copy()->addHours($i * 10 + 2), $net);
        }
        // 4 open orders, two of them continuation-linked to nothing filled.
        GridOrderFactory::new()->buy()->placed()->count(2)->create(['bot_config_id' => $bot->id]);
        GridOrderFactory::new()->sell()->placed()->count(2)->create(['bot_config_id' => $bot->id]);

        $this->assertSame(12, GridOrder::where('bot_config_id', $bot->id)->count());
        $this->assertSame(8, GridOrder::where('bot_config_id', $bot->id)->where('status', 'filled')->count());

        $row = $this->payloadFor($bot);

        // Was 8 (one per paired filled LEG) — the bug seen on the live page.
        $this->assertSame(4, $row['total_cycles']);
        $this->assertSame(4, $row['debug']['completed_trades_total']);
        $this->assertEquals(100.0, $row['success_rate']);
        $this->assertEqualsWithDelta(399113.68, $row['debug']['profit_total'], 0.001);
        $this->assertCount(4, $row['active_orders']);
        // Each cycle: buy filled → booked 2h later.
        $this->assertEquals(120.0, $row['avg_cycle_duration']);
        // Nothing booked in the last 48h.
        $this->assertSame(0, $row['completed_trades_24h']);
        $this->assertEquals(0.0, $row['profit_24h']);
        $this->assertNull($row['profit_change_24h']);
    }

    public function test_zero_trades_shows_no_success_rate_or_cycle_duration(): void
    {
        $bot = BotConfigFactory::new()->active()->create();
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);

        $row = $this->payloadFor($bot);

        $this->assertSame(0, $row['total_cycles']);
        $this->assertNull($row['success_rate'], '0 trades must render «—», never 0% or 100%');
        $this->assertNull($row['avg_cycle_duration']);
        $this->assertNull($row['profit_change_24h']);
        $this->assertEquals(0.0, $row['debug']['profit_total']);
        $this->assertNull($row['last_trade_at']);
    }

    public function test_mixed_profit_success_rate_and_24h_figures(): void
    {
        $bot = BotConfigFactory::new()->active()->create();

        // Previous 24h window: one winner of 1,000.
        $this->closedCycle($bot, now()->subHours(40), now()->subHours(30), '1000');
        // Last 24h: a winner of 2,500.50 and a loser of −500.50.
        $this->closedCycle($bot, now()->subHours(10), now()->subHours(9), '2500.50');
        $this->closedCycle($bot, now()->subHours(5), now()->subHours(3), '-500.50');
        // A break-even trade is NOT a success (net_profit > 0 only).
        $this->closedCycle($bot, now()->subHours(2), now()->subHours(1), '0');

        $row = $this->payloadFor($bot);

        $this->assertSame(4, $row['total_cycles']);
        $this->assertEquals(50.0, $row['success_rate']); // 2 of 4
        $this->assertSame(3, $row['completed_trades_24h']);
        $this->assertEqualsWithDelta(2000.0, $row['profit_24h'], 0.001);
        $this->assertEqualsWithDelta(3000.0, $row['debug']['profit_total'], 0.001);
        // (2000 − 1000) / 1000 × 100
        $this->assertEquals(100.0, $row['profit_change_24h']);
        // durations 600, 60, 120, 60 min → 210
        $this->assertEquals(210.0, $row['avg_cycle_duration']);
    }

    public function test_one_of_three_profitable_rounds_to_one_decimal(): void
    {
        $bot = BotConfigFactory::new()->active()->create();
        $this->closedCycle($bot, now()->subHours(6), now()->subHours(5), '10');
        $this->closedCycle($bot, now()->subHours(4), now()->subHours(3), '-10');
        $this->closedCycle($bot, now()->subHours(2), now()->subHours(1), '-10');

        $this->assertEquals(33.3, $this->payloadFor($bot)['success_rate']);
    }

    public function test_open_continuation_leg_counts_as_an_active_order(): void
    {
        $bot = BotConfigFactory::new()->active()->create();
        $buy = GridOrderFactory::new()->buy()->create([
            'bot_config_id' => $bot->id, 'status' => 'filled', 'filled_at' => now()->subHour(),
        ]);
        $exit = GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id, 'paired_order_id' => $buy->id,
        ]);
        $buy->update(['paired_order_id' => $exit->id]);
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);

        $row = $this->payloadFor($bot);

        $this->assertCount(2, $row['active_orders'], 'the waiting exit sell is an open order on the book');
        $this->assertSame(0, $row['total_cycles']);
    }

    public function test_view_renders_dash_instead_of_fake_success_rate(): void
    {
        $blade = file_get_contents(resource_path('views/filament/pages/bot-monitoring.blade.php'));

        $this->assertStringNotContainsString("total_cycles > 0 ? '100' : '0'", $blade);
        $this->assertStringContainsString("bot.success_rate === null ? '—'", $blade);
        $this->assertStringContainsString("bot.avg_cycle_duration === null ? '—'", $blade);
        // Polling runs are labelled as check runs, never as trading cycles.
        $this->assertStringContainsString('اجرای بررسی ۲۴ ساعت', $blade);
        $this->assertStringNotContainsString('چرخه‌ها ۲۴ ساعت', $blade);
    }
}
