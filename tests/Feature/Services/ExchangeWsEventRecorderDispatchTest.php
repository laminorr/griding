<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\ProcessOrderEventJob;
use App\Models\ExchangeWsEvent;
use App\Services\ExchangeWsEventRecorder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * W4 — ExchangeWsEventRecorder dispatching ProcessOrderEventJob, behind
 * config('trading.websocket.act_on_private_events') (default false).
 */
final class ExchangeWsEventRecorderDispatchTest extends TestCase
{
    use BuildsGridSchema;

    private const MIGRATION = 'database/migrations/2026_10_01_000001_create_exchange_ws_events_table.php';

    /** @var array<int,array{level:string,message:string,context:array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        Schema::dropIfExists('exchange_ws_events');
        (require base_path(self::MIGRATION))->up();

        $this->logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('exchange_ws_events');
        $this->dropGridSchema();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private static function orderEvent(array $over = []): array
    {
        return array_merge([
            'orderId' => 4567890123,
            'clientOrderId' => 'grid-abc-1',
            'eventTime' => 1759300000123,
            'side' => 'Buy',
            'status' => 'Done',
            'price' => '9800000000',
            'amount' => '0.0001',
            'filledAmount' => '0.0001',
            'orderType' => 'Limit',
            'marketType' => 'Spot',
        ], $over);
    }

    private function seedGridOrder(string $nobitexOrderId = '4567890123'): int
    {
        $botId = DB::table('bot_configs')->insertGetId([
            'name' => 'b', 'symbol' => 'BTCIRT', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        return DB::table('grid_orders')->insertGetId([
            'bot_config_id' => $botId, 'price' => '9800000000', 'amount' => '0.0001', 'type' => 'buy',
            'status' => 'placed', 'nobitex_order_id' => $nobitexOrderId, 'role' => 'cycle_exit',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** What a worker does when it starts the job (ShouldBeUniqueUntilProcessing). */
    private function releaseUniqueLock(int $gridId): void
    {
        Cache::lock(UniqueLock::getKey(new ProcessOrderEventJob($gridId)))->forceRelease();
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->logs, fn ($l) => $l['message'] === $message));
    }

    public function test_flag_defaults_to_false(): void
    {
        $this->assertFalse(config('trading.websocket.act_on_private_events'));
    }

    public function test_flag_off_records_but_dispatches_and_logs_nothing_extra(): void
    {
        Queue::fake();
        config(['trading.websocket.act_on_private_events' => false]);
        $this->seedGridOrder();

        $this->assertSame(1, (new ExchangeWsEventRecorder())->record('orders', self::orderEvent()));

        Queue::assertNothingPushed();
        $this->assertSame(1, ExchangeWsEvent::query()->count());
        $this->assertSame(['WS_PRIVATE_EVENT'], array_map(fn ($l) => $l['message'], $this->logs));
    }

    public function test_flag_on_dispatches_for_actionable_matched_spot_events_only(): void
    {
        Queue::fake();
        config(['trading.websocket.act_on_private_events' => true]);
        $gridId = $this->seedGridOrder();
        $r = new ExchangeWsEventRecorder();

        // Not actionable (each is still recorded).
        $r->record('orders', self::orderEvent(['status' => 'New', 'filledAmount' => '0', 'eventTime' => 1]));
        $r->record('orders', self::orderEvent(['status' => 'Active', 'filledAmount' => '0', 'eventTime' => 2]));
        $r->record('orders', self::orderEvent(['status' => 'Active', 'filledAmount' => '0.0000', 'eventTime' => 3]));
        $r->record('orders', self::orderEvent(['status' => 'Active', 'filledAmount' => null, 'eventTime' => 31]));
        $r->record('orders', self::orderEvent(['status' => 'Active', 'filledAmount' => 'abc', 'eventTime' => 32]));
        $r->record('orders', self::orderEvent(['status' => 'Done', 'marketType' => 'Margin', 'eventTime' => 4]));
        $r->record('orders', self::orderEvent(['orderId' => 111, 'eventTime' => 5]));          // unmatched
        $r->record('orders', json_encode(['status' => 'Failed', 'code' => 'X', 'clientOrderId' => 'grid-abc-1']));
        $r->record('trades', ['id' => 1, 'orderId' => 4567890123, 'time' => 6, 'amount' => '0.0001']);
        $this->assertSame(9, ExchangeWsEvent::query()->count());
        Queue::assertNothingPushed();

        // Actionable. Uniqueness is per grid order until processing starts, so
        // assert each status on a freshly faked queue with the unique lock
        // released, as if the previous job had started.
        foreach ([
            ['Active', '0.00001'],
            ['Active', 0.00002],
            ['Done', '0.0001'],
            ['Canceled', '0'],
            ['Inactive', '0'],
        ] as $i => [$status, $filled]) {
            Queue::fake();
            $this->releaseUniqueLock($gridId);

            $r->record('orders', self::orderEvent(['status' => $status, 'filledAmount' => $filled, 'eventTime' => 100 + $i]));

            Queue::assertPushed(ProcessOrderEventJob::class, 1);
            Queue::assertPushed(ProcessOrderEventJob::class, fn (ProcessOrderEventJob $j) => $j->gridOrderId === $gridId
                && $j->eventStatus === $status
                && $j->eventTimeMs === 100 + $i);
        }

        $this->assertCount(5, $this->logged('WS_EVENT_DISPATCH_REQUESTED'));
    }

    public function test_replayed_event_does_not_dispatch_twice(): void
    {
        Queue::fake();
        config(['trading.websocket.act_on_private_events' => true]);
        $gridId = $this->seedGridOrder();
        $r = new ExchangeWsEventRecorder();

        $this->assertSame(1, $r->record('orders', self::orderEvent()));
        $this->releaseUniqueLock($gridId); // rule out the unique lock: insertOrIgnore alone must stop it
        $this->assertSame(0, $r->record('orders', json_encode(self::orderEvent())));

        Queue::assertPushed(ProcessOrderEventJob::class, 1);
    }

    public function test_burst_of_events_for_one_order_queues_one_job(): void
    {
        Queue::fake();
        config(['trading.websocket.act_on_private_events' => true]);
        $this->seedGridOrder();
        $r = new ExchangeWsEventRecorder();

        $r->record('orders', self::orderEvent(['status' => 'Active', 'filledAmount' => '0.00002', 'eventTime' => 1]));
        $r->record('orders', self::orderEvent(['status' => 'Active', 'filledAmount' => '0.00005', 'eventTime' => 2]));
        $r->record('orders', self::orderEvent(['status' => 'Done', 'eventTime' => 3]));

        $this->assertSame(3, ExchangeWsEvent::query()->count());
        Queue::assertPushed(ProcessOrderEventJob::class, 1);
    }

    public function test_dispatch_failure_is_swallowed_and_the_event_is_still_recorded(): void
    {
        config([
            'trading.websocket.act_on_private_events' => true,
            'queue.default' => 'no-such-connection',
        ]);
        $this->seedGridOrder();

        $inserted = (new ExchangeWsEventRecorder())->record('orders', self::orderEvent());

        $this->assertSame(1, $inserted);
        $this->assertSame(1, ExchangeWsEvent::query()->count());
        $failed = $this->logged('WS_EVENT_DISPATCH_FAILED');
        $this->assertCount(1, $failed);
        $this->assertSame('warning', $failed[0]['level']);
        $this->assertSame([], $this->logged('WS_EVENT_DISPATCH_REQUESTED'));
    }
}
