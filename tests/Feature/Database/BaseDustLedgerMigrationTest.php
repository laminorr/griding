<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Fee model Phase 4 — 2026_10_02_000003_add_base_dust_ledger is additive and reversible. */
final class BaseDustLedgerMigrationTest extends TestCase
{
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
        Schema::dropIfExists('grid_orders');
        Schema::create('bot_configs', fn (Blueprint $t) => $t->id());
        Schema::create('grid_orders', fn (Blueprint $t) => $t->id());
        DB::table('bot_configs')->insert(['id' => 1]);
        DB::table('grid_orders')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bot_configs');
        Schema::dropIfExists('grid_orders');
        parent::tearDown();
    }

    public function test_up_defaults_existing_bots_to_zero_dust_and_down_reverts(): void
    {
        $m = require database_path('migrations/2026_10_02_000003_add_base_dust_ledger.php');

        $m->up();
        $this->assertEquals(0, DB::table('bot_configs')->value('base_dust'));
        $this->assertNull(DB::table('grid_orders')->value('exit_dust_delta'));
        $m->up(); // idempotent

        $m->down();
        $this->assertFalse(Schema::hasColumn('bot_configs', 'base_dust'));
        $this->assertFalse(Schema::hasColumn('grid_orders', 'exit_dust_delta'));
    }
}
