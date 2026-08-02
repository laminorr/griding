<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\QueueDepthHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * QueueDepthHealthCheck is the early-warning guard added after the 300k-row
 * `jobs` backlog incident: a worker that drains slower than schedule:run
 * enqueues lets the database queue grow unbounded, and this check fires a
 * CRITICAL log once the backlog crosses a threshold so it is caught early.
 *
 * The suite's default QUEUE_CONNECTION is `sync`, so each test that exercises
 * the counting path explicitly points the queue at the `database` driver
 * (that is the only driver whose backlog lives in a countable table).
 */
final class QueueDepthHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
        config(['queue.connections.database.driver' => 'database']);
        config(['queue.connections.database.table' => 'jobs']);
        config(['queue.connections.database.connection' => null]);
    }

    /** Insert $count placeholder rows into the `jobs` table. */
    private function seedJobs(int $count): void
    {
        $now = time();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'queue'        => 'default',
                'payload'      => '{}',
                'attempts'     => 0,
                'reserved_at'  => null,
                'available_at' => $now,
                'created_at'   => $now,
            ];
        }
        // Chunk to stay well under SQLite's bound-parameter limit.
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('jobs')->insert($chunk);
        }
    }

    public function test_logs_critical_when_backlog_exceeds_threshold(): void
    {
        $this->useDatabaseQueue();
        config(['trading.scheduler.queue_depth_alert_threshold' => 5]);

        $this->seedJobs(6); // 6 > 5

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('critical')
            ->once()
            ->with('QUEUE_DEPTH_CRITICAL', Mockery::on(function (array $ctx): bool {
                return ($ctx['depth'] ?? null) === 6
                    && ($ctx['threshold'] ?? null) === 5;
            }));

        $depth = (new QueueDepthHealthCheck())->check();

        $this->assertSame(6, $depth);
    }

    public function test_does_not_alert_when_backlog_is_within_threshold(): void
    {
        $this->useDatabaseQueue();
        config(['trading.scheduler.queue_depth_alert_threshold' => 5]);

        $this->seedJobs(3); // 3 <= 5

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->never();

        $depth = (new QueueDepthHealthCheck())->check();

        $this->assertSame(3, $depth);
    }

    public function test_is_a_noop_for_a_non_database_queue_driver(): void
    {
        // The default `sync` driver keeps no backlog table to count.
        config(['queue.default' => 'sync']);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('critical')->never();

        $this->assertNull((new QueueDepthHealthCheck())->check());
    }

    public function test_default_threshold_is_five_hundred(): void
    {
        // The default alert threshold ships as 500 (config/trading.php). No
        // override is applied here — the test env sets no TRADING_QUEUE_DEPTH_ALERT,
        // so this asserts the shipped default.
        $this->assertSame(500, QueueDepthHealthCheck::threshold());
    }
}
