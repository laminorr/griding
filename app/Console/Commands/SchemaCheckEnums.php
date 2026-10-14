<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Schema\EnumColumns;
use App\Support\Schema\EnumRegistry;
use Illuminate\Console\Command;

/**
 * Read-only: compare every ENUM column of grid_orders / bot_configs /
 * completed_trades on the live database (information_schema.COLUMNS) with the
 * values the code may write (App\Support\Schema\EnumRegistry).
 *
 *   OK        — the column allows exactly the code's values
 *   MISSING   — the code writes a value the column rejects  → exit 1
 *   EXTRA     — the column allows a value the code never writes (harmless)
 *   ABSENT    — the code expects an ENUM column that is not one → exit 1
 *   UNTRACKED — an ENUM column no code constant covers (check by hand)
 *
 * Run on the host after every deploy that touches an ENUM.
 */
class SchemaCheckEnums extends Command
{
    protected $signature = 'schema:check-enums';

    protected $description = 'Compare the live ENUM columns with the values the code writes (read-only)';

    public function handle(EnumColumns $enums): int
    {
        if (! $enums->isMysql()) {
            $this->warn('Connection driver is not mysql/mariadb — no ENUM columns to check (sqlite stores them as strings).');
            return self::SUCCESS;
        }

        $rows = [];
        $fail = false;
        foreach (EnumRegistry::expected() as $table => $expected) {
            $live = $enums->enumColumns($table);

            foreach ($expected as $column => $values) {
                if (! array_key_exists($column, $live)) {
                    $rows[] = ["{$table}.{$column}", 'ABSENT', 'not an ENUM column on this database', ''];
                    $fail = true;
                    continue;
                }
                $missing = array_values(array_diff($values, $live[$column]));
                $extra   = array_values(array_diff($live[$column], $values));
                if ($missing !== []) {
                    $fail = true;
                }
                $status = $missing !== [] ? 'MISSING' : ($extra !== [] ? 'EXTRA' : 'OK');
                $rows[] = ["{$table}.{$column}", $status,
                    $missing !== [] ? 'missing: ' . implode(', ', $missing) : ($extra !== [] ? 'extra: ' . implode(', ', $extra) : ''),
                    implode(',', $live[$column])];
            }

            foreach (array_diff_key($live, $expected) as $column => $values) {
                $rows[] = ["{$table}.{$column}", 'UNTRACKED', 'ENUM with no code constant', implode(',', $values)];
            }
            if ($live === [] && $expected === []) {
                $rows[] = [$table, 'OK', 'no ENUM columns', ''];
            }
        }

        $this->table(['column', 'status', 'detail', 'live values'], $rows);

        if ($fail) {
            $this->error('ENUM drift: the code writes values the database rejects. Run the pending migrations (php artisan migrate) and re-check.');
            return self::FAILURE;
        }
        $this->info('All ENUM columns accept every value the code writes.');

        return self::SUCCESS;
    }
}
