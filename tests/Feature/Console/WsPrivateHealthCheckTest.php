<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NobitexPrivateWsService;
use App\Services\NobitexWebSocketService;
use App\Support\WsFeedHealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * W3 — WsFeedHealthCheck also watches nobitex:ws-private's heartbeat, but only
 * when that key exists. The public-feed result returned by check() is unchanged.
 */
final class WsPrivateHealthCheckTest extends TestCase
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

    private function publicFresh(): void
    {
        Cache::put(NobitexWebSocketService::HEARTBEAT_FRAME_KEY, self::NOW - 5, 86400);
        Cache::put(NobitexWebSocketService::HEARTBEAT_PUBLICATION_KEY, self::NOW - 5, 86400);
    }

    private function privateAge(int $age): void
    {
        Cache::put(NobitexPrivateWsService::HEARTBEAT_FRAME_KEY, self::NOW - $age, 86400);
    }

    public function test_private_key_missing_logs_nothing(): void
    {
        $this->publicFresh();
        Log::shouldReceive('channel')->never();

        $this->assertSame(WsFeedHealthCheck::OK, (new WsFeedHealthCheck())->check(self::NOW));
        $this->assertNull((new WsFeedHealthCheck())->checkPrivate(self::NOW));
    }

    public function test_private_fresh_logs_nothing(): void
    {
        $this->publicFresh();
        $this->privateAge(180); // at the threshold = still alive
        Log::shouldReceive('channel')->never();

        $this->assertSame(WsFeedHealthCheck::OK, (new WsFeedHealthCheck())->check(self::NOW));
        $this->assertSame(WsFeedHealthCheck::OK, (new WsFeedHealthCheck())->checkPrivate(self::NOW));
    }

    public function test_private_stale_logs_critical_ws_private_dead_and_public_status_is_unchanged(): void
    {
        $this->publicFresh();
        $this->privateAge(181);

        Log::shouldReceive('channel')->with('queue')->andReturnSelf();
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('critical')->once()->with('WS_PRIVATE_DEAD', Mockery::on(
            fn (array $ctx) => $ctx['age_seconds'] === 181 && $ctx['threshold_seconds'] === 180
        ));

        $this->assertSame(WsFeedHealthCheck::OK, (new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_private_stale_with_public_dead_logs_both(): void
    {
        Cache::put(NobitexWebSocketService::HEARTBEAT_FRAME_KEY, self::NOW - 500, 86400);
        $this->privateAge(500);

        Log::shouldReceive('channel')->with('queue')->andReturnSelf();
        Log::shouldReceive('critical')->once()->with('WS_FEED_DEAD', Mockery::any());
        Log::shouldReceive('critical')->once()->with('WS_PRIVATE_DEAD', Mockery::any());

        $this->assertSame(WsFeedHealthCheck::DEAD, (new WsFeedHealthCheck())->check(self::NOW));
    }

    public function test_private_check_never_throws(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('db down'));
        Log::shouldReceive('channel')->andThrow(new \RuntimeException('log down'));

        $this->assertNull((new WsFeedHealthCheck())->checkPrivate(self::NOW));
    }
}
