<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Jobs\ProcessOrderEventJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\NobitexService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * W4 API-lag re-check: a Done/Canceled WS event whose REST status is still
 * live is re-checked after 2s, 4s, 8s by re-dispatching the job (not
 * release()), then left to the minute poller. One fill → exactly one exit,
 * whatever order the re-check and the poller run in.
 */
final class ProcessOrderEventRecheckTest extends TestCase
{
    use BuildsGridSchema;

    private const NOBITEX_ID = '900001';

    /** @var array<int,array{level:string,message:string,context:array}> */
    private array $logs = [];

    /** REST status the fake exchange reports for NOBITEX_ID. */
    private string $restStatus = 'Active';
    private string $restMatched = '0';
    private int $statusCalls = 0;
    private int $placeCalls = 0;

    /** @var null|\Closure(): void runs once, inside the next REST status call */
    private ?\Closure $duringStatusCall = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        $this->logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });

        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->andReturnUsing(function (array $ids) {
            $this->statusCalls++;
            if ($hook = $this->duringStatusCall) {
                $this->duringStatusCall = null;
                $hook();
            }
            return in_array(self::NOBITEX_ID, $ids, true) ? [OrderStatusDto::fromApi([
                'id' => self::NOBITEX_ID, 'status' => $this->restStatus, 'type' => 'buy', 'execution' => 'limit',
                'amount' => '0.001', 'matchedAmount' => $this->restMatched, 'price' => 100_000_000,
                'createdAt' => 1_700_000_000_000,
            ])] : [];
        });
        $svc->shouldReceive('placeOrder')->andReturnUsing(function () {
            $this->placeCalls++;
            return ['status' => 'ok', 'order' => ['id' => 900100 + $this->placeCalls]];
        });
        $this->app->instance(NobitexService::class, $svc);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(bool $simulation = false, bool $active = true): BotConfig
    {
        return BotConfig::create([
            'name' => 'recheck-test', 'symbol' => 'BTCIRT',
            'simulation' => $simulation, 'is_active' => $active, 'grid_spacing' => 1.00,
        ]);
    }

    private function makeOrder(BotConfig $bot, array $over = []): GridOrder
    {
        return GridOrder::create(array_merge([
            'bot_config_id' => $bot->id, 'price' => 100_000_000,
            'amount' => '0.001', 'original_amount' => '0.001', 'type' => 'buy',
            'status' => 'placed', 'client_order_id' => 'seed-recheck-buy',
            'nobitex_order_id' => self::NOBITEX_ID,
        ], $over));
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->logs, fn ($l) => $l['message'] === $message));
    }

    private function restFilled(): void
    {
        $this->restStatus  = 'Done';
        $this->restMatched = '0.001';
    }

    /**
     * What the worker does for a ShouldBeUniqueUntilProcessing job: release its
     * unique lock, then handle(). Queue::fake() never releases it on its own.
     */
    private function runAsWorker(ProcessOrderEventJob $job): void
    {
        (new UniqueLock($this->app->make(CacheRepository::class)))->release($job);
        $job->handle();
    }

    /** Exit (continuation) rows = every grid order but the seed. */
    private function exitCount(GridOrder $seed): int
    {
        return GridOrder::where('id', '!=', $seed->id)->count();
    }

    public function test_done_over_active_rest_rechecks_after_2_4_8_then_gives_up(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->makeBot());

        $job = new ProcessOrderEventJob($order->id, 'Done', 1);
        foreach ([1 => 2, 2 => 4, 3 => 8] as $attempt => $delay) {
            $this->runAsWorker($job);

            $pushed = Queue::pushed(ProcessOrderEventJob::class)->last();
            $this->assertNotNull($pushed);
            $this->assertSame($attempt, $pushed->recheck);
            $this->assertSame($delay, $pushed->delay);
            $this->assertSame($order->id, $pushed->gridOrderId);
            $this->assertSame('Done', $pushed->eventStatus);
            $this->assertSame(1, $pushed->eventTimeMs);
            $this->assertCount($attempt, Queue::pushed(ProcessOrderEventJob::class));

            $log = $this->logged('WS_EVENT_API_LAG_RECHECK')[$attempt - 1]['context'];
            $this->assertSame(['attempt' => $attempt, 'delay' => $delay, 'api_status' => 'ACTIVE'],
                ['attempt' => $log['attempt'], 'delay' => $log['delay'], 'api_status' => $log['api_status']]);

            $job = $pushed;
        }

        // Third re-check still ACTIVE: give up, nothing more queued.
        $this->runAsWorker($job);
        $this->assertCount(3, Queue::pushed(ProcessOrderEventJob::class));
        $gaveUp = $this->logged('WS_EVENT_API_LAGGING_GAVE_UP');
        $this->assertCount(1, $gaveUp);
        $this->assertSame(3, $gaveUp[0]['context']['rechecks']);
        $this->assertSame('ACTIVE', $gaveUp[0]['context']['api_status']);
        $this->assertSame(4, $this->statusCalls);
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame(0, $this->exitCount($order));
    }

    public function test_recheck_that_sees_filled_creates_the_exit_and_stops(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->makeBot());

        (new ProcessOrderEventJob($order->id, 'Done', null))->handle();
        $recheck = Queue::pushed(ProcessOrderEventJob::class)->sole();
        $this->assertSame(2, $recheck->delay);

        $this->restFilled();
        $this->runAsWorker($recheck);

        $fresh = $order->fresh();
        $this->assertSame('filled', $fresh->status);
        $this->assertNotNull($fresh->paired_order_id);
        $this->assertSame(1, $this->exitCount($order));
        $this->assertSame(1, $this->placeCalls);
        $this->assertCount(1, Queue::pushed(ProcessOrderEventJob::class)); // no further re-check
        $this->assertCount(1, $this->logged('WS_EVENT_API_LAG_RECHECK'));
        $this->assertSame([], $this->logged('WS_EVENT_API_LAGGING_GAVE_UP'));
        $this->assertSame(1, $this->logged('WS_EVENT_ACTED')[1]['context']['recheck']);
    }

    public function test_canceled_event_over_active_rest_is_rechecked_too(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->makeBot());

        (new ProcessOrderEventJob($order->id, 'Canceled', null))->handle();

        $this->assertSame(1, Queue::pushed(ProcessOrderEventJob::class)->sole()->recheck);
    }

    public function test_non_terminal_events_are_never_rechecked(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->makeBot());

        foreach (['Active', 'Inactive', null] as $eventStatus) {
            (new ProcessOrderEventJob($order->id, $eventStatus, null))->handle();
        }
        Queue::assertNothingPushed();
        $this->assertSame([], $this->logged('WS_EVENT_API_LAG_RECHECK'));
        $this->assertCount(3, $this->logged('WS_EVENT_ACTED'));
    }

    public function test_simulation_inactive_and_locally_terminal_orders_are_never_rechecked(): void
    {
        Queue::fake();
        $sim      = $this->makeOrder($this->makeBot(simulation: true), ['client_order_id' => 's']);
        $inactive = $this->makeOrder($this->makeBot(active: false), ['client_order_id' => 'i']);
        $cancelled = $this->makeOrder($this->makeBot(), ['client_order_id' => 'c', 'status' => 'cancelled']);

        foreach ([$sim, $inactive, $cancelled] as $o) {
            (new ProcessOrderEventJob($o->id, 'Done', null, 1))->handle();
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->statusCalls);
        $this->assertSame(['simulation', 'bot_inactive', 'already_terminal'],
            array_map(fn ($l) => $l['context']['reason'], $this->logged('WS_EVENT_SKIPPED')));
    }

    public function test_busy_status_lock_is_not_treated_as_api_lag(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->makeBot());
        $lock = \Illuminate\Support\Facades\Cache::lock(CheckTradesJob::orderStatusLockKey($order->id), 30);
        $this->assertTrue($lock->get());

        (new ProcessOrderEventJob($order->id, 'Done', null))->handle();

        $lock->release();
        Queue::assertNothingPushed(); // the poller holds it: it is handling the order
    }

    public function test_empty_delay_config_disables_rechecks(): void
    {
        Queue::fake();
        config(['trading.websocket.api_lag_recheck_delays' => []]);
        $order = $this->makeOrder($this->makeBot());

        (new ProcessOrderEventJob($order->id, 'Done', null))->handle();

        Queue::assertNothingPushed();
        $this->assertSame([], $this->logged('WS_EVENT_API_LAGGING_GAVE_UP'));
    }

    // ── races with the minute poller: one fill → exactly one exit ────────

    public function test_recheck_racing_the_poller_between_its_fill_and_pair_steps_creates_one_exit(): void
    {
        Queue::fake();
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $this->restFilled();

        // The poller applies the fill, releases the order-status lock and loads
        // its "filled, unpaired" batch — then the WS re-check runs before the
        // poller pairs from that (now stale) batch.
        $fired = false;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$fired, $order) {
            if (!$fired && str_starts_with($m->message, 'CheckTradesJob: Found 1 filled orders without pair')) {
                $fired = true;
                (new ProcessOrderEventJob($order->id, 'Done', null, 1))->handle();
            }
        });

        (new CheckTradesJob())->handle();

        $this->assertTrue($fired);
        $this->assertSame(1, $this->exitCount($order));
        $this->assertSame(1, $this->placeCalls);
        $fresh = $order->fresh();
        $this->assertSame('filled', $fresh->status);
        $this->assertSame($fresh->paired_order_id, GridOrder::where('id', '!=', $order->id)->value('id'));
        Queue::assertNothingPushed();
    }

    public function test_poller_racing_the_recheck_inside_its_status_call_creates_one_exit(): void
    {
        Queue::fake();
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $this->restFilled();

        // While the re-check holds the order-status lock (mid REST call), the
        // whole minute poll runs: it must skip the order, not pair it.
        $this->duringStatusCall = fn () => (new CheckTradesJob())->handle();

        (new ProcessOrderEventJob($order->id, 'Done', null, 1))->handle();

        $this->assertSame(1, $this->exitCount($order));
        $this->assertSame(1, $this->placeCalls);
        $this->assertNotEmpty(array_filter($this->logged('ORDER_STATUS_LOCK_BUSY'), fn ($l) => $l['context']['path'] === 'poller'));

        // And the poller's next minute changes nothing.
        (new CheckTradesJob())->handle();
        $this->assertSame(1, $this->exitCount($order));
        $this->assertSame(1, $this->placeCalls);
    }

    public function test_recheck_racing_inside_the_pollers_status_call_creates_one_exit(): void
    {
        Queue::fake();
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $this->restFilled();

        // The poller is mid REST call (it has not taken the per-order lock yet
        // — the batch call precedes it) when the re-check runs to completion.
        $this->duringStatusCall = fn () => (new ProcessOrderEventJob($order->id, 'Done', null, 1))->handle();

        (new CheckTradesJob())->handle();

        $this->assertSame(1, $this->exitCount($order));
        $this->assertSame(1, $this->placeCalls);
        $this->assertSame('filled', $order->fresh()->status);
    }

    // ── the database queue honours the delay and the unique lock ─────────

    public function test_database_queue_delays_the_recheck_and_keeps_one_job_per_order(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.connection' => null,
            'queue.failed.driver' => 'null',
        ]);
        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        $this->freezeSecond();

        $order = $this->makeOrder($this->makeBot());
        $work  = fn () => Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

        // The WS event (as the recorder dispatches it), then a duplicate event
        // that ShouldBeUniqueUntilProcessing absorbs.
        ProcessOrderEventJob::dispatch($order->id, 'Done', null);
        ProcessOrderEventJob::dispatch($order->id, 'Done', null);
        $this->assertSame(1, DB::table('jobs')->count());

        // Worker runs it: unique lock is released before handle(), so the
        // re-dispatch takes a fresh lock and lands 2s in the future.
        $work();
        $row = DB::table('jobs')->sole();
        $this->assertSame(now()->getTimestamp() + 2, (int) $row->available_at);
        $this->assertSame(1, unserialize(json_decode($row->payload, true)['data']['command'])->recheck);
        $this->assertSame(1, $this->statusCalls);

        // Another event while the re-check waits: absorbed, still one row.
        ProcessOrderEventJob::dispatch($order->id, 'Done', null);
        $this->assertSame(1, DB::table('jobs')->count());

        // Not due yet: the worker leaves it alone.
        $this->travel(1)->seconds();
        $work();
        $this->assertSame(1, $this->statusCalls);

        // Due: runs, REST now FILLED → exit, no further re-check.
        $this->travel(1)->seconds();
        $this->restFilled();
        $work();
        $this->assertSame(2, $this->statusCalls);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('filled', $order->fresh()->status);
        $this->assertSame(1, $this->exitCount($order));
    }
}
