<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * W4 — CheckTradesJob::processSingleOrder(), the single-order entry point the
 * WS event path uses. It must produce exactly the poller's effects (it runs
 * the poller's own processOrderStatus()/createPairOrder()), and the shared
 * "order-status:{id}" lock must keep the two paths from double-processing.
 *
 * NobitexService is mocked: getOrdersStatus() returns real OrderStatusDto
 * rows built with fromApi(); placeOrder() returns a fake exchange id.
 */
final class CheckTradesSingleOrderTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL    = 'BTCIRT';
    private const BUY_PRICE = 100_000_000;

    private int $placeCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->placeCalls = 0;
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(bool $simulation = false): BotConfig
    {
        return BotConfig::create([
            'name'         => 'single-order-test',
            'symbol'       => self::SYMBOL,
            'simulation'   => $simulation,
            'is_active'    => true,
            'grid_spacing' => 1.00,
        ]);
    }

    private function makeOrder(BotConfig $bot, array $over = []): GridOrder
    {
        return GridOrder::create(array_merge([
            'bot_config_id'    => $bot->id,
            'price'            => self::BUY_PRICE,
            'amount'           => '0.001',
            'original_amount'  => '0.001',
            'type'             => 'buy',
            'status'           => 'placed',
            'client_order_id'  => 'seed-single-buy',
            'nobitex_order_id' => '700001',
            'paired_order_id'  => null,
        ], $over));
    }

    private static function dto(string $id, string $status, string $matched, int $price = self::BUY_PRICE, string $type = 'buy'): OrderStatusDto
    {
        return OrderStatusDto::fromApi([
            'id'            => $id,
            'status'        => $status,
            'type'          => $type,
            'execution'     => 'limit',
            'amount'        => '0.001',
            'matchedAmount' => $matched,
            'price'         => $price,
            'createdAt'     => 1_700_000_000_000,
        ]);
    }

    /**
     * @param array<string,OrderStatusDto> $statusById nobitex id => DTO
     */
    private function mockNobitex(array &$statusById): MockInterface
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(function (array $ids) use (&$statusById) {
            return array_values(array_filter(array_map(fn ($id) => $statusById[$id] ?? null, $ids)));
        });
        $svc->shouldReceive('placeOrder')->andReturnUsing(function () {
            $this->placeCalls++;
            return ['status' => 'ok', 'order' => ['id' => 800000 + $this->placeCalls]];
        });
        $this->app->instance(NobitexService::class, $svc);

        return $svc;
    }

    private function single(GridOrder $order, BotConfig $bot): array
    {
        return (new CheckTradesJob())->processSingleOrder($order, $bot);
    }

    public function test_filled_marks_order_and_creates_one_pair_like_the_poller(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $status = ['700001' => self::dto('700001', 'Done', '0.001')];
        $this->mockNobitex($status);

        $result = $this->single($order, $bot);

        $this->assertSame('processed', $result['outcome']);
        $this->assertSame('FILLED', $result['api_status']);
        $this->assertTrue($result['pair_attempted']);

        $fresh = $order->fresh();
        $this->assertSame('filled', $fresh->status);
        $this->assertSame('0.00100000', (string) $fresh->filled_amount);
        $this->assertNotNull($fresh->paired_order_id);

        $pair = GridOrder::find($fresh->paired_order_id);
        $this->assertSame('sell', $pair->type);
        $this->assertSame('placed', $pair->status);
        $this->assertSame('cycle_exit', $pair->role);
        $this->assertSame($order->id, (int) $pair->paired_order_id);
        $this->assertSame(1, $this->placeCalls);

        // A second run (replayed event) changes nothing.
        $again = $this->single($fresh, $bot);
        $this->assertSame('not_polled', $again['outcome']);
        $this->assertFalse($again['pair_attempted']);
        $this->assertSame(2, GridOrder::count());
        $this->assertSame(1, $this->placeCalls);
    }

    public function test_completed_trade_is_booked_once_when_the_partner_fills(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $status = ['700001' => self::dto('700001', 'Done', '0.001')];
        $this->mockNobitex($status);

        $this->single($order, $bot);
        $pair = GridOrder::find($order->fresh()->paired_order_id);

        // The continuation sell fills.
        $status[(string) $pair->nobitex_order_id] = self::dto((string) $pair->nobitex_order_id, 'Done', '0.001', 101_000_000, 'sell');
        $this->single($pair, $bot);

        $this->assertSame('filled', $pair->fresh()->status);
        $this->assertSame(1, CompletedTrade::count());
        $trade = CompletedTrade::first();
        $this->assertSame($order->id, (int) $trade->buy_order_id);
        $this->assertSame($pair->id, (int) $trade->sell_order_id);

        // The poller then runs over the same bot: no second trade, no new order.
        $countBefore = GridOrder::count();
        (new CheckTradesJob())->handle();
        $this->assertSame(1, CompletedTrade::count());
        $this->assertSame($countBefore, GridOrder::count());
    }

    public function test_partial_active_records_partial_and_creates_no_pair(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $status = ['700001' => self::dto('700001', 'Active', '0.0004')];
        $this->mockNobitex($status);

        $result = $this->single($order, $bot);

        $this->assertSame('ACTIVE', $result['api_status']);
        $this->assertFalse($result['pair_attempted']);
        $fresh = $order->fresh();
        $this->assertSame('partially_filled', $fresh->status);
        $this->assertSame('0.00040000', (string) $fresh->filled_amount);
        $this->assertSame('0.00060000', (string) $fresh->remaining_amount);
        $this->assertNull($fresh->paired_order_id);
        $this->assertSame(1, GridOrder::count());
        $this->assertSame(0, $this->placeCalls);
    }

    public function test_canceled_with_partial_records_partial_is_terminal_and_unpaired(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $status = ['700001' => self::dto('700001', 'Canceled', '0.0004')];
        $this->mockNobitex($status);

        $result = $this->single($order, $bot);

        $this->assertSame('CANCELED', $result['api_status']);
        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('0.00040000', (string) $fresh->filled_amount);
        $this->assertNull($fresh->paired_order_id);
        $this->assertSame(1, GridOrder::count());
        $this->assertSame(0, $this->placeCalls);
    }

    public function test_unchanged_status_writes_nothing(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $before = (array) DB::table('grid_orders')->where('id', $order->id)->first();
        $status = ['700001' => self::dto('700001', 'Active', '0')];
        $this->mockNobitex($status);

        $result = $this->single($order, $bot);

        $this->assertSame('processed', $result['outcome']);
        $this->assertSame('ACTIVE', $result['api_status']);
        $this->assertSame($before, (array) DB::table('grid_orders')->where('id', $order->id)->first());
        $this->assertSame(1, GridOrder::count());
    }

    public function test_held_status_lock_makes_the_entry_point_skip_without_writes(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $before = (array) DB::table('grid_orders')->where('id', $order->id)->first();
        $status = ['700001' => self::dto('700001', 'Done', '0.001')];
        $svc = $this->mockNobitex($status);

        $held = Cache::lock(CheckTradesJob::orderStatusLockKey($order->id), 30);
        $this->assertTrue($held->get());

        try {
            $result = $this->single($order, $bot);
        } finally {
            $held->release();
        }

        $this->assertSame('lock_busy', $result['outcome']);
        $this->assertNull($result['api_status']);
        $this->assertSame($before, (array) DB::table('grid_orders')->where('id', $order->id)->first());
        $this->assertSame(1, GridOrder::count());
        $svc->shouldNotHaveReceived('getOrdersStatus');
        $this->assertSame(0, $this->placeCalls);
    }

    public function test_held_status_lock_makes_the_poller_skip_that_order(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $status = ['700001' => self::dto('700001', 'Done', '0.001')];
        $this->mockNobitex($status);

        $held = Cache::lock(CheckTradesJob::orderStatusLockKey($order->id), 30);
        $this->assertTrue($held->get());

        try {
            (new CheckTradesJob())->handle();
        } finally {
            $held->release();
        }

        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame(1, GridOrder::count());
        $this->assertSame(0, $this->placeCalls);
    }

    public function test_poller_then_event_path_never_double_pairs_or_double_books(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $status = ['700001' => self::dto('700001', 'Done', '0.001')];
        $this->mockNobitex($status);

        (new CheckTradesJob())->handle();
        $this->assertSame('filled', $order->fresh()->status);
        $this->assertSame(2, GridOrder::count());

        $this->single($order, $bot);
        $this->assertSame(2, GridOrder::count());
        $this->assertSame(1, $this->placeCalls);

        // Sell leg fills: event path first, then poller.
        $pair = GridOrder::find($order->fresh()->paired_order_id);
        $status[(string) $pair->nobitex_order_id] = self::dto((string) $pair->nobitex_order_id, 'Done', '0.001', 101_000_000, 'sell');

        $this->single($pair, $bot);
        $countAfterEvent = GridOrder::count();
        (new CheckTradesJob())->handle();

        $this->assertSame(1, CompletedTrade::count());
        $this->assertSame($countAfterEvent, GridOrder::count());
        // Only the buy's continuation was ever placed: the sell IS that
        // continuation (paired_order_id → buy), so it spawns nothing more.
        $this->assertSame(1, $this->placeCalls);
    }

    public function test_poller_does_not_apply_a_stale_status_to_an_order_the_event_path_advanced(): void
    {
        // Poller loaded the order as 'placed' (and a stale partial DTO), then
        // the event path filled it before the poller took the per-order lock.
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $stale = $order->replicate();
        $stale->id = $order->id;
        $stale->exists = true;

        $status = ['700001' => self::dto('700001', 'Done', '0.001')];
        $this->mockNobitex($status);
        $this->single($order, $bot);
        $this->assertSame('filled', $order->fresh()->status);

        $job = new CheckTradesJob();
        $ref = new \ReflectionMethod($job, 'processOrderStatusLocked');
        $ref->setAccessible(true);
        $ref->invoke($job, $stale, self::dto('700001', 'Active', '0.0004'), $bot);

        $fresh = $order->fresh();
        $this->assertSame('filled', $fresh->status);
        $this->assertSame('0.00100000', (string) $fresh->filled_amount);
    }

    public function test_simulation_bot_never_reaches_the_exchange(): void
    {
        $bot   = $this->makeBot(simulation: true);
        $order = $this->makeOrder($bot, ['nobitex_order_id' => 'SIM-1']);
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldNotReceive('getOrdersStatus');
        $svc->shouldNotReceive('placeOrder');
        $this->app->instance(NobitexService::class, $svc);

        $result = $this->single($order, $bot);

        $this->assertSame('simulation', $result['outcome']);
        $this->assertSame('placed', $order->fresh()->status);
    }
}
