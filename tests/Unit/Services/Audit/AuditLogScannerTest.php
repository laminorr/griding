<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditLogScanner;
use PHPUnit\Framework\TestCase;

final class AuditLogScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/audit-scan-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_parses_context_message_and_event_name(): void
    {
        $s = new AuditLogScanner();

        $e = $s->parseLine('[2026-10-03 12:00:00] production.WARNING: EXIT_SIZED {"bot_id":48,"amount":"0.000044"}');
        $this->assertSame(['2026-10-03 12:00:00', 'production', 'WARNING', 'EXIT_SIZED', 'EXIT_SIZED'], [$e['ts'], $e['env'], $e['level'], $e['event'], $e['message']]);
        $this->assertSame(['bot_id' => 48, 'amount' => '0.000044'], $e['context']);

        $e = $s->parseLine('[2026-10-03 12:00:00] production.WARNING: [WS-PRIVATE] Connection dropped; reconnecting {"error":"x"} []');
        $this->assertSame('[WS-PRIVATE] Connection dropped; reconnecting', $e['event']);
        $this->assertSame(['error' => 'x'], $e['context']);

        $e = $s->parseLine('[2026-10-03 12:00:00] production.INFO: CheckTradesJob: Order 5 at {weird} text');
        $this->assertSame('CheckTradesJob:', $e['event']);
        $this->assertSame([], $e['context']);

        $this->assertNull($s->parseLine('#0 /var/www/vendor/foo.php(12): bar()'));
    }

    public function test_reads_gz_and_plain_skips_testing_and_out_of_window(): void
    {
        file_put_contents($this->dir . '/trading-2026-10-03.log.gz', gzencode(
            "[2026-10-03 10:00:00] production.INFO: A {\"n\":1}\n[2026-10-03 10:00:01] testing.ERROR: T\n"
        ));
        file_put_contents($this->dir . '/trading-2026-10-04.log', "[2026-10-04 09:00:00] production.ERROR: B\n[2026-10-09 09:00:00] production.ERROR: LATE\n");
        file_put_contents($this->dir . '/trading-2026-09-01.log', "[2026-10-03 11:00:00] production.ERROR: SKIPPED_BY_FILE_DATE\n");
        file_put_contents($this->dir . '/notes.txt', "[2026-10-03 11:00:00] production.ERROR: NOT_A_LOG\n");

        $s = new AuditLogScanner();
        $out = $s->scan($this->dir, '2026-10-03 00:00:00', '2026-10-05 00:00:00');

        $this->assertSame(['A', 'B'], array_column($out, 'event'));
        $this->assertSame('trading-2026-10-03.log.gz', $out[0]['file']);
        $this->assertSame(1, $s->skippedTesting);
        $this->assertArrayNotHasKey('trading-2026-09-01.log', $s->files);
    }
}
