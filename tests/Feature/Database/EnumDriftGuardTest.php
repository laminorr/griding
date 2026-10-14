<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\GridOrder;
use App\Support\Schema\EnumRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnumDrift;
use Tests\TestCase;

/**
 * sqlite (the test DB) has no ENUM, so "the code writes a value the MySQL
 * column rejects" can only be caught statically. This guard holds three
 * things equal on every CI run:
 *
 *   1. every role/status/type literal written to grid_orders in app/
 *      ⊆ GridOrder::ROLES / STATUSES / TYPES;
 *   2. those constants == the ENUM lists the migrations leave on the columns
 *      (replayed from database/migrations in filename order);
 *   3. bot_configs / completed_trades declare no ENUM no constant covers.
 *
 * Why parse the migrations instead of letting them read GridOrder::ROLES: a
 * migration is a frozen snapshot — if it read a live constant, adding a value
 * to the constant would silently change what an ALREADY-RUN migration means
 * and would never reach a database that has run it. A new value therefore
 * needs a new migration that spells the list out, and (2) fails until it
 * exists. The model-level guard (GridOrder::saving) also refuses unknown
 * values at runtime, on sqlite as on MySQL.
 */
final class EnumDriftGuardTest extends TestCase
{
    public function test_app_writes_only_values_in_the_constants(): void
    {
        $findings = EnumDrift::scanDirectory(app_path());

        $this->assertSame([], EnumDrift::violations($findings));

        // The scanner really sees the writers (no vacuous pass).
        $seen = array_map(fn ($f) => "{$f['column']}={$f['value']}", $findings);
        foreach (['role=rearm', 'role=cycle_exit', 'role=initial_grid', 'role=rebalance', 'status=pending', 'status=placed',
                  'status=filled', 'status=cancelled', 'status=partially_filled', 'status=submission_unknown', 'type=buy', 'type=sell'] as $want) {
            $this->assertContains($want, $seen, "scanner no longer finds a {$want} write");
        }
        $files = array_unique(array_column($findings, 'file'));
        foreach (['app/Services/GridRearmer.php', 'app/Jobs/CheckTradesJob.php', 'app/Services/GridOrderExecutor.php', 'app/Services/SubmissionReconciler.php'] as $f) {
            $this->assertContains($f, $files);
        }
    }

    public function test_constants_equal_the_enums_declared_by_the_migrations(): void
    {
        $declared = EnumDrift::declaredByMigrations(database_path('migrations'));

        $this->assertSame([], EnumDrift::compare(EnumRegistry::expected(), $declared));
        $this->assertSame(GridOrder::ROLES, $declared['grid_orders']['role']);
        $this->assertSame(['bot_configs', 'completed_trades'], array_values(array_intersect(['bot_configs', 'completed_trades'], array_keys(EnumRegistry::expected()))));
        $this->assertArrayNotHasKey('bot_configs', $declared, 'bot_configs gained an ENUM: add its constant to EnumRegistry');
        $this->assertArrayNotHasKey('completed_trades', $declared, 'completed_trades gained an ENUM: add its constant to EnumRegistry');
    }

    /** The incident itself: without the hotfix migration, 'rearm' is caught. */
    public function test_without_the_hotfix_migration_the_guard_reports_rearm(): void
    {
        $schema = [];
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        foreach ($files as $f) {
            if (! str_contains($f, '2026_10_14_000001_add_rearm_to_grid_orders_role_enum')) {
                $schema = EnumDrift::applyMigration((string) file_get_contents($f), $schema);
            }
        }

        $this->assertSame(
            ["grid_orders.role: 'rearm' is written by the code but no migration adds it to the ENUM"],
            EnumDrift::compare(EnumRegistry::expected(), $schema)
        );
    }

    public function test_a_new_constant_value_without_a_migration_fails_and_a_migration_fixes_it(): void
    {
        $declared = EnumDrift::declaredByMigrations(database_path('migrations'));
        $expected = EnumRegistry::expected();
        $expected['grid_orders']['role'][] = 'hedge';

        $this->assertSame(["grid_orders.role: 'hedge' is written by the code but no migration adds it to the ENUM"], EnumDrift::compare($expected, $declared));

        // The fix: a migration that adds it.
        $fixture = <<<'PHP'
        <?php
        return new class extends Migration {
            public function up(): void
            {
                app(EnumColumns::class)->addValues('grid_orders', 'role', ['initial_grid', 'cycle_exit', 'rebalance', 'manual', 'rearm', 'hedge']);
            }
            public function down(): void
            {
                app(EnumColumns::class)->setValues('grid_orders', 'role', ['initial_grid', 'cycle_exit', 'rebalance', 'manual', 'rearm']);
            }
        };
        PHP;
        $this->assertSame([], EnumDrift::compare($expected, EnumDrift::applyMigration($fixture, $declared)));

        // And the reverse drift: the migrations allow a value no constant lists.
        $this->assertSame(
            ["grid_orders.role: 'hedge' is allowed by the migrations but missing from the code constant"],
            EnumDrift::compare(EnumRegistry::expected(), EnumDrift::applyMigration($fixture, $declared))
        );
    }

    public function test_raw_and_blueprint_migration_forms_are_parsed(): void
    {
        $schema = EnumDrift::applyMigration(<<<'PHP'
        <?php
        public function up(): void {
            Schema::create('widgets', function (Blueprint $t) { $t->enum('kind', ['a', 'b'])->nullable(); });
            DB::statement("ALTER TABLE widgets MODIFY COLUMN state " .
                "ENUM('x', 'y') NOT NULL");
        }
        public function down(): void {
            DB::statement("ALTER TABLE widgets MODIFY COLUMN state ENUM('x') NOT NULL");
        }
        PHP, []);
        $this->assertSame(['widgets' => ['kind' => ['a', 'b'], 'state' => ['x', 'y']]], $schema);
    }

    /** @return array<string, array{0:string}> */
    public static function forbiddenWrites(): array
    {
        return [
            'create role'          => ["GridOrder::create(['bot_config_id' => 1, 'role' => 'hedge']);"],
            'createIntent type'    => ["GridOrder::createIntent(['type' => 'short', 'status' => 'pending']);"],
            'update status'        => ["\$order->update(['status' => 'expired']);"],
            'forceFill ternary'    => ["\$row->forceFill(['status' => \$ok ? 'placed' : 'lost'])->save();"],
            'query-builder update' => ["GridOrder::where('id', 1)->update(['role' => 'hedge']);"],
            'DB table insert'      => ["DB::table('grid_orders')->insert([['role' => 'hedge'], ['role' => 'manual']]);"],
            'new model forceFill'  => ["(new GridOrder())->forceFill(['type' => 'short']);"],
            'named argument'       => ["\$exec->applyForBot(1, \$diff, simulation: true, role: 'hedge');"],
            'property assignment'  => ["\$order->role = 'hedge';"],
            'undefined constant'   => ["\$order->update(['role' => GridOrder::ROLE_HEDGE]);"],
        ];
    }

    #[DataProvider('forbiddenWrites')]
    public function test_a_new_literal_without_updating_the_constants_fails(string $code): void
    {
        $bad = EnumDrift::violations(EnumDrift::scanSource("<?php\n" . $code, 'fixture.php'));

        $this->assertCount(1, $bad, implode("\n", $bad));
        $this->assertStringStartsWith('fixture.php:2 writes grid_orders.', $bad[0]);
    }

    /** @return array<string, array{0:string}> */
    public static function unrelatedWrites(): array
    {
        return [
            'other model'        => ["BotConfig::create(['status' => 'running', 'type' => 'x']);"],
            'bot variable'       => ["\$bot->update(['status' => 'stopped']);"],
            'other table'        => ["DB::table('bot_configs')->update(['status' => 'x']);"],
            'plain array'        => ["return ['status' => 'ok', 'type' => 'connect'];"],
            'nested value array' => ["\$order->update(['raw_json' => ['status' => 'open'], 'status' => 'placed']);"],
            'constant value'     => ["\$order->update(['role' => GridOrder::ROLE_REARM, 'status' => GridOrder::STATUS_PLACED]);"],
            'a read'             => ["GridOrder::where('status', 'active')->whereIn('role', ['x'])->get();"],
        ];
    }

    #[DataProvider('unrelatedWrites')]
    public function test_unrelated_code_is_not_flagged(string $code): void
    {
        $this->assertSame([], EnumDrift::violations(EnumDrift::scanSource("<?php\n" . $code, 'fixture.php')));
    }

    public function test_the_model_refuses_an_unknown_value_on_sqlite_as_mysql_would(): void
    {
        $o = new GridOrder(['bot_config_id' => 1, 'price' => '100', 'amount' => '1', 'type' => 'buy', 'status' => 'pending', 'role' => 'hedge']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("GridOrder::role 'hedge' is not an allowed value");
        $o->save();
    }
}
