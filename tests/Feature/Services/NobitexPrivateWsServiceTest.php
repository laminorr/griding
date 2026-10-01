<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\WsFrameClient;
use App\Exceptions\PrivateWsReconnectException;
use App\Models\ExchangeWsEvent;
use App\Services\ExchangeWsEventRecorder;
use App\Services\NobitexPrivateWsService;
use App\Services\NobitexService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Network-free tests for the private (order/trade) WebSocket consumer. A
 * scripted fake client replaces the socket; the clock, sleep and loop guard
 * are test seams.
 */
final class NobitexPrivateWsServiceTest extends TestCase
{
    private const TOKEN1 = 'jwt-ONE-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN2 = 'jwt-TWO-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TOKEN3 = 'jwt-THREE-ccccccccccccccccccccccccccccc';
    private const PARAM  = 'Ab12Cd34Ef56Gh78Ij90Kl12Mn34Op56';

    private const ORDERS = 'private:orders#' . self::PARAM;
    private const TRADES = 'private:trades#' . self::PARAM;

    /** @var array<int,string> every line logged through Laravel */
    private array $laravelLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'trading.nobitex.retry.initial_ms' => 0,
            'trading.nobitex.retry.max_ms' => 0,
            'trading.nobitex.retry.jitter_ms' => 0,
        ]);
        $this->laravelLogs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->laravelLogs[] = $m->level . ' ' . $m->message . ' ' . json_encode($m->context, JSON_UNESCAPED_UNICODE);
        });
    }

    /** @param array<int,string|\Throwable> $tokens consecutive getWebsocketToken() results */
    private function api(array $tokens): NobitexService
    {
        $api = Mockery::mock(NobitexService::class);
        $exp = $api->shouldReceive('getWebsocketToken');
        $exp->andReturnUsing(function () use (&$tokens) {
            $next = array_shift($tokens);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            if ($next === null) {
                throw new \LogicException('unexpected extra token fetch');
            }
            return $next;
        });
        $api->shouldReceive('getWebsocketAuthParam')->andReturn(self::PARAM);
        return $api;
    }

    private function service(NobitexService $api, ?ExchangeWsEventRecorder $recorder = null): TestablePrivateWsService
    {
        $recorder ??= Mockery::mock(ExchangeWsEventRecorder::class)->shouldIgnoreMissing();
        return new TestablePrivateWsService($api, $recorder);
    }

    private static function connectReply(int $ttl = 1200): string
    {
        return json_encode(['id' => 1, 'connect' => ['client' => 'c-1', 'version' => '5', 'expires' => true, 'ttl' => $ttl]]);
    }

    /** @return array<int,array<string,mixed>> */
    private static function decoded(FakeWsClient $c): array
    {
        return array_map(fn ($s) => json_decode($s, true), $c->sent);
    }

    private function assertNoSecretsLogged(TestablePrivateWsService $ws): void
    {
        $lines = array_merge(
            $this->laravelLogs,
            array_map(fn ($l) => $l[0] . ' ' . $l[1] . ' ' . json_encode($l[2], JSON_UNESCAPED_UNICODE), $ws->logs)
        );
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            foreach ([self::TOKEN1, self::TOKEN2, self::TOKEN3, self::PARAM] as $secret) {
                $this->assertStringNotContainsString($secret, $line);
            }
        }
    }

    /* ------------------------------ connect/subscribe ----------------------------- */

    public function test_connect_frame_carries_the_token_and_both_private_channels_are_subscribed(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),
            json_encode(['id' => 2, 'subscribe' => (object) []]) . "\n" . json_encode(['id' => 3, 'subscribe' => (object) []]),
        ]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        try {
            $ws->consumeOnce();
            $this->fail('script exhaustion should end the connection');
        } catch (ScriptExhausted) {
        }

        $sent = self::decoded($client);
        $this->assertSame(['id' => 1, 'connect' => ['token' => self::TOKEN1]], $sent[0]);
        $this->assertSame(['id' => 2, 'subscribe' => ['channel' => self::ORDERS]], $sent[1]);
        $this->assertSame(['id' => 3, 'subscribe' => ['channel' => self::TRADES]], $sent[2]);
        foreach ($client->sent as $raw) {
            $this->assertStringNotContainsString('delta', $raw);
        }
        $this->assertTrue($client->closed);
        $this->assertNoSecretsLogged($ws);
    }

    public function test_server_ping_is_answered_with_pong(): void
    {
        $client = new FakeWsClient([self::connectReply(), '{}']);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $this->assertSame('{}', end($client->sent));
    }

    public function test_subscribe_error_logs_critical_and_keeps_the_connection(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),
            json_encode(['id' => 2, 'error' => ['code' => 103, 'message' => 'permission denied']]),
            '{}',
        ]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $crit = array_values(array_filter($ws->logs, fn ($l) => $l[0] === 'critical'));
        $this->assertCount(1, $crit);
        $this->assertSame('WS_PRIVATE_SUBSCRIBE_FAILED', $crit[0][1]);
        $this->assertSame(103, $crit[0][2]['code']);
        $this->assertSame('private:orders#Ab12…', $crit[0][2]['channel']);
        // Still alive: the following ping was answered.
        $this->assertSame('{}', end($client->sent));
        $this->assertNoSecretsLogged($ws);
    }

    public function test_connect_error_drops_the_connection(): void
    {
        $client = new FakeWsClient([json_encode(['id' => 1, 'error' => ['code' => 109, 'message' => 'token expired']])]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        $this->expectException(PrivateWsReconnectException::class);
        $ws->consumeOnce();
    }

    /* -------------------------------- token refresh ------------------------------- */

    public function test_refresh_is_sent_before_ttl_expiry_with_a_new_token(): void
    {
        $client = new FakeWsClient([
            self::connectReply(1200),                          // at t=1000 → refresh due at 2080, expires 2200
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2079, '{}'][1],
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2080, '{}'][1],
            json_encode(['id' => 4, 'refresh' => ['client' => 'c-1', 'expires' => true, 'ttl' => 1200]]),
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2500, '{}'][1], // past old expiry: fine after refresh
        ]);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clock = 1000;
        $ws->clients = [$client];
        $client->owner = $ws;

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $refreshes = array_values(array_filter(self::decoded($client), fn ($f) => is_array($f) && isset($f['refresh'])));
        $this->assertSame([['id' => 4, 'refresh' => ['token' => self::TOKEN2]]], $refreshes);
        // Not sent one second early: the frame right after the 2079 ping is the pong.
        $this->assertSame('{}', $client->sent[3]);
        $this->assertNoSecretsLogged($ws);
    }

    public function test_refresh_token_fetch_failure_drops_the_connection(): void
    {
        $client = new FakeWsClient([
            self::connectReply(1200),
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2100, '{}'][1],
        ]);
        $ws = $this->service($this->api([self::TOKEN1, new \RuntimeException('api down')]));
        $ws->clock = 1000;
        $ws->clients = [$client];
        $client->owner = $ws;

        $this->expectException(PrivateWsReconnectException::class);
        $ws->consumeOnce();
    }

    public function test_refresh_rejected_by_server_drops_the_connection(): void
    {
        $client = new FakeWsClient([
            self::connectReply(1200),
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2100, '{}'][1],
            json_encode(['id' => 4, 'error' => ['code' => 109, 'message' => 'token expired']]),
        ]);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clock = 1000;
        $ws->clients = [$client];
        $client->owner = $ws;

        $this->expectException(PrivateWsReconnectException::class);
        $ws->consumeOnce();
    }

    public function test_token_expiry_without_refresh_reply_drops_the_connection(): void
    {
        $client = new FakeWsClient([
            self::connectReply(1200),
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2100, '{}'][1], // refresh sent, no reply
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2200, '{}'][1],
        ]);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clock = 1000;
        $ws->clients = [$client];
        $client->owner = $ws;

        $this->expectException(PrivateWsReconnectException::class);
        $this->expectExceptionMessage('expired');
        $ws->consumeOnce();
    }

    public function test_server_disconnect_push_drops_the_connection(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),
            json_encode(['push' => ['disconnect' => ['code' => 3005, 'reason' => 'connection expired']]]),
        ]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        $this->expectException(PrivateWsReconnectException::class);
        $this->expectExceptionMessage('3005');
        $ws->consumeOnce();
    }

    public function test_socket_closed_by_server_drops_the_connection(): void
    {
        $client = new FakeWsClient([self::connectReply(), fn () => null]);
        $client->disconnectOnNull = true;
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        $this->expectException(PrivateWsReconnectException::class);
        $ws->consumeOnce();
    }

    public function test_run_reconnects_with_a_fresh_token_after_refresh_failure(): void
    {
        $first = new FakeWsClient([
            self::connectReply(1200),
            fn (TestablePrivateWsService $ws) => [$ws->clock = 2100, '{}'][1],
        ]);
        $second = new FakeWsClient([self::connectReply(1200)]);
        $ws = $this->service($this->api([self::TOKEN1, new \RuntimeException('api down'), self::TOKEN3]));
        $ws->clock = 1000;
        $ws->clients = [$first, $second];
        $first->owner = $second->owner = $ws;
        $ws->loops = 2;

        $ws->run();

        $this->assertSame(['id' => 1, 'connect' => ['token' => self::TOKEN3]], self::decoded($second)[0]);
        $this->assertSame(['id' => 2, 'subscribe' => ['channel' => self::ORDERS]], self::decoded($second)[1]);
        $this->assertCount(2, $ws->sleeps);
        $this->assertTrue($first->closed);
        $this->assertNoSecretsLogged($ws);
    }

    public function test_run_aborts_when_another_instance_holds_the_lock(): void
    {
        Cache::put(NobitexPrivateWsService::LOCK_KEY, 999, 60);
        $ws = $this->service($this->api([]));
        $ws->loops = 1;

        $ws->run();

        $this->assertSame(0, $ws->opened);
    }

    /* --------------------------------- publications -------------------------------- */

    public function test_publications_are_routed_to_the_recorder_by_channel(): void
    {
        $orderData = json_encode(['orderId' => 1, 'status' => 'New']);
        $tradeData = ['id' => 9, 'orderId' => 1];

        $recorder = Mockery::mock(ExchangeWsEventRecorder::class);
        $recorder->shouldReceive('record')->once()->with(ExchangeWsEvent::CHANNEL_ORDERS, $orderData)->andReturn(1);
        $recorder->shouldReceive('record')->once()->with(ExchangeWsEvent::CHANNEL_TRADES, $tradeData)->andReturn(1);

        $client = new FakeWsClient([
            self::connectReply(),
            json_encode(['push' => ['channel' => self::ORDERS, 'pub' => ['data' => $orderData]]]),
            json_encode(['push' => ['channel' => self::TRADES, 'pub' => ['data' => $tradeData]]]),
            json_encode(['push' => ['channel' => 'private:orders#someone-else', 'pub' => ['data' => $orderData]]]),
            'not json at all',
        ]);
        $ws = $this->service($this->api([self::TOKEN1]), $recorder);
        $ws->clients = [$client];

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $this->assertNoSecretsLogged($ws);
    }

    /* ---------------------------------- heartbeat ---------------------------------- */

    public function test_heartbeat_written_on_frames_with_ten_second_guard(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),
            fn (TestablePrivateWsService $ws) => [$ws->clock = 1005, '{}'][1],
            fn (TestablePrivateWsService $ws) => [$ws->clock = 1011, '{}'][1],
        ]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clock = 1000;
        $ws->clients = [$client];
        $client->owner = $ws;
        $client->afterReceive = function () use (&$seen) {
            $seen[] = Cache::get(NobitexPrivateWsService::HEARTBEAT_FRAME_KEY);
        };
        $seen = [];

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        // written at 1000; 1005 is inside the guard; 1011 rewrites.
        $this->assertSame(1011, Cache::get(NobitexPrivateWsService::HEARTBEAT_FRAME_KEY));
        $this->assertSame([null, 1000, 1000, 1011], $seen);
    }

    public function test_mask_helpers(): void
    {
        $this->assertSame('Ab12…', NobitexPrivateWsService::mask(self::PARAM));
        $this->assertSame('private:trades#Ab12…', NobitexPrivateWsService::maskChannel(self::TRADES));
        $this->assertStringNotContainsString('ws-consumer', 'nobitex:ws-private');
    }
}

final class ScriptExhausted extends \RuntimeException
{
}

/** Scripted socket: each receive() pops the next frame (string, or closure returning one). */
final class FakeWsClient implements WsFrameClient
{
    /** @var array<int,string> */
    public array $sent = [];
    public bool $closed = false;
    public bool $connected = true;
    public bool $disconnectOnNull = false;
    public ?TestablePrivateWsService $owner = null;
    public ?\Closure $afterReceive = null;

    /** @param array<int,string|\Closure> $script */
    public function __construct(private array $script)
    {
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function receive(): ?string
    {
        if ($this->afterReceive !== null) {
            ($this->afterReceive)();
        }
        if ($this->script === []) {
            throw new ScriptExhausted('script exhausted');
        }
        $next = array_shift($this->script);
        if ($next instanceof \Closure) {
            $next = $next($this->owner);
        }
        if ($next === null && $this->disconnectOnNull) {
            $this->connected = false;
        }
        return $next;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class TestablePrivateWsService extends NobitexPrivateWsService
{
    public int $clock = 1000;
    /** @var array<int,FakeWsClient> */
    public array $clients = [];
    public int $opened = 0;
    public int $loops = 0;
    /** @var array<int,int> */
    public array $sleeps = [];
    /** @var array<int,array{0:string,1:string,2:array}> */
    public array $logs = [];

    public function consumeOnce(): void
    {
        $this->consume();
    }

    protected function openClient(): WsFrameClient
    {
        $this->opened++;
        $c = array_shift($this->clients);
        if ($c === null) {
            throw new \LogicException('no fake client left');
        }
        return $c;
    }

    protected function keepRunning(): bool
    {
        return $this->loops-- > 0;
    }

    protected function sleepSeconds(int $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    protected function nowSeconds(): int
    {
        return $this->clock;
    }

    protected function out(string $msg, array $ctx = [], string $level = 'info'): void
    {
        $this->logs[] = [$level, $msg, $ctx];
    }
}
