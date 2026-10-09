<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BotMonitoring;
use App\Services\CandleService;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * W2 — «نمودار قیمت» data API (BotMonitoring::getChartData) and the live-view
 * refresh API (BotMonitoring::getBotData, now polled via $wire every 30s).
 * CandleService is mocked: no network.
 */
final class BotMonitoringChartDataTest extends TestCase
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

    private function pageFor(?int $botId): BotMonitoring
    {
        $page = new BotMonitoring();
        $page->selectedBotId = $botId;

        return $page;
    }

    public function test_rejects_unsupported_resolution_without_calling_candle_service(): void
    {
        $bot = BotConfigFactory::new()->create();
        $this->mock(CandleService::class, fn ($m) => $m->shouldNotReceive('getCandles'));

        foreach (['5', '240', '1D', 'W', '', '15; DROP'] as $res) {
            $out = $this->pageFor($bot->id)->getChartData($res);

            $this->assertSame('invalid_resolution', $out['status'], "resolution '{$res}' must be rejected");
            $this->assertSame([], $out['candles']);
            $this->assertSame([], $out['levels']);
        }
    }

    public function test_returns_candles_from_candle_service_and_only_open_levels_of_selected_bot(): void
    {
        $bot   = BotConfigFactory::new()->create(['symbol' => 'BTCIRT']);
        $other = BotConfigFactory::new()->create(['symbol' => 'BTCIRT']);

        // Open orders of the selected bot (placed + partially_filled).
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id, 'price' => '215000000000']);
        GridOrderFactory::new()->sell()->placed()->create(['bot_config_id' => $bot->id, 'price' => '218000000000']);
        GridOrderFactory::new()->sell()->partiallyFilled()->create(['bot_config_id' => $bot->id, 'price' => '219000000000']);
        // Not open: must not be drawn.
        GridOrderFactory::new()->buy()->filled()->create(['bot_config_id' => $bot->id, 'price' => '214000000000']);
        GridOrderFactory::new()->buy()->cancelled()->create(['bot_config_id' => $bot->id, 'price' => '213000000000']);
        GridOrderFactory::new()->buy()->pending()->create(['bot_config_id' => $bot->id, 'price' => '212000000000']);
        // Another bot's open order: must not leak in.
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $other->id, 'price' => '211000000000']);

        $candles = [
            ['t' => 900,  'o' => '216389999960', 'h' => '217000000000', 'l' => '216000000000', 'c' => '216500000010', 'v' => '0.5'],
            ['t' => 1800, 'o' => '216500000010', 'h' => '216900000000', 'l' => '216100000000', 'c' => '216700000000', 'v' => '1.25'],
        ];
        $this->mock(CandleService::class, function ($m) use ($candles) {
            $m->shouldReceive('getCandles')->once()->with('BTCIRT', '15', 200)->andReturn([
                'status' => 'ok', 'candles' => $candles, 'live' => true, 'errmsg' => null, 'unit' => 'IRR',
            ]);
        });

        $out = $this->pageFor($bot->id)->getChartData('15');

        $this->assertSame('ok', $out['status']);
        $this->assertTrue($out['live']);
        $this->assertSame('IRR', $out['unit']);
        $this->assertSame('BTCIRT', $out['symbol']);
        $this->assertSame('15', $out['resolution']);
        $this->assertSame($candles, $out['candles']);

        $levels = $out['levels'];
        usort($levels, fn ($a, $b) => strcmp($a['price'], $b['price']));
        $this->assertSame([
            ['price' => '215000000000', 'side' => 'buy'],
            ['price' => '218000000000', 'side' => 'sell'],
            ['price' => '219000000000', 'side' => 'sell'],
        ], $levels);
        foreach ($out['levels'] as $lv) {
            $this->assertIsString($lv['price'], 'Level prices stay decimal strings server-side.');
        }
    }

    public function test_symbol_falls_back_to_btcirt_only_when_bot_has_none(): void
    {
        $bot = BotConfigFactory::new()->create(['symbol' => 'ETHUSDT']);
        $this->mock(CandleService::class, function ($m) {
            $m->shouldReceive('getCandles')->once()->with('ETHUSDT', 'D', 200)
                ->andReturn(['status' => 'no_data', 'candles' => [], 'live' => false, 'errmsg' => null, 'unit' => 'USDT']);
        });

        $out = $this->pageFor($bot->id)->getChartData('D');

        $this->assertSame('no_data', $out['status']);
        $this->assertSame('USDT', $out['unit']);
        $this->assertSame([], $out['candles']);
        $this->assertFalse($out['live']);
    }

    public function test_candle_service_failure_is_an_error_status_not_an_exception(): void
    {
        $bot = BotConfigFactory::new()->create(['symbol' => 'BTCIRT']);
        $this->mock(CandleService::class, fn ($m) => $m->shouldReceive('getCandles')->andThrow(new \RuntimeException('boom')));

        $out = $this->pageFor($bot->id)->getChartData('60');

        $this->assertSame('error', $out['status']);
        $this->assertSame([], $out['candles']);
        $this->assertSame('IRR', $out['unit']);
    }

    public function test_no_selected_bot_shows_default_market_candles_without_annotations(): void
    {
        config(['trading.websocket.candle_symbols' => ['BTCIRT']]);
        $candles = [['t' => 900, 'o' => '1', 'h' => '2', 'l' => '1', 'c' => '2', 'v' => '0.1']];
        $this->mock(CandleService::class, function ($m) use ($candles) {
            $m->shouldReceive('getCandles')->once()->with('BTCIRT', '15', 200)
                ->andReturn(['status' => 'ok', 'candles' => $candles, 'live' => false, 'errmsg' => null, 'unit' => 'IRR']);
        });

        $out = $this->pageFor(null)->getChartData('15');

        $this->assertSame('ok', $out['status']);
        $this->assertSame('market', $out['mode']);
        $this->assertSame('BTCIRT', $out['symbol']);
        $this->assertSame($candles, $out['candles']);
        $this->assertSame([], $out['levels']);
        $this->assertSame([], $out['markers']);
        $this->assertNull($out['start_t']);
    }

    public function test_get_bot_data_is_json_serializable_with_the_same_top_level_shape(): void
    {
        $bot = BotConfigFactory::new()->create(['last_check_at' => now()]);
        GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
        GridOrderFactory::new()->sell()->placed()->create(['bot_config_id' => $bot->id]);

        $data = $this->pageFor($bot->id)->getBotData();

        $this->assertIsArray($data);
        $this->assertTrue(array_is_list($data));
        $this->assertCount(1, $data);

        // Regression guard: the exact keys the Alpine live view reads.
        $this->assertSame([
            'id', 'name', 'symbol', 'status', 'capital', 'grid_levels', 'grid_spacing',
            'active_orders', 'filled_24h', 'completed_trades_24h', 'profit_24h',
            'profit_change_24h', 'last_check_at', 'last_trade_at',
            'daily_profits', 'fill_distribution', 'avg_cycle_duration', 'total_cycles',
            'success_rate', 'activity_cycles', 'activity_summary', 'debug',
        ], array_keys($data[0]));

        // Plain arrays only (no Collections / Carbon): survives a JSON round trip unchanged.
        $this->assertSame($data, json_decode(json_encode($data, JSON_THROW_ON_ERROR), true));
        $this->assertTrue(array_is_list($data[0]['active_orders']));
        $this->assertCount(2, $data[0]['active_orders']);
        $this->assertSame(
            ['id', 'type', 'price', 'amount', 'status', 'paired_order_id', 'nobitex_order_id'],
            array_keys($data[0]['active_orders'][0])
        );
        $this->assertIsString($data[0]['last_check_at']);
        $this->assertCount(30, $data[0]['daily_profits']);
        $this->assertCount(24, $data[0]['fill_distribution']);

        // The server-render seed and the $wire poll return the same thing.
        $this->assertSame($data, $this->pageFor($bot->id)->botDataPayload());
    }
}
