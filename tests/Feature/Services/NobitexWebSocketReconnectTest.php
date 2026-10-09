<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\NobitexWebSocketService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Public WS consumer reconnect logging — same policy as the private consumer
 * (NobitexPrivateWsService::reportDrop). Production: Nobitex recycles the
 * public connection nightly (~03:50–04:05 Tehran) with "Empty read; connection
 * dead?"; that used to log ERROR "[WS] Crash" with an attempt counter that
 * never reset (attempt 4, 8, …).
 */
final class NobitexWebSocketReconnectTest extends TestCase
{
    private const DROP = '[WS] Connection dropped; reconnecting';

    private function service(array $script, int $flapThreshold = 10): ScriptedReconnectWsService
    {
        config(['trading.websocket.private_flap_reconnects_per_hour' => $flapThreshold]);
        Cache::flush();
        $ws = new ScriptedReconnectWsService();
        $ws->script = $script;

        return $ws;
    }

    /** @return list<array{0:string,1:string,2:array}> */
    private static function logsNamed(ScriptedReconnectWsService $ws, string $msg): array
    {
        return array_values(array_filter($ws->logs, fn ($l) => $l[1] === $msg));
    }

    public function test_expected_server_drop_is_a_warning_not_an_error(): void
    {
        $ws = $this->service([
            ['established' => true, 'error' => 'Empty read; connection dead?', 'at' => 1000],
            ['established' => true, 'error' => 'Socket closed by server (close frame)', 'at' => 2000],
        ]);

        $ws->run(['BTCIRT'], true);

        $drops = self::logsNamed($ws, self::DROP);
        $this->assertSame(['warning', 'warning'], array_column($drops, 0));
        $this->assertSame([1, 1], array_map(fn ($d) => $d[2]['attempt'], $drops));
        $this->assertSame([1, 2], array_map(fn ($d) => $d[2]['reconnects_last_hour'], $drops));
        $this->assertSame('Empty read; connection dead?', $drops[0][2]['error']);
        $this->assertSame([], self::logsNamed($ws, '[WS] Crash'));
        $this->assertSame([], array_filter($ws->logs, fn ($l) => $l[0] === 'error'));
    }

    public function test_attempt_counter_restarts_after_a_healthy_connection(): void
    {
        // Old code: attempt kept counting across the process lifetime (4, 8, …).
        $script = array_fill(0, 4, ['established' => true, 'error' => 'Empty read; connection dead?', 'at' => 1000]);
        $ws = $this->service($script);

        $ws->run(['BTCIRT'], true);

        $this->assertSame([1, 1, 1, 1], array_map(fn ($d) => $d[2]['attempt'], self::logsNamed($ws, self::DROP)));
    }

    public function test_repeated_failed_reconnect_attempts_are_errors(): void
    {
        $ws = $this->service([
            ['established' => true, 'error' => 'Empty read; connection dead?', 'at' => 1000],
            // Reconnect never comes up (handshake/DNS fails), twice.
            ['established' => false, 'error' => 'Could not open socket', 'at' => 1002],
            ['established' => false, 'error' => 'Could not open socket', 'at' => 1005],
        ]);

        $ws->run(['BTCIRT'], true);

        $drops = self::logsNamed($ws, self::DROP);
        $this->assertSame([['warning', 1], ['error', 2], ['error', 3]], array_map(fn ($d) => [$d[0], $d[2]['attempt']], $drops));
    }

    public function test_unexpected_drop_is_error_once_an_hour_then_warning(): void
    {
        $ws = $this->service([
            ['established' => true, 'error' => 'Broken frame', 'at' => 1000],
            ['established' => true, 'error' => 'Broken frame', 'at' => 1100],
            ['established' => true, 'error' => 'Broken frame', 'at' => 5000],
        ]);

        $ws->run(['BTCIRT'], true);

        $this->assertSame(['error', 'warning', 'error'], array_column(self::logsNamed($ws, self::DROP), 0));
    }

    public function test_more_than_threshold_reconnects_per_hour_logs_one_flapping_error(): void
    {
        $script = [];
        for ($i = 1; $i <= 5; $i++) {
            $script[] = ['established' => true, 'error' => 'Empty read; connection dead?', 'at' => 1000 + $i * 60];
        }
        $ws = $this->service($script, 3);

        $ws->run(['BTCIRT'], true);

        $this->assertSame(array_fill(0, 5, 'warning'), array_column(self::logsNamed($ws, self::DROP), 0));
        $flap = self::logsNamed($ws, 'WS_PUBLIC_FLAPPING');
        $this->assertCount(1, $flap);
        $this->assertSame('error', $flap[0][0]);
        $this->assertSame(4, $flap[0][2]['reconnects_last_hour']);
        $this->assertSame(3, $flap[0][2]['threshold']);
    }

    public function test_command_filters_disallowed_symbols_with_one_warning(): void
    {
        config(['trading.exchange.allowed_symbols' => ['BTCIRT']]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = [$e->level, $e->message, $e->context];
        });

        $svc = Mockery::mock(NobitexWebSocketService::class);
        $svc->shouldReceive('run')->once()->with(['BTCIRT'], false);
        $this->app->instance(NobitexWebSocketService::class, $svc);

        $this->artisan('nobitex:ws-consumer', ['symbols' => 'BTCIRT,ETHIRT,USDTIRT'])->assertSuccessful();

        $warn = array_values(array_filter($logged, fn ($l) => $l[0] === 'warning'));
        $this->assertCount(1, $warn);
        $this->assertSame('[WS] Skipping symbols not in trading.exchange.allowed_symbols', $warn[0][1]);
        $this->assertSame(['ETHIRT', 'USDTIRT'], $warn[0][2]['skipped']);
        $this->assertSame(['BTCIRT'], $warn[0][2]['subscribing']);
    }

    public function test_command_with_all_symbols_allowed_logs_no_warning(): void
    {
        config(['trading.exchange.allowed_symbols' => ['BTCIRT', 'ETHIRT', 'USDTIRT']]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->level;
        });

        $svc = Mockery::mock(NobitexWebSocketService::class);
        $svc->shouldReceive('run')->once()->with(['BTCIRT', 'ETHIRT', 'USDTIRT'], false);
        $this->app->instance(NobitexWebSocketService::class, $svc);

        $this->artisan('nobitex:ws-consumer', ['symbols' => 'BTCIRT,ETHIRT,USDTIRT'])->assertSuccessful();

        $this->assertNotContains('warning', $logged);
    }
}

/** Each consume() pops one scripted connection outcome and throws its error. */
final class ScriptedReconnectWsService extends NobitexWebSocketService
{
    /** @var list<array{established:bool,error:string,at:int}> */
    public array $script = [];
    public int $clock = 1000;
    /** @var list<int> */
    public array $sleeps = [];
    /** @var list<array{0:string,1:string,2:array}> */
    public array $logs = [];

    protected function consume(array $symbols): void
    {
        $step = array_shift($this->script);
        $this->clock = $step['at'];
        $this->established = $step['established'];
        throw new \RuntimeException($step['error']);
    }

    protected function keepRunning(): bool
    {
        return $this->script !== [];
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
