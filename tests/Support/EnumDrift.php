<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\GridOrder;

/**
 * Static checks behind EnumDriftGuardTest: sqlite has no ENUM, so these keep
 * "the code writes a value the MySQL column does not allow" from reaching
 * production unseen.
 *
 *  scanSource()       — every literal (or GridOrder::CONSTANT) the code writes
 *                       to grid_orders.role/status/type: write-call arrays
 *                       (create, createIntent, update, forceFill, fill, insert,
 *                       updateOrCreate, …), `role:` named arguments and
 *                       `->status = '…'` assignments, on GridOrder or on an
 *                       untyped variable. Calls rooted in another model / DB
 *                       table are ignored.
 *  declaredByMigrations() — the ENUM value list each migration's up() leaves
 *                       on each column, replayed in filename order: Blueprint
 *                       ->enum(), raw "ALTER TABLE t MODIFY [COLUMN] c ENUM(…)",
 *                       and EnumColumns::addValues()/setValues().
 */
final class EnumDrift
{
    public const COLUMNS = ['role', 'status', 'type'];

    private const WRITE_METHODS = [
        'create', 'createintent', 'update', 'forcefill', 'fill', 'insert', 'insertorignore', 'make',
        'firstorcreate', 'firstornew', 'updateorcreate', 'updateorinsert', 'upsert', 'forcecreate',
    ];

    /** Static receivers that are NOT grid_orders. */
    private const OTHER_CLASSES = ['BotConfig', 'CompletedTrade', 'User', 'BotActivityLog', 'ExchangeWsEvent', 'Setting', 'Cache', 'Log', 'Http', 'Notification'];

    /** Variables that never hold a GridOrder (writes on them are other tables). */
    private const OTHER_VARIABLE = '/^\$(bot|botConfig|botRow|config|trade|completedTrade|user|log|event|setting|this)$/i';

    /**
     * @return list<array{file:string, line:int, column:string, value:string, how:string}>
     */
    public static function scanSource(string $code, string $file = 'inline'): array
    {
        $t = array_values(array_filter(token_get_all($code), fn ($x) => ! (is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
        $n = count($t);
        $out = [];

        // Bracket stack: each entry = ['ch' => '(', 'call' => ?receiver-info, 'arrayOf' => ?call-info]
        $stack = [];
        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            $ch  = is_array($tok) ? null : $tok;

            if ($ch === '(') {
                $stack[] = ['ch' => '(', 'call' => self::callAt($t, $i)];
                continue;
            }
            if ($ch === '[' || (is_array($tok) && $tok[0] === T_ARRAY && ($t[$i + 1] ?? null) === '(')) {
                if (is_array($tok)) {
                    $i++;   // array( … )
                }
                $parent = end($stack) ?: null;
                $call   = ($parent !== null && $parent['ch'] === '(' && $parent['call'] !== null) ? $parent['call'] : null;
                // A row array nested as an ELEMENT (insert([[...], [...]])) inherits
                // its parent's write context; a value array ('raw_json' => [...]) does not.
                $prevTok = $t[$i - (is_array($tok) ? 2 : 1)] ?? null;
                $isValue = is_array($prevTok) && $prevTok[0] === T_DOUBLE_ARROW;
                if ($call === null && ! $isValue && $parent !== null && ($parent['arrayOf'] ?? null) !== null) {
                    $call = $parent['arrayOf'];
                }
                $stack[] = ['ch' => is_array($tok) ? '(' : '[', 'call' => null, 'arrayOf' => $call];
                continue;
            }
            if ($ch === '{') {
                $stack[] = ['ch' => '{', 'call' => null];
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                array_pop($stack);
                continue;
            }

            // 'role' => <expr> inside a write-call array
            if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && ($t[$i + 1][0] ?? null) === T_DOUBLE_ARROW) {
                $key = trim($tok[1], "'\"");
                $top = end($stack) ?: null;
                if (in_array($key, self::COLUMNS, true) && $top !== null && ($top['arrayOf'] ?? null) !== null && $top['arrayOf']['grid']) {
                    foreach (self::valuesOf($t, $i + 2) as $v) {
                        $out[] = ['file' => $file, 'line' => $tok[2], 'column' => $key, 'value' => $v, 'how' => $top['arrayOf']['method'] . '([…])'];
                    }
                }
                continue;
            }

            // role: <expr> (named argument)
            if (is_array($tok) && $tok[0] === T_STRING && in_array($tok[1], self::COLUMNS, true)
                && ($t[$i + 1] ?? null) === ':' && in_array($t[$i - 1] ?? null, ['(', ','], true)) {
                foreach (self::valuesOf($t, $i + 2) as $v) {
                    $out[] = ['file' => $file, 'line' => $tok[2], 'column' => $tok[1], 'value' => $v, 'how' => 'named argument'];
                }
                continue;
            }

            // $order->status = <expr>
            if (is_array($tok) && in_array($tok[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && is_array($t[$i + 1] ?? null) && $t[$i + 1][0] === T_STRING && in_array($t[$i + 1][1], self::COLUMNS, true)
                && ($t[$i + 2] ?? null) === '=') {
                $var = is_array($t[$i - 1] ?? null) && $t[$i - 1][0] === T_VARIABLE ? $t[$i - 1][1] : null;
                if ($var !== null && ! preg_match(self::OTHER_VARIABLE, $var)) {
                    foreach (self::valuesOf($t, $i + 3) as $v) {
                        $out[] = ['file' => $file, 'line' => $t[$i + 1][2], 'column' => $t[$i + 1][1], 'value' => $v, 'how' => "{$var}->{$t[$i + 1][1]} ="];
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<array{file:string, line:int, column:string, value:string, how:string}> */
    public static function scanDirectory(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $rel = ltrim(str_replace(base_path(), '', $f->getPathname()), '/');
                array_push($out, ...self::scanSource((string) file_get_contents($f->getPathname()), $rel));
            }
        }

        return $out;
    }

    /**
     * Findings whose value is not allowed for its column.
     *
     * @param array<string, list<string>> $allowed column => values
     * @return list<string>
     */
    public static function violations(array $findings, array $allowed = GridOrder::ENUM_COLUMNS): array
    {
        $bad = [];
        foreach ($findings as $f) {
            if (! in_array($f['value'], $allowed[$f['column']] ?? [], true)) {
                $bad[] = "{$f['file']}:{$f['line']} writes grid_orders.{$f['column']} = '{$f['value']}' ({$f['how']}) — not in GridOrder::" . strtoupper($f['column']) . 'S';
            }
        }

        return $bad;
    }

    /**
     * Replay migrations/*.php (filename order) and return the ENUM list each
     * column ends with.
     *
     * @return array<string, array<string, list<string>>> table => column => values
     */
    public static function declaredByMigrations(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
        sort($files);
        $schema = [];
        foreach ($files as $f) {
            $schema = self::applyMigration((string) file_get_contents($f), $schema);
        }

        return $schema;
    }

    /**
     * @param array<string, array<string, list<string>>> $schema
     * @return array<string, array<string, list<string>>>
     */
    public static function applyMigration(string $code, array $schema): array
    {
        $up = $code;
        if (($p = strpos($up, 'function up(')) !== false) {
            $up = substr($up, $p);
        }
        if (($p = strpos($up, 'function down(')) !== false) {
            $up = substr($up, 0, $p);
        }
        // "ALTER … " . "ENUM(…)" → one literal
        $joined = preg_replace('/["\']\s*\.\s*["\']/', '', $up);

        $events = [];

        // Blueprint ->enum('col', [...]) under the nearest preceding Schema::create/table('t').
        preg_match_all("/Schema::(?:create|table)\\(\\s*'(\\w+)'/", $up, $tables, PREG_OFFSET_CAPTURE);
        preg_match_all("/->enum\\(\\s*'(\\w+)'\\s*,\\s*\\[([^\\]]*)\\]/", $up, $enums, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($enums as $m) {
            $table = null;
            foreach ($tables[1] as $tm) {
                if ($tm[1] < $m[0][1]) {
                    $table = $tm[0];
                }
            }
            if ($table !== null) {
                $events[] = [$m[0][1], 'set', $table, $m[1][0], self::listOf($m[2][0])];
            }
        }

        preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+MODIFY(?:\s+COLUMN)?\s+`?(\w+)`?\s+ENUM\s*\(([^)]*)\)/i', $joined, $raw, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($raw as $m) {
            $events[] = [100000 + $m[0][1], 'set', $m[1][0], $m[2][0], self::listOf($m[3][0])];
        }

        preg_match_all("/->(addValues|setValues)\\(\\s*'(\\w+)'\\s*,\\s*'(\\w+)'\\s*,\\s*\\[([^\\]]*)\\]/", $up, $helper, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($helper as $m) {
            $events[] = [$m[0][1], $m[1][0] === 'addValues' ? 'add' : 'set', $m[2][0], $m[3][0], self::listOf($m[4][0])];
        }

        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($events as [, $op, $table, $column, $values]) {
            $current = $schema[$table][$column] ?? [];
            $schema[$table][$column] = $op === 'add'
                ? array_values(array_unique(array_merge($values, array_diff($current, $values))))
                : $values;
        }

        return $schema;
    }

    /**
     * Differences between what the code may write and what the migrations
     * allow, for the given tables.
     *
     * @param array<string, array<string, list<string>>> $expected table => column => values (code)
     * @param array<string, array<string, list<string>>> $declared table => column => values (migrations)
     * @return list<string>
     */
    public static function compare(array $expected, array $declared): array
    {
        $problems = [];
        foreach ($expected as $table => $columns) {
            foreach ($columns as $column => $values) {
                if (! isset($declared[$table][$column])) {
                    $problems[] = "{$table}.{$column}: the code lists values but no migration declares the ENUM";
                    continue;
                }
                $have = $declared[$table][$column];
                foreach (array_diff($values, $have) as $v) {
                    $problems[] = "{$table}.{$column}: '{$v}' is written by the code but no migration adds it to the ENUM";
                }
                foreach (array_diff($have, $values) as $v) {
                    $problems[] = "{$table}.{$column}: '{$v}' is allowed by the migrations but missing from the code constant";
                }
            }
            foreach (array_keys($declared[$table] ?? []) as $column) {
                if (! isset($columns[$column])) {
                    $problems[] = "{$table}.{$column}: ENUM declared by a migration but not covered by a code constant";
                }
            }
        }

        return $problems;
    }

    /** "'a', 'b'" → ['a', 'b'] */
    private static function listOf(string $s): array
    {
        preg_match_all("/'([^']*)'/", $s, $m);

        return $m[1];
    }

    /**
     * The call a '(' at $i opens, if it is a write method: receiver head and
     * whether that head can be a GridOrder.
     *
     * @return array{method:string, head:string, grid:bool}|null
     */
    private static function callAt(array $t, int $i): ?array
    {
        $name = $t[$i - 1] ?? null;
        if (! is_array($name) || $name[0] !== T_STRING || ! in_array(strtolower($name[1]), self::WRITE_METHODS, true)) {
            return null;
        }
        $op = $t[$i - 2] ?? null;
        if (! is_array($op) || ! in_array($op[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return null;   // a plain function call, not a model write
        }

        // Walk back over the chain to its head: X::a(...)->b(...)->update(
        $j = $i - 2;
        $head = null;
        $tableArg = null;
        while ($j > 0) {
            $tok = $t[$j];
            if (is_array($tok) && in_array($tok[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $j--;
                continue;
            }
            if ($tok === ')') {   // skip a balanced (...) argument list
                $depth = 0;
                for (; $j > 0; $j--) {
                    if ($t[$j] === ')') {
                        $depth++;
                    } elseif ($t[$j] === '(') {
                        $depth--;
                        if ($depth === 0) {
                            // DB::table('x') — remember the table name
                            if (is_array($t[$j - 1] ?? null) && strtolower($t[$j - 1][1]) === 'table' && is_array($t[$j + 1] ?? null) && $t[$j + 1][0] === T_CONSTANT_ENCAPSED_STRING) {
                                $tableArg = trim($t[$j + 1][1], "'\"");
                            }
                            break;
                        }
                    }
                }
                // (new X(...))->update( — the head is X
                if (is_array($t[$j + 1] ?? null) && $t[$j + 1][0] === T_NEW && is_array($t[$j + 2] ?? null)) {
                    $head = $t[$j + 2][1];
                    break;
                }
                $j--;
                continue;
            }
            if (is_array($tok) && in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC, T_VARIABLE], true)) {
                $prev = $t[$j - 1] ?? null;
                if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $j--;   // a method/property name in the middle of the chain
                    continue;
                }
                $head = $tok[1];
                break;
            }
            break;
        }

        if ($head === null) {
            return null;
        }
        $short = ltrim((string) preg_replace('/^.*\\\\/', '', $head), '\\');
        if ($short === 'DB') {
            $grid = $tableArg === 'grid_orders';
        } elseif ($head[0] === '$') {
            $grid = ! preg_match(self::OTHER_VARIABLE, $head);
        } else {
            $grid = ! in_array($short, self::OTHER_CLASSES, true);
        }

        return ['method' => $name[1], 'head' => $head, 'grid' => $grid];
    }

    /**
     * String literals (and GridOrder::CONSTANTs) of the expression starting at
     * $i, up to the next top-level ',', ';' or closing bracket.
     *
     * @return list<string>
     */
    private static function valuesOf(array $t, int $i): array
    {
        $vals = [];
        $depth = 0;
        for ($n = count($t); $i < $n; $i++) {
            $tok = $t[$i];
            if (in_array($tok, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($tok, [')', ']'], true)) {
                if ($depth-- === 0) {
                    break;
                }
            } elseif (($tok === ',' || $tok === ';') && $depth === 0) {
                break;
            } elseif ($depth === 0 && is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $vals[] = stripcslashes(substr($tok[1], 1, -1));
            } elseif ($depth === 0 && is_array($tok) && in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(^|\\\\)GridOrder$/', $tok[1]) && is_array($t[$i + 1] ?? null) && $t[$i + 1][0] === T_DOUBLE_COLON
                && is_array($t[$i + 2] ?? null) && $t[$i + 2][0] === T_STRING) {
                $const = GridOrder::class . '::' . $t[$i + 2][1];
                $vals[] = defined($const) && is_string(constant($const)) ? constant($const) : "<undefined {$t[$i + 2][1]}>";
                $i += 2;
            }
        }

        return $vals;
    }
}
