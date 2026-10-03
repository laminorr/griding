<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\WsFrameClient;
use App\Exceptions\PrivateWsReconnectException;
use App\Exceptions\PrivateWsSilenceException;
use App\Exceptions\WsReadTimeoutException;
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

    /* ------------------------------ liveness / timeouts ------------------------------ */

    /** A scripted read that times out at the given clock (the socket stays open). */
    private static function timeoutAt(int $clock, bool $socketClosed = false): \Closure
    {
        return function (TestablePrivateWsService $ws) use ($clock, $socketClosed) {
            $ws->clock = $clock;
            if ($socketClosed) {
                $ws->currentClient->connected = false;
            }
            throw new WsReadTimeoutException('Client read timeout');
        };
    }

    private static function pingAt(int $clock): \Closure
    {
        return fn (TestablePrivateWsService $ws) => [$ws->clock = $clock, '{}'][1];
    }

    /** @return array<int,array{0:string,1:string,2:array}> */
    private static function logsNamed(TestablePrivateWsService $ws, string $msg): array
    {
        return array_values(array_filter($ws->logs, fn ($l) => $l[1] === $msg));
    }

    public function test_silence_longer_than_the_old_25s_with_pings_does_not_reconnect(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),           // t=1000
            self::pingAt(1040),             // 40s gap: the old 25s socket timeout would have dropped here
            self::timeoutAt(1100),          // 60s read timeout, 60s < 90s silence: keep reading
            self::pingAt(1105),             // ping resets the silence clock
            self::timeoutAt(1165),
            self::pingAt(1170),
        ]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];
        $client->owner = $ws;

        try {
            $ws->consumeOnce();
            $this->fail('expected the script to run out while still connected');
        } catch (ScriptExhausted) {
            // still reading: no reconnect
        }

        $this->assertSame(60, config('trading.websocket.read_timeout_seconds'));
        // Full 60s reads; the read after each timeout is capped at the silence budget left (90-60=30).
        $this->assertSame([60, 60, 60, 30, 60, 30, 60], $client->readTimeouts);
        $this->assertSame(['{}', '{}', '{}'], array_slice($client->sent, 3)); // every ping answered
        $this->assertSame([], self::logsNamed($ws, '[WS-PRIVATE] Connection dropped; reconnecting'));
        $this->assertCount(2, self::logsNamed($ws, '[WS-PRIVATE] Read timeout; within silence budget'));
    }

    public function test_no_frames_for_max_silence_reconnects_with_that_reason(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),           // t=1000: last frame
            self::timeoutAt(1060),          // tolerated
            self::timeoutAt(1090),          // 90s without any frame
        ]);
        $second = new FakeWsClient([self::connectReply()]);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clients = [$client, $second];
        $client->owner = $second->owner = $ws;
        $ws->loops = 1;

        $ws->run();

        $this->assertSame([60, 60, 30], $client->readTimeouts);
        $this->assertTrue($client->closed);
        $drops = self::logsNamed($ws, '[WS-PRIVATE] Connection dropped; reconnecting');
        $this->assertCount(1, $drops);
        $this->assertSame('no frames for 90s', $drops[0][2]['error']);
        $this->assertSame('error', $drops[0][0]); // first genuine drop this hour
    }

    public function test_read_timeout_on_a_closed_socket_is_a_drop(): void
    {
        $client = new FakeWsClient([self::connectReply(), self::timeoutAt(1060, socketClosed: true)]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];
        $client->owner = $ws;

        $this->expectException(WsReadTimeoutException::class);
        $ws->consumeOnce();
    }

    public function test_config_drives_read_timeout_and_silence(): void
    {
        config([
            'trading.websocket.read_timeout_seconds' => 40,
            'trading.websocket.private_max_silence_seconds' => 100,
        ]);
        $client = new FakeWsClient([self::connectReply(), self::timeoutAt(1040), self::timeoutAt(1080), self::timeoutAt(1100)]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];
        $client->owner = $ws;

        try {
            $ws->consumeOnce();
            $this->fail('expected a silence reconnect');
        } catch (PrivateWsSilenceException $e) {
            $this->assertSame('no frames for 100s', $e->getMessage());
        }
        $this->assertSame([40, 40, 40, 20], $client->readTimeouts);
        // The single-instance lock must outlast one blocking read.
        $this->assertSame(60, (fn () => $this->lockTtlSeconds)->call($ws));
        config(['trading.websocket.read_timeout_seconds' => 60]);
        $this->assertSame(75, (fn () => $this->lockTtlSeconds)->call($this->service($this->api([]))));
    }

    public function test_repeated_drops_warn_and_flapping_logs_one_error(): void
    {
        config(['trading.websocket.private_flap_reconnects_per_hour' => 3]);
        $clients = [];
        for ($i = 0; $i < 5; $i++) {
            // Each connection is healthy, then the socket read fails (attempt 1 every time).
            $clients[] = new FakeWsClient([
                self::connectReply(),
                function (TestablePrivateWsService $ws) use ($i) {
                    $ws->clock = 1000 + ($i + 1) * 120;
                    throw new \RuntimeException('Broken frame');
                },
            ]);
        }
        $ws = $this->service($this->api(array_fill(0, 5, self::TOKEN1)));
        $ws->clients = $clients;
        foreach ($clients as $c) {
            $c->owner = $ws;
        }
        $ws->loops = 5;

        $ws->run();

        $drops = self::logsNamed($ws, '[WS-PRIVATE] Connection dropped; reconnecting');
        $this->assertSame(['error', 'warning', 'warning', 'warning', 'warning'], array_column($drops, 0));
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($d) => $d[2]['reconnects_last_hour'], $drops));
        $this->assertSame([1, 1, 1, 1, 1], array_map(fn ($d) => $d[2]['attempt'], $drops));

        $flap = self::logsNamed($ws, 'WS_PRIVATE_FLAPPING');
        $this->assertCount(1, $flap); // crossed at the 4th reconnect; not repeated within the hour
        $this->assertSame('error', $flap[0][0]);
        $this->assertSame(4, $flap[0][2]['reconnects_last_hour']);
        $this->assertSame(3, $flap[0][2]['threshold']);
    }

    public function test_flap_window_is_one_hour(): void
    {
        config(['trading.websocket.private_flap_reconnects_per_hour' => 1]);
        $clients = [];
        foreach ([1100, 5000, 5100] as $t) {
            $clients[] = new FakeWsClient([
                self::connectReply(),
                function (TestablePrivateWsService $ws) use ($t) {
                    $ws->clock = $t;
                    throw new \RuntimeException('Broken frame');
                },
            ]);
        }
        $ws = $this->service($this->api(array_fill(0, 3, self::TOKEN1)));
        $ws->clients = $clients;
        foreach ($clients as $c) {
            $c->owner = $ws;
        }
        $ws->loops = 3;

        $ws->run();

        $drops = self::logsNamed($ws, '[WS-PRIVATE] Connection dropped; reconnecting');
        // 1100 → first; 5000 is > 1h later → first again (ERROR); 5100 → repeat.
        $this->assertSame(['error', 'error', 'warning'], array_column($drops, 0));
        $this->assertSame([1, 1, 2], array_map(fn ($d) => $d[2]['reconnects_last_hour'], $drops));
        $this->assertCount(1, self::logsNamed($ws, 'WS_PRIVATE_FLAPPING'));
    }

    public function test_planned_reconnect_warns_and_failed_reconnect_attempt_errors(): void
    {
        $planned = new FakeWsClient([
            self::connectReply(),
            json_encode(['push' => ['disconnect' => ['code' => 3005, 'reason' => 'connection expired']]]),
        ]);
        // Never established: the reconnect attempt itself fails.
        $failing = new FakeWsClient([fn () => throw new \RuntimeException('handshake failed')]);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clients = [$planned, $failing];
        $planned->owner = $failing->owner = $ws;
        $ws->loops = 2;

        $ws->run();

        $drops = self::logsNamed($ws, '[WS-PRIVATE] Connection dropped; reconnecting');
        $this->assertSame([['warning', 1], ['error', 2]], array_map(fn ($d) => [$d[0], $d[2]['attempt']], $drops));
    }

    public function test_refresh_still_fires_on_a_silent_channel_from_pings_alone(): void
    {
        // connect at t=1000, ttl 1200 → refresh due at 2080. Only pings arrive.
        $script = [self::connectReply(1200)];
        for ($t = 1025; $t <= 2100; $t += 25) {
            $script[] = self::pingAt($t);
        }
        $client = new FakeWsClient($script);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clients = [$client];
        $client->owner = $ws;

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $frames = self::decoded($client);
        $refreshAt = array_keys(array_filter($frames, fn ($f) => is_array($f) && isset($f['refresh'])));
        $this->assertCount(1, $refreshAt);
        $this->assertSame(['token' => self::TOKEN2], $frames[$refreshAt[0]]['refresh']);
        // Sent right after the first ping at/after 2080 (t=2100), i.e. within one ping interval.
        $pongsBefore = count(array_filter(array_slice($client->sent, 3, $refreshAt[0] - 3), fn ($f) => $f === '{}'));
        $this->assertSame(intdiv(2100 - 1025, 25) + 1, $pongsBefore);
    }

    public function test_refresh_fires_after_a_tolerated_read_timeout(): void
    {
        $client = new FakeWsClient([
            self::connectReply(1200),   // refresh due at 2080
            self::pingAt(2050),
            self::timeoutAt(2110),      // a missed ping; 60s < 90s silence → keep reading
        ]);
        $ws = $this->service($this->api([self::TOKEN1, self::TOKEN2]));
        $ws->clients = [$client];
        $client->owner = $ws;

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $this->assertSame(['id' => 4, 'refresh' => ['token' => self::TOKEN2]], self::decoded($client)[4]);
        $this->assertCount(1, self::logsNamed($ws, '[WS-PRIVATE] Token refresh sent'));
    }

    public function test_subscribe_reply_recovery_fields_are_logged(): void
    {
        $client = new FakeWsClient([
            self::connectReply(),
            json_encode(['id' => 2, 'subscribe' => ['recoverable' => true, 'epoch' => 'abcd', 'offset' => 17, 'positioned' => true]]),
            json_encode(['id' => 3, 'subscribe' => (object) []]),
        ]);
        $ws = $this->service($this->api([self::TOKEN1]));
        $ws->clients = [$client];

        try { $ws->consumeOnce(); } catch (ScriptExhausted) {}

        $subs = self::logsNamed($ws, '[WS-PRIVATE] Subscribed');
        $this->assertSame('private:orders#Ab12…', $subs[0][2]['channel']);
        $this->assertSame([true, true, 'abcd', 17], [$subs[0][2]['recoverable'], $subs[0][2]['positioned'], $subs[0][2]['epoch'], $subs[0][2]['offset']]);
        $this->assertSame([null, null, null, null, []], [$subs[1][2]['recoverable'], $subs[1][2]['positioned'], $subs[1][2]['epoch'], $subs[1][2]['offset'], $subs[1][2]['reply_keys']]);
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
    /** @var array<int,int> every setReadTimeout() value, in order */
    public array $readTimeouts = [];

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

    public function setReadTimeout(int $seconds): void
    {
        $this->readTimeouts[] = $seconds;
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
    public ?FakeWsClient $currentClient = null;
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
        return $this->currentClient = $c;
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
