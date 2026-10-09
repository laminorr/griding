<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BotMonitoring;
use App\Models\GridOrder;
use App\Services\CandleService;
use Database\Factories\BotConfigFactory;
use Database\Factories\CompletedTradeFactory;
use Database\Factories\GridOrderFactory;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * «نمودار قیمت» annotations (BotMonitoring::getChartData): bot start marker,
 * merged fill markers, cycle profit, and the inactive-bot mode. CandleService
 * is mocked: no network.
 */
final class BotMonitoringChartAnnotationsTest extends TestCase
{
    use BuildsGridSchema;

    /** 15m candles: 2026-10-08 00:00 UTC + i×900s, i = 0..9 (00:00 → 02:15 open). */
    private int $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->base = Carbon::parse('2026-10-08 00:00:00', 'UTC')->getTimestamp();
        Carbon::setTestNow($this->at($this->base + 3 * 3600));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropGridSchema();
        parent::tearDown();
    }

    private function candles(int $n = 10, int $step = 900): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['t' => $this->base + $i * $step, 'o' => '1', 'h' => '2', 'l' => '1', 'c' => '2', 'v' => '0.1'];
        }

        return $out;
    }

    private function fakeCandles(array $candles): void
    {
        $this->mock(CandleService::class, function ($m) use ($candles) {
            $m->shouldReceive('getCandles')->andReturn([
                'status' => 'ok', 'candles' => $candles, 'live' => false, 'errmsg' => null, 'unit' => 'IRR',
            ]);
        });
    }

    private function chart(?int $botId, string $res = '15'): array
    {
        $page = new BotMonitoring();
        $page->selectedBotId = $botId;

        return $page->getChartData($res);
    }

    private function fill(int $botId, string $side, int $ts, string $price = '215000000000', string $amount = '0.001'): GridOrder
    {
        $o = GridOrderFactory::new()->{$side}()->filled()->create([
            'bot_config_id' => $botId, 'price' => $price, 'amount' => $amount, 'filled_amount' => $amount,
        ]);
        GridOrder::whereKey($o->id)->update(['filled_at' => $this->at($ts)]);

        return $o->fresh();
    }

    /** A unix time as an app-timezone Carbon — how the app writes datetimes. */
    private function at(int $ts): Carbon
    {
        return Carbon::createFromTimestamp($ts, config('app.timezone'));
    }

    private function setCreatedAt(int $orderId, int $ts): void
    {
        GridOrder::whereKey($orderId)->update(['created_at' => $this->at($ts)]);
    }

    public function test_start_t_is_the_candle_of_the_first_grid_order(): void
    {
        $bot = BotConfigFactory::new()->create();
        $bot->forceFill(['created_at' => $this->at($this->base - 86400)])->save();

        $a = GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
        $b = GridOrderFactory::new()->sell()->placed()->create(['bot_config_id' => $bot->id]);
        $this->setCreatedAt($a->id, $this->base + 2 * 900 + 100); // candle #2
        $this->setCreatedAt($b->id, $this->base + 5 * 900 + 10);

        $this->fakeCandles($this->candles());
        $out = $this->chart($bot->id);

        // The first ORDER, not bot_configs.created_at (a day earlier, out of range).
        $this->assertSame($this->base + 2 * 900, $out['start_t']);
    }

    public function test_start_t_falls_back_to_bot_created_at_and_is_null_out_of_range(): void
    {
        $bot = BotConfigFactory::new()->create();
        $bot->forceFill(['created_at' => $this->at($this->base + 4 * 900 + 1)])->save();

        $this->fakeCandles($this->candles());
        $this->assertSame($this->base + 4 * 900, $this->chart($bot->id)['start_t']);

        // Older than the first candle: nothing, the range is not stretched.
        $o = GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
        $this->setCreatedAt($o->id, $this->base - 1);
        $out = $this->chart($bot->id);
        $this->assertNull($out['start_t']);
        $this->assertSame($this->base, $out['candles'][0]['t']);
    }

    public function test_markers_cover_only_in_range_fills_and_merge_per_candle_and_side(): void
    {
        $bot   = BotConfigFactory::new()->create();
        $other = BotConfigFactory::new()->create();

        // Candle #1: two buys + one sell → one buy marker (count 2) + one sell marker.
        $this->fill($bot->id, 'buy', $this->base + 900 + 5, '210000000000', '0.001');
        $this->fill($bot->id, 'buy', $this->base + 900 + 600, '212000000000', '0.003');
        $this->fill($bot->id, 'sell', $this->base + 900 + 700, '220000000000', '0.002');
        // Candle #9 (last; inside its 15 minutes).
        $this->fill($bot->id, 'sell', $this->base + 9 * 900 + 899, '221000000000');
        // Out of range: before the first candle, after the last candle's close.
        $this->fill($bot->id, 'buy', $this->base - 1);
        $this->fill($bot->id, 'sell', $this->base + 10 * 900);
        // Not filled / another bot: never drawn.
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
        $this->fill($other->id, 'buy', $this->base + 900 + 5);

        $this->fakeCandles($this->candles());
        $markers = $this->chart($bot->id)['markers'];

        $this->assertCount(3, $markers);
        $this->assertSame(['t' => $this->base + 900, 'side' => 'buy', 'count' => 2], array_intersect_key($markers[0], array_flip(['t', 'side', 'count'])));
        // Amount-weighted mean price of the two buys; summed amount.
        $this->assertSame('211500000000', $markers[0]['price']);
        $this->assertSame('0.004', $markers[0]['amount']);
        $this->assertSame(['t' => $this->base + 900, 'side' => 'sell', 'count' => 1], array_intersect_key($markers[1], array_flip(['t', 'side', 'count'])));
        $this->assertSame('220000000000', $markers[1]['price']);
        $this->assertSame($this->base + 9 * 900, $markers[2]['t']);
        $this->assertNull($markers[2]['cycle_profit']);
        foreach ($markers as $m) {
            $this->assertSame(['t', 'side', 'count', 'price', 'amount', 'cycle_profit'], array_keys($m));
        }
    }

    public function test_fill_in_a_candle_gap_is_not_pulled_into_an_older_candle(): void
    {
        $bot = BotConfigFactory::new()->create();
        // Candles #0 and #2 only (no trades in #1); the fill sits inside #1.
        $candles = [$this->candles(3)[0], $this->candles(3)[2]];
        $this->fill($bot->id, 'buy', $this->base + 900 + 10);

        $this->fakeCandles($candles);
        $this->assertSame([], $this->chart($bot->id)['markers']);
    }

    public function test_markers_are_capped_at_the_newest_sixty(): void
    {
        $bot = BotConfigFactory::new()->create();
        // 70 one-minute candles, one buy fill in each.
        for ($i = 0; $i < 70; $i++) {
            $this->fill($bot->id, 'buy', $this->base + $i * 60 + 1);
        }

        $this->fakeCandles($this->candles(70, 60));
        $markers = $this->chart($bot->id, '1')['markers'];

        $this->assertCount(BotMonitoring::CHART_MARKER_CAP, $markers);
        $this->assertSame($this->base + 10 * 60, $markers[0]['t'], 'The oldest 10 are dropped.');
        $this->assertSame($this->base + 69 * 60, end($markers)['t']);
    }

    public function test_cycle_profit_comes_from_completed_trades_on_the_closing_leg(): void
    {
        $bot  = BotConfigFactory::new()->create();
        $buy  = $this->fill($bot->id, 'buy', $this->base + 60);
        $sell = $this->fill($bot->id, 'sell', $this->base + 3 * 900 + 60, '218000000000');
        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id, 'buy_order_id' => $buy->id, 'sell_order_id' => $sell->id,
            'net_profit' => 412345, 'profit' => 999,
        ]);

        // Reverse cycle (sell first, buy closes it); legacy row without net_profit.
        $sell2 = $this->fill($bot->id, 'sell', $this->base + 5 * 900 + 60, '219000000000');
        $buy2  = $this->fill($bot->id, 'buy', $this->base + 7 * 900 + 60, '217000000000');
        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id, 'buy_order_id' => $buy2->id, 'sell_order_id' => $sell2->id,
            'net_profit' => null, 'profit' => 1500,
        ]);

        $this->fakeCandles($this->candles());
        $byT = collect($this->chart($bot->id)['markers'])->keyBy(fn ($m) => $m['t'] . $m['side']);

        $this->assertNull($byT[($this->base) . 'buy']['cycle_profit'], 'Opening leg did not close a cycle.');
        $this->assertSame('412345', $byT[($this->base + 3 * 900) . 'sell']['cycle_profit']);
        $this->assertNull($byT[($this->base + 5 * 900) . 'sell']['cycle_profit']);
        $this->assertSame('1500', $byT[($this->base + 7 * 900) . 'buy']['cycle_profit']);
    }

    public function test_inactive_bot_gets_markers_and_start_but_no_live_levels(): void
    {
        $bot = BotConfigFactory::new()->create(['is_active' => false, 'symbol' => 'BTCIRT']);
        $o = GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id, 'price' => '214000000000']);
        $this->setCreatedAt($o->id, $this->base + 30);
        $this->fill($bot->id, 'sell', $this->base + 2 * 900 + 30);

        $this->fakeCandles($this->candles());
        $out = $this->chart($bot->id);

        $this->assertSame('ok', $out['status']);
        $this->assertSame('inactive', $out['mode']);
        $this->assertSame([], $out['levels']);
        $this->assertSame(1, $out['stale_levels'], 'Leftover placed rows are counted, not drawn.');
        $this->assertCount(1, $out['markers']);
        $this->assertSame($this->base, $out['start_t']);
    }

    public function test_active_bot_mode_and_levels_unchanged(): void
    {
        $bot = BotConfigFactory::new()->create(['is_active' => true]);
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id, 'price' => '214000000000']);

        $this->fakeCandles($this->candles());
        $out = $this->chart($bot->id);

        $this->assertSame('bot', $out['mode']);
        $this->assertSame([['price' => '214000000000', 'side' => 'buy']], $out['levels']);
        $this->assertSame(0, $out['stale_levels']);
    }

    public function test_invalid_resolution_still_rejected_with_empty_annotations(): void
    {
        $bot = BotConfigFactory::new()->create();
        $this->mock(CandleService::class, fn ($m) => $m->shouldNotReceive('getCandles'));

        $out = $this->chart($bot->id, '5');

        $this->assertSame('invalid_resolution', $out['status']);
        $this->assertSame([], $out['candles']);
        $this->assertSame([], $out['markers']);
        $this->assertNull($out['start_t']);
    }
}
