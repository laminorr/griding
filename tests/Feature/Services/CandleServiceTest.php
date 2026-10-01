<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\CandleService;
use App\Services\MarketDataLayer;
use App\Services\NobitexService;
use App\Services\NobitexWebSocketService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CandleService: REST history (mocked NobitexService::getOhlc) + live WS candle
 * overlay from mdl:candle:{SYMBOL}:{RESOLUTION}. No network.
 */
final class CandleServiceTest extends TestCase
{
    private const NOW = 1_731_900_000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(self::NOW));
    }

    /** @return array{t:int,o:string,h:string,l:string,c:string,v:string} */
    private function row(int $t, string $c = '100'): array
    {
        return ['t' => $t, 'o' => '90', 'h' => '110', 'l' => '80', 'c' => $c, 'v' => '1.5'];
    }

    /**
     * Expected output row for an IRT market: o/h/l/c TOMAN -> RIAL (×10), v unchanged.
     * Decimal-point shift done by hand so the expectation is independent of Money.
     */
    private function irr(array $row): array
    {
        foreach (['o', 'h', 'l', 'c'] as $f) {
            $s = $row[$f];
            if (str_contains($s, '.')) {
                [$int, $frac] = explode('.', $s, 2);
                $s = ltrim($int . $frac[0], '0') . (strlen($frac) > 1 ? '.' . substr($frac, 1) : '');
                $s = $s === '' ? '0' : $s;
            } else {
                $s .= '0';
            }
            $row[$f] = $s;
        }
        return $row;
    }

    /** @param array<string,mixed> $result */
    private function restReturns(array $result, int $times = 1): \Mockery\MockInterface
    {
        return $this->mock(NobitexService::class, function ($m) use ($result, $times) {
            $m->shouldReceive('getOhlc')->times($times)->andReturn($result);
        });
    }

    private function live(array $row, string $symbol = 'BTCIRT', string $res = '15'): void
    {
        Cache::put(MarketDataLayer::candleCacheKey($symbol, $res), $row, 300);
    }

    private function svc(): CandleService
    {
        return app(CandleService::class);
    }

    public function test_history_only_passes_rows_through_ascending_live_false(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(1800), $this->row(900)], 'errmsg' => null]);

        $out = $this->svc()->getCandles('btcirt', '15', 10);

        $this->assertSame([
            'status' => 'ok',
            'candles' => [$this->irr($this->row(900)), $this->irr($this->row(1800))],
            'live' => false,
            'errmsg' => null,
            'unit' => 'IRR',
        ], $out);
    }

    public function test_live_with_equal_t_replaces_last_row(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900), $this->row(1800, '100')], 'errmsg' => null]);
        $this->live($this->row(1800, '123.45'));

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertTrue($out['live']);
        $this->assertSame([$this->irr($this->row(900)), $this->irr($this->row(1800, '123.45'))], $out['candles']);
    }

    public function test_live_with_newer_t_is_appended(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900), $this->row(1800)], 'errmsg' => null]);
        $this->live($this->row(2700, '200'));

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertTrue($out['live']);
        $this->assertSame([$this->irr($this->row(900)), $this->irr($this->row(1800)), $this->irr($this->row(2700, '200'))], $out['candles']);
    }

    public function test_appended_live_keeps_at_most_count_rows(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900), $this->row(1800)], 'errmsg' => null]);
        $this->live($this->row(2700, '200'));

        $out = $this->svc()->getCandles('BTCIRT', '15', 2);

        $this->assertSame([$this->irr($this->row(1800)), $this->irr($this->row(2700, '200'))], $out['candles']);
    }

    public function test_older_live_candle_is_ignored(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900), $this->row(1800)], 'errmsg' => null]);
        $this->live($this->row(900, '999'));

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertFalse($out['live']);
        $this->assertSame([$this->irr($this->row(900)), $this->irr($this->row(1800))], $out['candles']);
    }

    public function test_live_candle_for_other_resolution_is_not_used(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900)], 'errmsg' => null]);
        $this->live($this->row(1800), 'BTCIRT', '60');

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertFalse($out['live']);
        $this->assertSame([$this->irr($this->row(900))], $out['candles']);
    }

    public function test_rest_result_is_cached_for_60_seconds_per_symbol_resolution_count(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900)], 'errmsg' => null], times: 1);

        $this->svc()->getCandles('BTCIRT', '15', 50);
        $this->travel(59)->seconds();
        $out = $this->svc()->getCandles('BTCIRT', '15', 50);

        $this->assertSame([$this->irr($this->row(900))], $out['candles']);
        // Mockery verifies getOhlc was called exactly once.
    }

    public function test_rest_cache_expires_and_keys_differ_by_count(): void
    {
        $this->mock(NobitexService::class, function ($m) {
            $m->shouldReceive('getOhlc')->times(3)->andReturn(['status' => 'ok', 'candles' => [$this->row(900)], 'errmsg' => null]);
        });

        $this->svc()->getCandles('BTCIRT', '15', 50);
        $this->svc()->getCandles('BTCIRT', '15', 51); // different count -> separate key
        $this->travel(61)->seconds();
        $this->svc()->getCandles('BTCIRT', '15', 50); // expired
    }

    public function test_rest_error_with_live_candle_returns_ok_with_that_candle(): void
    {
        $this->restReturns(['status' => 'error', 'candles' => [], 'errmsg' => 'boom']);
        $this->live($this->row(2700, '200'));

        $this->assertSame([
            'status' => 'ok', 'candles' => [$this->irr($this->row(2700, '200'))], 'live' => true, 'errmsg' => null, 'unit' => 'IRR',
        ], $this->svc()->getCandles('BTCIRT', '15'));
    }

    public function test_rest_no_data_with_live_candle_returns_ok_with_that_candle(): void
    {
        $this->restReturns(['status' => 'no_data', 'candles' => [], 'errmsg' => null]);
        $this->live($this->row(2700, '200'));

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertSame('ok', $out['status']);
        $this->assertSame([$this->irr($this->row(2700, '200'))], $out['candles']);
        $this->assertTrue($out['live']);
    }

    public function test_rest_error_without_live_is_passed_through(): void
    {
        $this->restReturns(['status' => 'error', 'candles' => [], 'errmsg' => 'Invalid symbol']);

        $this->assertSame([
            'status' => 'error', 'candles' => [], 'live' => false, 'errmsg' => 'Invalid symbol', 'unit' => 'IRR',
        ], $this->svc()->getCandles('BTCIRT', '15'));
    }

    public function test_rest_no_data_without_live_is_passed_through(): void
    {
        $this->restReturns(['status' => 'no_data', 'candles' => [], 'errmsg' => null]);

        $this->assertSame([
            'status' => 'no_data', 'candles' => [], 'live' => false, 'errmsg' => null, 'unit' => 'IRR',
        ], $this->svc()->getCandles('BTCIRT', '15'));
    }

    public function test_rest_exception_never_throws_and_returns_error(): void
    {
        $this->mock(NobitexService::class, function ($m) {
            $m->shouldReceive('getOhlc')->once()->andThrow(new \RuntimeException('Connection timed out'));
        });

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertSame('error', $out['status']);
        $this->assertSame([], $out['candles']);
        $this->assertFalse($out['live']);
        $this->assertSame('Connection timed out', $out['errmsg']);
    }

    public function test_countback_is_clamped_to_500_and_to_is_now(): void
    {
        $this->mock(NobitexService::class, function ($m) {
            $m->shouldReceive('getOhlc')
                ->once()
                ->with('BTCIRT', '60', self::NOW, null, 500)
                ->andReturn(['status' => 'ok', 'candles' => [$this->row(900)], 'errmsg' => null]);
        });

        $this->svc()->getCandles('BTCIRT', '60', 5000);
    }

    public function test_invalid_resolution_throws_invalid_argument(): void
    {
        $this->mock(NobitexService::class, fn($m) => $m->shouldNotReceive('getOhlc'));

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->getCandles('BTCIRT', '7');
    }

    public function test_ws_written_candle_round_trips_through_the_service(): void
    {
        // Writer and reader share the key + shape: what the WS consumer caches is overlaid as-is.
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(1731852000)], 'errmsg' => null]);
        $ws = new class extends NobitexWebSocketService {
            public function publish(string $channel, mixed $payload): void
            {
                $this->processPublicationPayload($channel, $payload);
            }
        };
        $ws->publish('public:candle-BTCIRT-15', json_encode(['t' => 1731852900, 'o' => 6240000001.0, 'h' => 6250000000.0, 'l' => 6238000000.0, 'c' => 6238031033.0, 'v' => 1.26]));

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertTrue($out['live']);
        $this->assertSame(
            ['t' => 1731852900, 'o' => '62400000010', 'h' => '62500000000', 'l' => '62380000000', 'c' => '62380310330', 'v' => '1.26'],
            end($out['candles'])
        );
    }

    // ---- Unit conversion (TOMAN -> RIAL for IRT markets) -------------------

    private function hostRow(int $t): array
    {
        // Real BTCIRT magnitudes seen on the host (TOMAN, as Nobitex returns them).
        return ['t' => $t, 'o' => '21638999996', 'h' => '21700000000', 'l' => '21600000000.5', 'c' => '21650000001', 'v' => '0.12345678'];
    }

    public function test_irt_rest_only_prices_are_multiplied_by_10_exactly_volume_unchanged(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->hostRow(900)], 'errmsg' => null]);

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertSame('IRR', $out['unit']);
        $this->assertFalse($out['live']);
        $this->assertSame(
            ['t' => 900, 'o' => '216389999960', 'h' => '217000000000', 'l' => '216000000005', 'c' => '216500000010', 'v' => '0.12345678'],
            $out['candles'][0]
        );
    }

    public function test_irt_live_only_candle_is_converted(): void
    {
        $this->restReturns(['status' => 'error', 'candles' => [], 'errmsg' => 'boom']);
        $this->live($this->hostRow(2700));

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertTrue($out['live']);
        $this->assertSame('IRR', $out['unit']);
        $this->assertSame('216389999960', $out['candles'][0]['o']);
        $this->assertSame('0.12345678', $out['candles'][0]['v']);
    }

    public function test_irt_merged_rest_and_live_are_all_converted_once(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->hostRow(900), $this->hostRow(1800)], 'errmsg' => null]);
        $this->live(['t' => 2700, 'o' => '21650000001', 'h' => '21660000000', 'l' => '21640000000', 'c' => '21655000000', 'v' => '2']);

        $out = $this->svc()->getCandles('BTCIRT', '15');

        $this->assertTrue($out['live']);
        $this->assertSame(['216389999960', '216389999960', '216500000010'], array_column($out['candles'], 'o'));
        $this->assertSame('216550000000', $out['candles'][2]['c']);
        $this->assertSame('2', $out['candles'][2]['v']);
    }

    public function test_conversion_does_not_touch_cached_raw_values(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->hostRow(900)], 'errmsg' => null]);
        $this->live($this->hostRow(900));

        $this->svc()->getCandles('BTCIRT', '15', 10);
        $out = $this->svc()->getCandles('BTCIRT', '15', 10); // second call served from cache

        // Raw caches keep the exchange (TOMAN) values ...
        $this->assertSame('21638999996', Cache::get(MarketDataLayer::candleCacheKey('BTCIRT', '15'))['o']);
        $this->assertSame('21638999996', Cache::get(CandleService::REST_CACHE_PREFIX . 'BTCIRT:15:10')['candles'][0]['o']);
        // ... and the output is converted exactly once (not ×100 on a cache hit).
        $this->assertSame('216389999960', $out['candles'][0]['o']);
    }

    public function test_non_irt_market_is_unchanged_and_reports_quote_unit(): void
    {
        $this->restReturns(['status' => 'ok', 'candles' => [$this->row(900, '67000.5')], 'errmsg' => null]);
        $this->live($this->row(1800, '67100.25'), 'BTCUSDT');

        $out = $this->svc()->getCandles('btcusdt', '15');

        $this->assertSame('USDT', $out['unit']);
        $this->assertSame([$this->row(900, '67000.5'), $this->row(1800, '67100.25')], $out['candles']);
    }

    public function test_error_result_still_carries_unit(): void
    {
        $this->restReturns(['status' => 'error', 'candles' => [], 'errmsg' => 'x']);

        $this->assertSame('USDT', $this->svc()->getCandles('ETHUSDT', '15')['unit']);
    }
}
