<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\DTOs\OrderStatusDto;
use App\Jobs\ProcessOrderEventJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\NobitexService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * W4 — ProcessOrderEventJob: the WS event is only a trigger. The job loads the
 * order + bot, skips what the poller would not touch, and otherwise runs
 * CheckTradesJob::processSingleOrder() — REST status is the truth.
 */
final class ProcessOrderEventJobTest extends TestCase
{
    use BuildsGridSchema;

    /** @var array<int,array{level:string,message:string,context:array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        // A Done event over a still-ACTIVE REST status schedules an API-lag
        // re-check; keep it queued (inspected in ProcessOrderEventRecheckTest).
        Queue::fake();
        $this->logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(bool $simulation = false, bool $active = true): BotConfig
    {
        return BotConfig::create([
            'name'         => 'event-job-test',
            'symbol'       => 'BTCIRT',
            'simulation'   => $simulation,
            'is_active'    => $active,
            'grid_spacing' => 1.00,
        ]);
    }

    private function makeOrder(BotConfig $bot, array $over = []): GridOrder
    {
        return GridOrder::create(array_merge([
            'bot_config_id'    => $bot->id,
            'price'            => 100_000_000,
            'amount'           => '0.001',
            'original_amount'  => '0.001',
            'type'             => 'buy',
            'status'           => 'placed',
            'client_order_id'  => 'seed-event-buy',
            'nobitex_order_id' => '900001',
        ], $over));
    }

    private function restSays(string $status, string $matched): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOrdersStatus')->once()->with(['900001'])->andReturn([
            OrderStatusDto::fromApi([
                'id' => '900001', 'status' => $status, 'type' => 'buy', 'execution' => 'limit',
                'amount' => '0.001', 'matchedAmount' => $matched, 'price' => 100_000_000,
                'createdAt' => 1_700_000_000_000,
            ]),
        ]);
        $svc->shouldReceive('placeOrder')->andReturn(['status' => 'ok', 'order' => ['id' => 900002]]);
        $this->app->instance(NobitexService::class, $svc);
    }

    private function exchangeMustNotBeCalled(): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldNotReceive('getOrdersStatus');
        $svc->shouldNotReceive('placeOrder');
        $this->app->instance(NobitexService::class, $svc);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->logs, fn ($l) => $l['message'] === $message));
    }

    public function test_is_unique_until_processing_by_grid_order_id(): void
    {
        $job = new ProcessOrderEventJob(42, 'Done', 1);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame('42', $job->uniqueId());
        $this->assertSame(3, $job->tries);
    }

    public function test_rest_status_wins_over_the_event_payload(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        // Event claims Done; REST says still Active with nothing matched.
        $this->restSays('Active', '0');

        $eventTimeMs = (int) floor(microtime(true) * 1000) - 250;
        (new ProcessOrderEventJob($order->id, 'Done', $eventTimeMs))->handle();

        $fresh = $order->fresh();
        $this->assertSame('placed', $fresh->status);
        $this->assertNull($fresh->filled_amount);
        $this->assertNull($fresh->paired_order_id);
        $this->assertSame(1, GridOrder::count());

        $acted = $this->logged('WS_EVENT_ACTED');
        $this->assertCount(1, $acted);
        $ctx = $acted[0]['context'];
        $this->assertSame($order->id, $ctx['grid_order_id']);
        $this->assertSame($bot->id, $ctx['bot_id']);
        $this->assertSame('Done', $ctx['event_status']);
        $this->assertSame('ACTIVE', $ctx['api_status_after']);
        $this->assertIsInt($ctx['latency_ms']);
        $this->assertGreaterThanOrEqual(250, $ctx['latency_ms']);
    }

    public function test_rest_filled_runs_the_poller_fill_and_pair_path(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot);
        $this->restSays('Done', '0.001');

        (new ProcessOrderEventJob($order->id, 'Done', null))->handle();

        $fresh = $order->fresh();
        $this->assertSame('filled', $fresh->status);
        $this->assertNotNull($fresh->paired_order_id);
        $this->assertSame(2, GridOrder::count());
        $this->assertSame('FILLED', $this->logged('WS_EVENT_ACTED')[0]['context']['api_status_after']);
        $this->assertNull($this->logged('WS_EVENT_ACTED')[0]['context']['latency_ms']);
    }

    public function test_skips_missing_order(): void
    {
        $this->exchangeMustNotBeCalled();

        (new ProcessOrderEventJob(999, 'Done', 1))->handle();

        $this->assertSame('order_missing', $this->logged('WS_EVENT_SKIPPED')[0]['context']['reason']);
        $this->assertSame([], $this->logged('WS_EVENT_ACTED'));
    }

    public function test_skips_simulation_bot(): void
    {
        $bot   = $this->makeBot(simulation: true);
        $order = $this->makeOrder($bot, ['nobitex_order_id' => 'SIM-1']);
        $this->exchangeMustNotBeCalled();

        (new ProcessOrderEventJob($order->id, 'Done', 1))->handle();

        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame('simulation', $this->logged('WS_EVENT_SKIPPED')[0]['context']['reason']);
    }

    public function test_skips_order_without_nobitex_order_id(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makeOrder($bot, ['nobitex_order_id' => null]);
        $this->exchangeMustNotBeCalled();

        (new ProcessOrderEventJob($order->id, 'Done', 1))->handle();

        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame('no_nobitex_order_id', $this->logged('WS_EVENT_SKIPPED')[0]['context']['reason']);
    }

    public function test_skips_inactive_bot_like_the_poller(): void
    {
        $bot   = $this->makeBot(active: false);
        $order = $this->makeOrder($bot);
        $this->exchangeMustNotBeCalled();

        (new ProcessOrderEventJob($order->id, 'Done', 1))->handle();

        $this->assertSame('bot_inactive', $this->logged('WS_EVENT_SKIPPED')[0]['context']['reason']);
    }

    public function test_skips_terminal_orders(): void
    {
        $bot = $this->makeBot();
        $cancelled = $this->makeOrder($bot, ['status' => 'cancelled']);
        $partner   = $this->makeOrder($bot, ['status' => 'placed', 'nobitex_order_id' => '900009', 'client_order_id' => 'p']);
        $paired    = $this->makeOrder($bot, ['status' => 'filled', 'nobitex_order_id' => '900010', 'client_order_id' => 'f', 'paired_order_id' => $partner->id]);
        $this->exchangeMustNotBeCalled();

        (new ProcessOrderEventJob($cancelled->id, 'Canceled', 1))->handle();
        (new ProcessOrderEventJob($paired->id, 'Done', 1))->handle();

        $reasons = array_map(fn ($l) => $l['context']['reason'], $this->logged('WS_EVENT_SKIPPED'));
        $this->assertSame(['already_terminal', 'already_terminal'], $reasons);
        $this->assertSame(3, GridOrder::count());
    }
}
