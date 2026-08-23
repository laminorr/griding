<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\DTOs\OrderBookDto;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Market-data audit fixes — three PUBLIC, UNSIGNED endpoints:
 *   1. Orderbook now tries the documented v3 PATH form first
 *      (GET /v3/orderbook/{symbol}) and falls back to the legacy attempts.
 *   2. The TraderBot User-Agent rides on EVERY request (public calls included),
 *      not just the signed ones.
 *   3. New getOhlc() reads TradingView-UDF candle history and normalizes it,
 *      preserving o/h/l/c/v as strings.
 */
final class NobitexMarketDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trading.nobitex.base_url'          => 'https://apiv2.nobitex.ir',
            // Empty api_key: these are public endpoints, so no Authorization
            // Token header should be attached.
            'trading.nobitex.api_key'           => '',
            'trading.nobitex.signed_user_agent' => 'TraderBot/Griding-1.0',
            'trading.nobitex.retry.times'       => 1,
            'trading.nobitex.retry.sleep'       => 0,
            'trading.nobitex.rate_limit.rpm'    => 1000,
        ]);
    }

    /* -----------------------------------------------------------------
     | CHANGE 1 — orderbook v3 path form is the primary attempt
     |------------------------------------------------------------------*/

    public function test_orderbook_hits_the_v3_path_form_first(): void
    {
        Http::fake([
            '*/v3/orderbook/BTCIRT' => Http::response([
                'status'         => 'ok',
                'lastUpdate'     => 1_700_000_000_000,
                'lastTradePrice' => '45000000000',
                'bids'           => [['44990000000', '0.5']],
                'asks'           => [['45010000000', '0.3']],
            ], 200),
        ]);

        $dto = (new NobitexService)->getOrderBook('BTCIRT');

        $this->assertInstanceOf(OrderBookDto::class, $dto);
        $this->assertSame('BTCIRT', $dto->symbol);
        $this->assertSame(45_000_000_000, $dto->lastPrice);
        $this->assertSame(44_990_000_000, $dto->bids[0]['price']);
        $this->assertSame(45_010_000_000, $dto->asks[0]['price']);

        // Exactly one request — the path form succeeded, no fallback needed —
        // and it was the documented PATH form (symbol in the path).
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/v3/orderbook/BTCIRT', $request->url());

            return true;
        });
    }

    public function test_orderbook_falls_back_when_the_v3_path_form_fails(): void
    {
        Http::fake([
            // Primary path form fails …
            '*/v3/orderbook/BTCIRT'   => Http::response('gateway error', 500),
            // … legacy v3 query alias answers instead.
            '*/market/orderbook-v3*'  => Http::response([
                'status'         => 'ok',
                'lastUpdate'     => 1_700_000_000_000,
                'lastTradePrice' => '45000000000',
                'bids'           => [['44980000000', '1.0']],
                'asks'           => [['45020000000', '2.0']],
            ], 200),
        ]);

        $dto = (new NobitexService)->getOrderBook('BTCIRT');

        // Fallback still produced a valid DTO.
        $this->assertInstanceOf(OrderBookDto::class, $dto);
        $this->assertSame(45_000_000_000, $dto->lastPrice);
        $this->assertSame(44_980_000_000, $dto->bids[0]['price']);

        // The FIRST attempt was the path form; the second was the legacy alias.
        $recorded = Http::recorded();
        $this->assertStringContainsString('/v3/orderbook/BTCIRT', $recorded[0][0]->url());
        $this->assertStringContainsString('/market/orderbook-v3', $recorded[1][0]->url());
    }

    /* -----------------------------------------------------------------
     | CHANGE 2 — TraderBot User-Agent on public calls
     |------------------------------------------------------------------*/

    public function test_public_orderbook_call_sends_the_traderbot_user_agent(): void
    {
        Http::fake([
            '*/v3/orderbook/BTCIRT' => Http::response([
                'status'         => 'ok',
                'lastUpdate'     => 1_700_000_000_000,
                'lastTradePrice' => '45000000000',
                'bids'           => [['44990000000', '0.5']],
                'asks'           => [['45010000000', '0.3']],
            ], 200),
        ]);

        (new NobitexService)->getOrderBook('BTCIRT');

        Http::assertSent(function ($request) {
            $this->assertTrue($request->hasHeader('User-Agent'));
            $this->assertSame('TraderBot/Griding-1.0', $request->header('User-Agent')[0]);
            // Public call: no signing headers, no legacy Token auth.
            $this->assertFalse($request->hasHeader('Nobitex-Signature'));
            $this->assertFalse($request->hasHeader('Authorization'));

            return true;
        });
    }

    public function test_public_market_stats_call_sends_the_traderbot_user_agent(): void
    {
        Http::fake([
            '*/market/stats*' => Http::response([
                'status' => 'ok',
                'stats'  => [
                    'btc-rls' => ['bestSell' => '45010000000', 'bestBuy' => '44990000000', 'latest' => '45000000000'],
                ],
            ], 200),
        ]);

        (new NobitexService)->getMarketStats('BTCIRT');

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent')
            && $request->header('User-Agent')[0] === 'TraderBot/Griding-1.0');
    }

    /* -----------------------------------------------------------------
     | CHANGE 3 — getOhlc()
     |------------------------------------------------------------------*/

    public function test_get_ohlc_ok_zips_candles_as_strings(): void
    {
        Http::fake([
            '*/market/udf/history*' => Http::response([
                's' => 'ok',
                't' => [1_700_000_000, 1_700_000_060],
                'o' => ['45000000000', '45010000000'],
                'h' => ['45050000000', '45060000000'],
                'l' => ['44990000000', '45000000000'],
                'c' => ['45010000000', '45055000000'],
                'v' => ['1.5', '2.25'],
            ], 200),
        ]);

        $result = (new NobitexService)->getOhlc('BTCIRT', '60', 1_700_000_120);

        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['errmsg']);
        $this->assertCount(2, $result['candles']);

        $first = $result['candles'][0];
        $this->assertSame(1_700_000_000, $first['t']);
        $this->assertIsInt($first['t']);

        // o/h/l/c/v preserved as STRINGS (no float precision loss).
        foreach (['o', 'h', 'l', 'c', 'v'] as $k) {
            $this->assertIsString($first[$k], "candle field {$k} must be a string");
        }
        $this->assertSame('45000000000', $first['o']);
        $this->assertSame('45050000000', $first['h']);
        $this->assertSame('44990000000', $first['l']);
        $this->assertSame('45010000000', $first['c']);
        $this->assertSame('1.5', $first['v']);

        $this->assertSame('45055000000', $result['candles'][1]['c']);
    }

    public function test_get_ohlc_no_data_returns_empty_candles(): void
    {
        Http::fake([
            '*/market/udf/history*' => Http::response(['s' => 'no_data'], 200),
        ]);

        $result = (new NobitexService)->getOhlc('BTCIRT', 'D', 1_700_000_120);

        $this->assertSame('no_data', $result['status']);
        $this->assertSame([], $result['candles']);
        $this->assertNull($result['errmsg']);
    }

    public function test_get_ohlc_error_returns_status_error_with_errmsg(): void
    {
        Http::fake([
            '*/market/udf/history*' => Http::response(['s' => 'error', 'errmsg' => 'bad range'], 200),
        ]);

        $result = (new NobitexService)->getOhlc('BTCIRT', '15', 1_700_000_120);

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['candles']);
        $this->assertSame('bad range', $result['errmsg']);
    }

    public function test_get_ohlc_rejects_invalid_resolution(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        (new NobitexService)->getOhlc('BTCIRT', '7', 1_700_000_120);
    }

    public function test_get_ohlc_clamps_countback_to_500(): void
    {
        Http::fake([
            '*/market/udf/history*' => Http::response(['s' => 'no_data'], 200),
        ]);

        (new NobitexService)->getOhlc('BTCIRT', '60', 1_700_000_120, from: 1_699_000_000, countback: 5000);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $this->assertSame('500', (string) ($q['countback'] ?? null));
            $this->assertSame('BTCIRT', $q['symbol'] ?? null);

            return true;
        });
    }

    public function test_get_ohlc_is_public_and_unsigned(): void
    {
        Http::fake([
            '*/market/udf/history*' => Http::response(['s' => 'no_data'], 200),
        ]);

        (new NobitexService)->getOhlc('BTCIRT', '60', 1_700_000_120);

        Http::assertSent(function ($request) {
            // No signing headers, no legacy Token auth — a purely public read.
            $this->assertFalse($request->hasHeader('Nobitex-Key'));
            $this->assertFalse($request->hasHeader('Nobitex-Signature'));
            $this->assertFalse($request->hasHeader('Nobitex-Timestamp'));
            $this->assertFalse($request->hasHeader('Authorization'));

            return true;
        });
    }
}
