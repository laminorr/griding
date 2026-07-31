<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cleanup 6a, Fix 2(a): the new migration
 * 2026_07_31_000001_change_stop_loss_percent_default_on_bot_configs_table
 * aligns the bot_configs.stop_loss_percent DB default with
 * config('trading.grid.default_stop_loss_percent') (=15), up from the
 * historical 5.
 *
 * HARNESS NOTE — why this test does NOT use BotConfigFactory / BuildsGridSchema:
 * the project's RefreshDatabase tier builds bot_configs from the hand-written
 * BuildsGridSchema trait (which still declares DEFAULT 5, deliberately — the
 * KillSwitchService characterization note pins that harness fact), and
 * BotConfigFactory always writes stop_loss_percent explicitly. So the DB default
 * is NOT observable through that harness. This test therefore drives the REAL
 * migration file directly against a minimal sqlite table that mirrors the
 * historical column, and asserts the default flips from 5 to 15 while the column
 * stays NOT NULL.
 */
final class StopLossPercentDefaultMigrationTest extends TestCase
{
    private const MIGRATION =
        'migrations/2026_07_31_000001_change_stop_loss_percent_default_on_bot_configs_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        // config/database.php hard-codes the sqlite connection to a file path and
        // ignores DB_DATABASE, so the phpunit.xml :memory: setting never reaches
        // it. Force an in-memory, FK-free connection (same override the shared
        // BuildsGridSchema trait uses) so this migration test has a real DB.
        config([
            'database.default'                                    => 'sqlite',
            'database.connections.sqlite.database'                => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // Rebuild a minimal bot_configs mirroring the historical column shape:
        // DECIMAL(5,2) NOT NULL DEFAULT 5 (2025_07_24_214742 create migration).
        Schema::dropIfExists('bot_configs');
        Schema::create('bot_configs', function (Blueprint $table) {
            $table->id();
            $table->decimal('stop_loss_percent', 5, 2)->default(5);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bot_configs');
        parent::tearDown();
    }

    public function test_default_is_5_before_the_migration(): void
    {
        DB::statement('INSERT INTO bot_configs DEFAULT VALUES');

        $value = DB::table('bot_configs')->orderByDesc('id')->value('stop_loss_percent');

        $this->assertEquals(5.0, (float) $value);
    }

    public function test_migration_changes_the_default_to_15(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        // A fresh row inserted with no explicit stop_loss now gets 15.
        DB::statement('INSERT INTO bot_configs DEFAULT VALUES');
        $after = DB::table('bot_configs')->orderByDesc('id')->value('stop_loss_percent');

        $this->assertEquals(15.0, (float) $after);
    }

    public function test_migration_keeps_the_column_not_null(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        // NOT NULL survives the ->change(): an explicit null is rejected by sqlite.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('bot_configs')->insert(['stop_loss_percent' => null]);
    }

    public function test_down_restores_the_original_default_of_5(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->down();

        DB::statement('INSERT INTO bot_configs DEFAULT VALUES');
        $restored = DB::table('bot_configs')->orderByDesc('id')->value('stop_loss_percent');

        $this->assertEquals(5.0, (float) $restored);
    }

    /**
     * The migration exists to make the DB agree with config, so pin that the
     * config target is still 15 (a drift here would silently re-open the gap).
     */
    public function test_config_default_stop_loss_is_15(): void
    {
        $this->assertEquals(15.0, (float) config('trading.grid.default_stop_loss_percent'));
    }
}
