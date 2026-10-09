<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Read-only scanner for the app's Monolog line files (plain *.log and rotated
 * *.log.gz, read through gzopen) — used by `bot:audit`.
 *
 * Line shape (App\Logging\CustomizeFormatter):
 *   [2026-10-03 12:00:00] production.WARNING: EVENT_NAME {"json":"context"}
 * The context JSON is optional; continuation lines (stack traces) and lines
 * that do not start with the timestamp prefix are skipped. `testing.*` lines
 * (a test run that wrote into storage/logs) are always ignored.
 *
 * Timestamps are compared as 'Y-m-d H:i:s' strings in the app timezone (the
 * formatter writes them in that zone), which sorts lexicographically.
 */
final class AuditLogScanner
{
    private const LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] ([A-Za-z0-9_\-]+)\.([A-Z]+): (.*)$/s';

    /** @var array<string,int> file => lines parsed (for the report's method note) */
    public array $files = [];

    public int $skippedTesting = 0;

    /** Lines parsed inside the window (kept or not). */
    public int $linesInWindow = 0;

    /**
     * Every parsed entry in [$from, $to] from the files under $dir for which
     * $keep (if given) returns true — lets the caller drop routine INFO noise
     * so a week of logs does not have to fit in memory.
     *
     * @param (callable(array):bool)|null $keep
     * @return list<array{ts:string, env:string, level:string, event:string, message:string, context:array, file:string}>
     */
    public function scan(string $dir, string $from, string $to, ?callable $keep = null): array
    {
        $out = [];
        foreach ($this->candidateFiles($dir, $from, $to) as $path) {
            $count = 0;
            foreach ($this->lines($path) as $line) {
                $entry = $this->parseLine($line);
                if ($entry === null) {
                    continue;
                }
                if ($entry['env'] === 'testing') {
                    $this->skippedTesting++;
                    continue;
                }
                if ($entry['ts'] < $from || $entry['ts'] > $to) {
                    continue;
                }
                $this->linesInWindow++;
                if ($keep !== null && ! $keep($entry)) {
                    continue;
                }
                $entry['file'] = basename($path);
                $out[] = $entry;
                $count++;
            }
            $this->files[basename($path)] = $count;
        }

        usort($out, fn (array $a, array $b) => strcmp($a['ts'], $b['ts']));

        return $out;
    }

    /**
     * Parse one line. Returns null for continuation / foreign lines.
     *
     * @return array{ts:string, env:string, level:string, event:string, message:string, context:array}|null
     */
    public function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (! preg_match(self::LINE, $line, $m)) {
            return null;
        }
        [$message, $context] = $this->splitContext($m[4]);

        return [
            'ts'      => $m[1],
            'env'     => $m[2],
            'level'   => $m[3],
            'event'   => self::eventName($message),
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * The stable event key of a message: the whole message for bracketed
     * "[WS-PRIVATE] Connection dropped; reconnecting" style lines, else the
     * first token ("EXIT_SIZED", "CheckTradesJob:").
     */
    public static function eventName(string $message): string
    {
        $message = trim($message);
        if (str_starts_with($message, '[')) {
            return mb_substr($message, 0, 80);
        }
        $first = strtok($message, ' ');

        return $first === false ? '' : mb_substr($first, 0, 80);
    }

    /** @return array{0:string, 1:array} message, decoded context */
    private function splitContext(string $rest): array
    {
        $rest   = rtrim($rest);
        $offset = 0;
        while (($pos = strpos($rest, ' {', $offset)) !== false) {
            $candidate = substr($rest, $pos + 1);
            foreach ([$candidate, preg_replace('/\s+(\[\]|\{.*\})$/s', '', $candidate)] as $json) {
                if (! is_string($json) || $json === '') {
                    continue;
                }
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return [substr($rest, 0, $pos), $decoded];
                }
            }
            $offset = $pos + 2;
        }

        return [preg_replace('/\s+\[\]$/', '', $rest) ?? $rest, []];
    }

    /**
     * *.log and *.log.gz files under $dir. Daily files whose name carries a
     * date more than one day outside the window are skipped unread.
     *
     * @return list<string>
     */
    private function candidateFiles(string $dir, string $from, string $to): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $fromDay = date('Y-m-d', strtotime(substr($from, 0, 10) . ' -1 day'));
        $toDay   = date('Y-m-d', strtotime(substr($to, 0, 10) . ' +1 day'));

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (! preg_match('/\.log(\.\d+)?(\.gz)?$/', $name)) {
                continue;
            }
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $name, $d) && ($d[1] < $fromDay || $d[1] > $toDay)) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && is_readable($path)) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /** @return \Generator<string> */
    private function lines(string $path): \Generator
    {
        $gz = str_ends_with($path, '.gz');
        $h  = $gz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($h === false) {
            return;
        }
        try {
            while (true) {
                $line = $gz ? gzgets($h) : fgets($h);
                if ($line === false) {
                    break;
                }
                yield $line;
            }
        } finally {
            $gz ? gzclose($h) : fclose($h);
        }
    }
}
