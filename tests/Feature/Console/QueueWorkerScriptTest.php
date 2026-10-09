<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * scripts/queue-worker.sh: restarts queue:work whenever it exits (closing the
 * hourly --max-time gap) and is single-instance under flock so cron can start
 * it every minute. Driven with a fake PHP binary via the QUEUE_WORKER_PHP hook.
 */
final class QueueWorkerScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/usr/bin/flock') && trim((string) shell_exec('command -v flock')) === '') {
            $this->markTestSkipped('flock not available');
        }
        $this->dir = sys_get_temp_dir() . '/qw-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        // Fake "php": records its argv and exits non-zero, like a crashed worker.
        file_put_contents($this->dir . '/php', "#!/bin/sh\necho \"\$*\" >> \"{$this->dir}/runs\"\nexit 3\n");
        chmod($this->dir . '/php', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function process(int $maxRuns): Process
    {
        return new Process(['bash', base_path('scripts/queue-worker.sh')], null, [
            'QUEUE_WORKER_PHP'      => $this->dir . '/php',
            'QUEUE_WORKER_LOCK'     => $this->dir . '/lock',
            'QUEUE_WORKER_LOG'      => $this->dir . '/log',
            'QUEUE_WORKER_MAX_RUNS' => (string) $maxRuns,
        ]);
    }

    public function test_worker_is_restarted_after_every_exit_and_each_restart_is_logged(): void
    {
        $p = $this->process(3);
        $p->setTimeout(20);
        $p->run();

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $runs = file($this->dir . '/runs', FILE_IGNORE_NEW_LINES);
        $this->assertSame(
            array_fill(0, 3, 'artisan queue:work database --sleep=1 --tries=3 --max-time=3540 --memory=256'),
            $runs,
        );
        $log = (string) file_get_contents($this->dir . '/log');
        $this->assertSame(3, substr_count($log, 'queue:work exited (code 3); restarting in 1s'));
        $this->assertStringContainsString('starting queue:work (run 3)', $log);
    }

    public function test_second_copy_exits_at_once_while_the_lock_is_held(): void
    {
        // Hold the lock the way a running loop would.
        $holder = new Process(['flock', $this->dir . '/lock', 'sleep', '10']);
        $holder->start();
        usleep(300_000);

        try {
            $p = $this->process(1);
            $p->setTimeout(5);
            $started = microtime(true);
            $p->run();

            $this->assertSame(0, $p->getExitCode());
            $this->assertLessThan(2.0, microtime(true) - $started);
            $this->assertFileDoesNotExist($this->dir . '/runs');
        } finally {
            $holder->stop(0);
        }
    }
}
