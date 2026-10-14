<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and widens MySQL/MariaDB ENUM columns without guessing their other
 * attributes.
 *
 * `ALTER TABLE … MODIFY` restates the WHOLE column definition, so a hard-coded
 * "ENUM(...) NULL" silently changes a NOT NULL column, drops its default or
 * resets its collation. modify() therefore reads the live column from
 * information_schema.COLUMNS and rebuilds charset, collation, nullability,
 * default and comment from what is there; only the value list changes.
 *
 * Every DDL method is a no-op off mysql/mariadb: sqlite (the test schema) has
 * no ENUM type and stores these columns as plain strings.
 *
 * Migrations call addValues()/setValues() with the value list written out
 * literally — Tests\Feature\Database\EnumDriftGuardTest reads those calls to
 * know the schema each migration declares.
 */
class EnumColumns
{
    public const MYSQL_DRIVERS = ['mysql', 'mariadb'];

    public function isMysql(): bool
    {
        return in_array($this->driver(), self::MYSQL_DRIVERS, true);
    }

    /**
     * Ensure every value in $values is allowed. Values already in the column
     * that are not in $values are kept (appended), never dropped.
     *
     * @param list<string> $values
     * @return bool true when the column was altered
     */
    public function addValues(string $table, string $column, array $values): bool
    {
        if (! $this->isMysql()) {
            return false;
        }

        $info    = $this->requireColumn($table, $column);
        $current = self::parseValues((string) $info->COLUMN_TYPE);
        if (array_diff($values, $current) === []) {
            return false;   // idempotent: already there
        }

        $target = array_values(array_unique(array_merge($values, array_diff($current, $values))));
        $this->modify($table, $column, $info, $target);

        return true;
    }

    /**
     * Set the exact value list (used by down() after assertUnused()).
     *
     * @param list<string> $values
     * @return bool true when the column was altered
     */
    public function setValues(string $table, string $column, array $values): bool
    {
        if (! $this->isMysql()) {
            return false;
        }

        $info = $this->requireColumn($table, $column);
        if (self::parseValues((string) $info->COLUMN_TYPE) === array_values($values)) {
            return false;
        }

        $this->modify($table, $column, $info, array_values($values));

        return true;
    }

    /**
     * Refuse (any driver) while a row still holds $value — removing it from the
     * ENUM would corrupt that row on MySQL.
     */
    public function assertUnused(string $table, string $column, string $value): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $n = DB::table($table)->where($column, $value)->count();
        if ($n > 0) {
            throw new \RuntimeException(sprintf(
                "Refusing to remove '%s' from %s.%s: %d row(s) still use it. Re-assign or delete those rows first.",
                $value, $table, $column, $n
            ));
        }
    }

    /**
     * Every ENUM column of $table on the live database: column => values.
     *
     * @return array<string, list<string>>
     */
    public function enumColumns(string $table): array
    {
        if (! $this->isMysql()) {
            return [];
        }

        $rows = $this->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE = 'enum' ORDER BY ORDINAL_POSITION",
            [$this->prefixed($table)]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->COLUMN_NAME] = self::parseValues((string) $r->COLUMN_TYPE);
        }

        return $out;
    }

    public function column(string $table, string $column): ?object
    {
        $rows = $this->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->prefixed($table), $column]
        );

        return $rows[0] ?? null;
    }

    /**
     * "enum('a','it''s')" → ['a', "it's"].
     *
     * @return list<string>
     */
    public static function parseValues(string $columnType): array
    {
        if (! preg_match('/^\s*enum\s*\((.*)\)\s*$/is', $columnType, $m)) {
            return [];
        }
        preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/s", $m[1], $vals);

        return array_map(
            fn (string $v) => str_replace(["''", "\\'", '\\\\'], ["'", "'", '\\'], $v),
            $vals[1]
        );
    }

    /**
     * Everything after the type: charset, collation, nullability, default and
     * comment, rebuilt from an information_schema.COLUMNS row. Handles both
     * MariaDB (COLUMN_DEFAULT 'NULL' / quoted literal) and MySQL (SQL NULL /
     * bare literal) spellings.
     */
    public function attributes(object $info): string
    {
        $sql = '';
        if (! empty($info->CHARACTER_SET_NAME)) {
            $sql .= ' CHARACTER SET ' . $info->CHARACTER_SET_NAME;
        }
        if (! empty($info->COLLATION_NAME)) {
            $sql .= ' COLLATE ' . $info->COLLATION_NAME;
        }

        $nullable = strtoupper((string) $info->IS_NULLABLE) === 'YES';
        $sql .= $nullable ? ' NULL' : ' NOT NULL';

        $default = $info->COLUMN_DEFAULT;
        if ($default === null || strtoupper((string) $default) === 'NULL') {
            if ($nullable) {
                $sql .= ' DEFAULT NULL';
            }
        } else {
            $default = (string) $default;
            if (strlen($default) >= 2 && $default[0] === "'" && str_ends_with($default, "'")) {
                $default = str_replace("''", "'", substr($default, 1, -1));
            }
            $sql .= ' DEFAULT ' . $this->quote($default);
        }

        if (($info->COLUMN_COMMENT ?? '') !== '') {
            $sql .= ' COMMENT ' . $this->quote((string) $info->COLUMN_COMMENT);
        }

        return $sql;
    }

    /** @param list<string> $values */
    public function enumType(array $values): string
    {
        return 'ENUM(' . implode(',', array_map(fn ($v) => $this->quote((string) $v), $values)) . ')';
    }

    /** @param list<string> $values */
    private function modify(string $table, string $column, object $info, array $values): void
    {
        $this->statement(sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `%s` %s%s',
            $this->prefixed($table), $column, $this->enumType($values), $this->attributes($info)
        ));
    }

    private function requireColumn(string $table, string $column): object
    {
        $info = $this->column($table, $column);
        if ($info === null) {
            throw new \RuntimeException("Column {$table}.{$column} not found in information_schema.COLUMNS");
        }
        if (! str_starts_with(strtolower((string) $info->COLUMN_TYPE), 'enum(')) {
            throw new \RuntimeException("Column {$table}.{$column} is not an ENUM ({$info->COLUMN_TYPE})");
        }

        return $info;
    }

    private function prefixed(string $table): string
    {
        return DB::connection()->getTablePrefix() . $table;
    }

    // ---- Seams (overridden in tests) --------------------------------------

    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** @return list<object> */
    protected function select(string $sql, array $bindings): array
    {
        return DB::select($sql, $bindings);
    }

    protected function statement(string $sql): void
    {
        DB::statement($sql);
    }

    protected function quote(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }
}
