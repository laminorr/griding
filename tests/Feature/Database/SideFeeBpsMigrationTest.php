<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fee model Phase 1 — 2026_10_02_000001_add_side_fee_bps_to_bot_configs_table
 * is additive (nullable, default NULL), leaves existing rows untouched, and is
 * reversible. Driven against a minimal sqlite table (same harness approach as
 * StopLossPercentDefaultMigrationTest).
 */
final class SideFeeBpsMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_10_02_000001_add_side_fee_bps_to_bot_configs_table.php';

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

        Schema::dropIfExists('bot_configs');
        Schema::create('bot_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fee_bps')->default(35);
        });
        DB::table('bot_configs')->insert([['fee_bps' => 35], ['fee_bps' => 35]]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bot_configs');
        parent::tearDown();
    }

    public function test_up_adds_nullable_columns_without_rewriting_rows_and_down_reverts(): void
    {
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $this->assertTrue(Schema::hasColumns('bot_configs', ['buy_fee_bps', 'sell_fee_bps']));
        foreach (DB::table('bot_configs')->get() as $row) {
            $this->assertNull($row->buy_fee_bps);
            $this->assertNull($row->sell_fee_bps);
            $this->assertEquals(35, $row->fee_bps); // legacy column untouched
        }

        // Re-running up() is a no-op (guarded by hasColumn).
        $migration->up();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('bot_configs', 'buy_fee_bps'));
        $this->assertFalse(Schema::hasColumn('bot_configs', 'sell_fee_bps'));
        $this->assertTrue(Schema::hasColumn('bot_configs', 'fee_bps'));
        $this->assertSame(2, DB::table('bot_configs')->count());
    }
}
