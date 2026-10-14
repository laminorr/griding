<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Schema\EnumColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_10_14_000001 adds 'rearm' to the grid_orders.role ENUM.
 *
 * The sqlite cases run the migration for real. The MySQL/MariaDB DDL path is
 * exercised through EnumColumns' seams (driver, information_schema read,
 * statement, quoting) against an in-memory column, so the exact ALTER, its
 * idempotence and the preserved attributes are asserted without a server.
 */
final class RearmRoleEnumMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_10_14_000001_add_rearm_to_grid_orders_role_enum.php';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'                                    => 'sqlite',
            'database.connections.sqlite.database'                => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::dropIfExists('grid_orders');
        Schema::create('grid_orders', function (Blueprint $t) {
            $t->id();
            $t->string('role')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('grid_orders');
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** EnumColumns on a fake MySQL/MariaDB column; records every statement. */
    private function fakeMysql(array $column): object
    {
        $fake = new class($column) extends EnumColumns {
            public array $statements = [];

            public function __construct(public array $column) {}

            protected function driver(): string
            {
                return 'mariadb';
            }

            protected function select(string $sql, array $bindings): array
            {
                return str_contains($sql, 'COLUMN_NAME = ?') && $bindings === ['grid_orders', 'role'] ? [(object) $this->column] : [];
            }

            protected function statement(string $sql): void
            {
                $this->statements[] = $sql;
                // Apply the new type, as the server would.
                preg_match('/MODIFY COLUMN `role` (ENUM\(.*?\))( CHARACTER| NULL| NOT)/', $sql, $m);
                $this->column['COLUMN_TYPE'] = strtolower(substr($m[1], 0, 4)) . substr($m[1], 4);
            }

            protected function quote(string $value): string
            {
                return "'" . str_replace("'", "''", $value) . "'";
            }
        };
        $this->app->instance(EnumColumns::class, $fake);

        return $fake;
    }

    private const MARIADB_ROLE = [
        'COLUMN_NAME' => 'role',
        'COLUMN_TYPE' => "enum('initial_grid','cycle_exit','rebalance','manual')",
        'IS_NULLABLE' => 'YES',
        'COLUMN_DEFAULT' => 'NULL',              // MariaDB spells "no default" like this
        'CHARACTER_SET_NAME' => 'utf8mb4',
        'COLLATION_NAME' => 'utf8mb4_unicode_ci',
        'COLUMN_COMMENT' => '',
    ];

    public function test_sqlite_is_a_no_op_and_down_refuses_while_a_rearm_row_exists(): void
    {
        DB::table('grid_orders')->insert([['role' => 'cycle_exit'], ['role' => 'rearm']]);
        $m = $this->migration();

        $m->up();
        $m->up();
        $this->assertSame(['cycle_exit', 'rearm'], DB::table('grid_orders')->orderBy('id')->pluck('role')->all());

        try {
            $m->down();
            $this->fail('down() must refuse while a rearm row exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Refusing to remove 'rearm' from grid_orders.role: 1 row(s) still use it", $e->getMessage());
        }
        $this->assertSame(2, DB::table('grid_orders')->count());

        DB::table('grid_orders')->where('role', 'rearm')->delete();
        $m->down();   // allowed now; no-op DDL on sqlite
        $this->assertSame(['cycle_exit'], DB::table('grid_orders')->pluck('role')->all());
    }

    public function test_mysql_adds_rearm_once_and_preserves_the_column_attributes(): void
    {
        $fake = $this->fakeMysql(self::MARIADB_ROLE);
        $m = $this->migration();

        $m->up();
        $this->assertSame([
            "ALTER TABLE `grid_orders` MODIFY COLUMN `role` ENUM('initial_grid','cycle_exit','rebalance','manual','rearm')"
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
        ], $fake->statements);

        $m->up();   // idempotent: 'rearm' already there
        $m->up();
        $this->assertCount(1, $fake->statements);

        // down() without rearm rows restores the old list, attributes intact.
        $m->down();
        $this->assertCount(2, $fake->statements);
        $this->assertSame(
            "ALTER TABLE `grid_orders` MODIFY COLUMN `role` ENUM('initial_grid','cycle_exit','rebalance','manual')"
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
            $fake->statements[1]
        );
        $m->down();
        $this->assertCount(2, $fake->statements, 'down() is idempotent too');
    }

    public function test_mysql_down_refuses_while_a_rearm_row_exists_and_alters_nothing(): void
    {
        $fake = $this->fakeMysql(['COLUMN_TYPE' => "enum('initial_grid','cycle_exit','rebalance','manual','rearm')"] + self::MARIADB_ROLE);
        DB::table('grid_orders')->insert(['role' => 'rearm']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Refusing to remove 'rearm'");
        try {
            $this->migration()->down();
        } finally {
            $this->assertSame([], $fake->statements);
        }
    }

    public function test_a_not_null_column_with_a_default_keeps_both(): void
    {
        // MySQL 8 spelling: SQL NULL for "no default", bare literal otherwise.
        $fake = $this->fakeMysql([
            'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 'manual', 'COLUMN_COMMENT' => "why it's here",
            'CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'utf8mb4_bin',
        ] + self::MARIADB_ROLE);
        $this->migration()->up();
        $this->assertStringEndsWith(
            " CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'manual' COMMENT 'why it''s here'",
            $fake->statements[0]
        );

        // MariaDB spelling of the same default: quoted.
        $fake = $this->fakeMysql(['IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => "'manual'"] + self::MARIADB_ROLE);
        $this->migration()->up();
        $this->assertStringEndsWith(" NOT NULL DEFAULT 'manual'", $fake->statements[0]);

        // NOT NULL without a default: no DEFAULT clause invented.
        $fake = $this->fakeMysql(['IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null] + self::MARIADB_ROLE);
        $this->migration()->up();
        $this->assertStringEndsWith('utf8mb4_unicode_ci NOT NULL', $fake->statements[0]);
    }

    public function test_extra_live_values_are_kept_and_a_missing_column_is_an_error(): void
    {
        $fake = $this->fakeMysql(['COLUMN_TYPE' => "enum('initial_grid','cycle_exit','rebalance','manual','legacy')"] + self::MARIADB_ROLE);
        $this->migration()->up();
        $this->assertStringContainsString("ENUM('initial_grid','cycle_exit','rebalance','manual','rearm','legacy')", $fake->statements[0]);

        $this->fakeMysql(['COLUMN_TYPE' => 'varchar(32)'] + self::MARIADB_ROLE);
        $this->expectExceptionMessage('is not an ENUM');
        $this->migration()->up();
    }

    public function test_parse_values(): void
    {
        $this->assertSame(['a', "it's", 'b c'], EnumColumns::parseValues("enum('a','it''s','b c')"));
        $this->assertSame(['buy', 'sell'], EnumColumns::parseValues("ENUM('buy', 'sell')"));
        $this->assertSame([], EnumColumns::parseValues('varchar(16)'));
    }
}
