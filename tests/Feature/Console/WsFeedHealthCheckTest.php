<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NobitexWebSocketService;
use App\Support\WsFeedHealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * WsFeedHealthCheck turns a silently dead nobitex:ws-consumer into a log line,
 * based on the heartbeat keys the consumer writes. Log-only; never throws.
 */
final class WsFeedHealthCheckTest extends TestCase
{
    private const NOW = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'trading.websocket.health.dead_after_seconds' => 180,
            'trading.websocket.health.silent_after_seconds' => 600,
        ]);
    }

    private function heartbeat(?int $frameAge, ?int $pubAge): void
    {
        if ($frameAge !== null) {
            Cache::put(NobitexWebSocketService::HEARTBEAT_FRAME_KEY, self::NOW - $frameAge, 86400);
        }
        if ($pubAge !== null) {
            Cache::put(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY, self::NOW - $pubAge, 86400);
        }
    }

    public function test_fresh_feed_logs_nothing(): void
    {
        $this->heartbeat(5, 5);

        Log::shouldReceive('channel')->never();

        $this->assertSame(WsFeedHealthCheck::OK, (new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_stale_frame_logs_critical_ws_feed_dead(): void
    {
        $this->heartbeat(181, 181);

        Log::shouldReceive('channel')->with('queue')->andReturnSelf();
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('critical')->once()->with('WS_FEED_DEAD', Mockery::on(
            fn(array $ctx) => $ctx['age_seconds'] === 181 && $ctx['threshold_seconds'] === 180
        ));

        $this->assertSame(WsFeedHealthCheck::DEAD, (new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_fresh_frame_with_stale_publication_logs_warning_ws_feed_silent(): void
    {
        $this->heartbeat(10, 601);

        Log::shouldReceive('channel')->with('queue')->andReturnSelf();
        Log::shouldReceive('critical')->never();
        Log::shouldReceive('warning')->once()->with('WS_FEED_SILENT', Mockery::on(
            fn(array $ctx) => $ctx['age_seconds'] === 601 && $ctx['threshold_seconds'] === 600
        ));

        $this->assertSame(WsFeedHealthCheck::SILENT, (new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_missing_keys_log_warning_never_seen_not_critical(): void
    {
        Log::shouldReceive('channel')->with('queue')->andReturnSelf();
        Log::shouldReceive('critical')->never();
        Log::shouldReceive('warning')->once()->with('WS_FEED_NEVER_SEEN', Mockery::any());

        $this->assertSame(WsFeedHealthCheck::NEVER_SEEN, (new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_never_throws_when_cache_fails(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('db down'));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('WS_FEED_CHECK_FAILED', Mockery::any());

        $this->assertNull((new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_never_throws_even_when_logging_fails(): void
    {
        Log::shouldReceive('channel')->andThrow(new \RuntimeException('log down'));

        $this->assertNull((new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_default_thresholds(): void
    {
        // Shipped defaults from config/trading.php (no env override in tests).
        $this->refreshApplication();
        $this->assertSame(180, WsFeedHealthCheck::deadAfterSeconds());
        $this->assertSame(600, WsFeedHealthCheck::silentAfterSeconds());
    }
}
