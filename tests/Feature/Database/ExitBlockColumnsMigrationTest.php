<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Fee model Phase 5 — rejection / exit-block columns are additive and reversible. */
final class ExitBlockColumnsMigrationTest extends TestCase
{
    private const COLUMNS = ['last_error_code', 'last_error_message', 'exit_state', 'exit_blocked_reason', 'exit_blocked_at'];

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
        Schema::create('grid_orders', fn (Blueprint $t) => $t->id());
        DB::table('grid_orders')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('grid_orders');
        parent::tearDown();
    }

    public function test_up_and_down(): void
    {
        $m = require database_path('migrations/2026_10_02_000004_add_rejection_and_exit_block_columns_to_grid_orders.php');
        $m->up();
        $this->assertTrue(Schema::hasColumns('grid_orders', self::COLUMNS));
        $this->assertNull(DB::table('grid_orders')->value('exit_state'));
        $m->up();
        $m->down();
        foreach (self::COLUMNS as $c) {
            $this->assertFalse(Schema::hasColumn('grid_orders', $c));
        }
        $this->assertSame(1, DB::table('grid_orders')->count());
    }
}
