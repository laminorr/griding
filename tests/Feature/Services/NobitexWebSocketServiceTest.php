<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\DTOs\OrderBookDto;
use App\Services\MarketDataLayer;
use App\Services\NobitexService;
use App\Services\NobitexWebSocketService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Network-free tests for the WS consumer's cache/heartbeat logic. No socket is
 * opened: a test double exposes the protected seams and drives a fake clock.
 */
final class NobitexWebSocketServiceTest extends TestCase
{
    private const OB = MarketDataLayer::CACHE_PREFIX_ORDERBOOK;
    private const PX = MarketDataLayer::CACHE_PREFIX_PRICE;

    private function service(int $intervalMs = 1000): TestableWsService
    {
        config(['trading.websocket.cache_write_interval_ms' => $intervalMs]);
        Cache::flush();

        return new TestableWsService();
    }

    /** @return array<string,mixed> */
    private function pub(int $bid, int $ask, int $last, int $lastUpdate = 1700000000123): array
    {
        return [
            'asks' => [[(string) $ask, '0.5'], [(string) ($ask + 10), '1.25']],
            'bids' => [[(string) $bid, '0.75']],
            'lastTradePrice' => (string) $last,
            'lastUpdate' => $lastUpdate,
        ];
    }

    /* ---------------------------- handshake ---------------------------- */

    public function test_handshake_headers_never_include_authorization_even_with_api_key(): void
    {
        config(['trading.nobitex.api_key' => 'legacy-token-123']);

        $headers = (new TestableWsService())->handshakeHeaders();

        $this->assertArrayNotHasKey('Authorization', $headers);
        foreach (array_keys($headers) as $name) {
            $this->assertNotSame('authorization', strtolower((string) $name));
        }
    }

    /* ------------------------------ seeding ----------------------------- */

    public function test_seed_writes_both_keys_in_the_same_shape_as_a_publication(): void
    {
        // Reference: what a WS publication writes.
        $ws = $this->service();
        $ws->clockMs = 1_000_000;
        $ws->clockS = 1000;
        $ws->publish('BTCIRT', json_encode($this->pub(100, 110, 105, 1700000000000)));
        $pubOb = Cache::get(self::OB . 'BTCIRT');
        $pubPx = Cache::get(self::PX . 'BTCIRT');

        // Seed from a mocked REST orderbook carrying the same book.
        $ws = $this->service();
        $ws->clockMs = 1_000_000;
        $ws->clockS = 1000;
        $this->mock(NobitexService::class, function ($m) {
            $m->shouldReceive('getOrderBook')->once()->with('BTCIRT')->andReturn(new OrderBookDto(
                symbol: 'BTCIRT',
                lastPrice: 105,
                ts: 1700000000,
                bids: [['price' => 100, 'quantity' => '0.75']],
                asks: [['price' => 110, 'quantity' => '0.5'], ['price' => 120, 'quantity' => '1.25']],
            ));
        });
        $ws->seed(['BTCIRT']);

        $this->assertSame($pubOb, Cache::get(self::OB . 'BTCIRT'));
        $this->assertSame($pubPx, Cache::get(self::PX . 'BTCIRT'));
        $this->assertSame([
            'asks' => [['110', '0.5'], ['120', '1.25']],
            'bids' => [['100', '0.75']],
            'lastTradePrice' => 105,
            'lastUpdate' => 1700000000000,
        ], Cache::get(self::OB . 'BTCIRT'));
        $this->assertSame(['price' => 105, 'ts' => 1000], Cache::get(self::PX . 'BTCIRT'));
    }

    public function test_seed_failure_for_one_symbol_is_logged_and_does_not_throw(): void
    {
        $ws = $this->service();
        $this->mock(NobitexService::class, function ($m) {
            $m->shouldReceive('getOrderBook')->with('BTCIRT')->andThrow(new \RuntimeException('boom'));
            $m->shouldReceive('getOrderBook')->with('ETHIRT')->andReturn(new OrderBookDto(
                symbol: 'ETHIRT', lastPrice: 50, ts: 1700000000,
                bids: [['price' => 49, 'quantity' => '1']], asks: [['price' => 51, 'quantity' => '2']],
            ));
        });

        $ws->seed(['BTCIRT', 'ETHIRT']);

        $this->assertNull(Cache::get(self::OB . 'BTCIRT'));
        $this->assertSame(50, Cache::get(self::PX . 'ETHIRT')['price']);
        $this->assertContains(['warning', '[WS] Orderbook seed failed', 'BTCIRT'], array_map(
            fn($l) => [$l[0], $l[1], $l[2]['symbol'] ?? null],
            $ws->logs
        ));
    }

    public function test_seed_bypasses_the_throttle(): void
    {
        $ws = $this->service(1000);
        $ws->clockMs = 5000;
        $ws->publish('BTCIRT', $this->pub(100, 110, 105));
        $this->mock(NobitexService::class, function ($m) {
            $m->shouldReceive('getOrderBook')->andReturn(new OrderBookDto(
                symbol: 'BTCIRT', lastPrice: 200, ts: 1700000000,
                bids: [['price' => 199, 'quantity' => '1']], asks: [['price' => 201, 'quantity' => '1']],
            ));
        });

        $ws->clockMs = 5100; // inside the interval
        $ws->seed(['BTCIRT']);

        $this->assertSame(200, Cache::get(self::PX . 'BTCIRT')['price']);
        $this->assertSame(2, $ws->cacheWrites['BTCIRT']);
    }

    /* ------------------------------ throttle ---------------------------- */

    public function test_two_publications_within_interval_write_once_and_latest_is_flushed_on_later_frame(): void
    {
        $ws = $this->service(1000);
        $ws->clockMs = 10_000;
        $ws->publish('BTCIRT', $this->pub(100, 110, 105));

        $ws->clockMs = 10_400;
        $ws->publish('BTCIRT', $this->pub(101, 111, 106));

        $this->assertSame(1, $ws->cacheWrites['BTCIRT']);
        $this->assertSame(105, Cache::get(self::PX . 'BTCIRT')['price']);
        // In-memory snapshot is always the latest.
        $this->assertSame(106, $ws->getLastPriceSnapshot('BTCIRT')['price']);

        // A frame (e.g. a {} ping) before the interval elapses: still held.
        $ws->clockMs = 10_900;
        $ws->frame();
        $this->assertSame(1, $ws->cacheWrites['BTCIRT']);

        // A frame after the interval: the dirty snapshot is flushed.
        $ws->clockMs = 11_000;
        $ws->frame();
        $this->assertSame(2, $ws->cacheWrites['BTCIRT']);
        $this->assertSame(106, Cache::get(self::PX . 'BTCIRT')['price']);
        $this->assertSame([['101', '0.75']], Cache::get(self::OB . 'BTCIRT')['bids']);

        // Nothing dirty any more -> further frames write nothing.
        $ws->clockMs = 20_000;
        $ws->frame();
        $this->assertSame(2, $ws->cacheWrites['BTCIRT']);
    }

    public function test_symbols_are_throttled_independently(): void
    {
        $ws = $this->service(1000);
        $ws->clockMs = 10_000;
        $ws->publish('BTCIRT', $this->pub(100, 110, 105));
        $ws->clockMs = 10_100;
        $ws->publish('ETHIRT', $this->pub(50, 52, 51));
        $ws->clockMs = 10_200;
        $ws->publish('BTCIRT', $this->pub(101, 111, 106));

        $this->assertSame(1, $ws->cacheWrites['BTCIRT']);
        $this->assertSame(1, $ws->cacheWrites['ETHIRT']);
        $this->assertSame(51, Cache::get(self::PX . 'ETHIRT')['price']);

        // At 11_050 BTC (last write 10_000) is due; ETH (last write 10_100) is not.
        $ws->clockMs = 11_050;
        $ws->publish('ETHIRT', $this->pub(50, 53, 52)); // 950ms since ETH write -> throttled
        $this->assertSame(1, $ws->cacheWrites['ETHIRT']);
        $this->assertSame(51, Cache::get(self::PX . 'ETHIRT')['price']);

        $ws->frame(); // flushes BTC only
        $this->assertSame(2, $ws->cacheWrites['BTCIRT']);
        $this->assertSame(1, $ws->cacheWrites['ETHIRT']);
        $this->assertSame(106, Cache::get(self::PX . 'BTCIRT')['price']);

        $ws->clockMs = 11_100;
        $ws->frame(); // now ETH is due
        $this->assertSame(2, $ws->cacheWrites['BTCIRT']);
        $this->assertSame(2, $ws->cacheWrites['ETHIRT']);
        $this->assertSame(52, Cache::get(self::PX . 'ETHIRT')['price']);
    }

    public function test_price_ttl_is_unchanged(): void
    {
        config(['trading.cache.price_ttl' => 7]);
        $ws = $this->service();

        $this->assertSame(7, $ws->priceTtl());
    }

    /* ------------------------------ heartbeat --------------------------- */

    public function test_frame_updates_last_frame_at_respecting_ten_second_guard(): void
    {
        $ws = $this->service();
        $ws->clockS = 1000;
        $ws->frame();
        $this->assertSame(1000, Cache::get(NobitexWebSocketService::HEARTBEAT_FRAME_KEY));

        $ws->clockS = 1009;
        $ws->frame();
        $this->assertSame(1000, Cache::get(NobitexWebSocketService::HEARTBEAT_FRAME_KEY));

        $ws->clockS = 1010;
        $ws->frame();
        $this->assertSame(1010, Cache::get(NobitexWebSocketService::HEARTBEAT_FRAME_KEY));
    }

    public function test_publication_updates_last_publication_at(): void
    {
        $ws = $this->service();
        $this->assertNull(Cache::get(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY));

        $ws->clockS = 2000;
        $ws->publish('BTCIRT', $this->pub(100, 110, 105));
        $this->assertSame(2000, Cache::get(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY));

        $ws->clockS = 2015;
        $ws->publish('BTCIRT', $this->pub(100, 110, 105));
        $this->assertSame(2015, Cache::get(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY));
    }

    public function test_non_orderbook_channel_does_not_touch_publication_heartbeat(): void
    {
        $ws = $this->service();
        $ws->publish('public:candle-BTCIRT', ['x' => 1], rawChannel: true);

        $this->assertNull(Cache::get(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY));
    }

    /* ------------------------------ commands ---------------------------- */

    public function test_broken_ws_nobitex_command_is_not_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayNotHasKey('ws:nobitex', $commands);
        $this->assertArrayHasKey('nobitex:ws-consumer', $commands);
    }
}

/** Exposes protected seams and replaces the clock; never opens a socket. */
class TestableWsService extends NobitexWebSocketService
{
    public int $clockMs = 1_000_000;
    public int $clockS = 1_000;
    /** @var array<string,int> */
    public array $cacheWrites = [];
    /** @var array<int,array{0:string,1:string,2:array}> */
    public array $logs = [];

    protected function nowMs(): int
    {
        return $this->clockMs;
    }

    protected function nowSeconds(): int
    {
        return $this->clockS;
    }

    protected function writeSnapshotToCache(string $symbol): void
    {
        $this->cacheWrites[$symbol] = ($this->cacheWrites[$symbol] ?? 0) + 1;
        parent::writeSnapshotToCache($symbol);
    }

    protected function out(string $msg, array $ctx = [], string $level = 'info'): void
    {
        $this->logs[] = [$level, $msg, $ctx];
    }

    /** @return array<string,string> */
    public function handshakeHeaders(): array
    {
        return $this->buildHandshakeHeaders();
    }

    /** @param array<int,string> $symbols */
    public function seed(array $symbols): void
    {
        $this->seedOrderbooks($symbols);
    }

    /** @param mixed $payload */
    public function publish(string $symbolOrChannel, $payload, bool $rawChannel = false): void
    {
        $channel = $rawChannel ? $symbolOrChannel : 'public:orderbook-' . $symbolOrChannel;
        $this->processPublicationPayload($channel, $payload);
    }

    public function frame(): void
    {
        $this->onFrameReceived();
    }

    public function priceTtl(): int
    {
        return $this->ttlPriceSeconds;
    }
}
