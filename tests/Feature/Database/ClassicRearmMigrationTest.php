<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Classic-grid re-arm columns are additive, default OFF and reversible. */
final class ClassicRearmMigrationTest extends TestCase
{
    private const ORDER_COLUMNS = ['rearm_order_id', 'rearm_state', 'rearm_exit_order_id', 'rearm_root_order_id', 'grid_generation'];

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
        Schema::dropIfExists('bot_configs');
        Schema::create('bot_configs', fn (Blueprint $t) => $t->id());
        Schema::create('grid_orders', fn (Blueprint $t) => $t->id());
        DB::table('bot_configs')->insert(['id' => 48]);
        DB::table('grid_orders')->insert([['id' => 1], ['id' => 2]]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('grid_orders');
        Schema::dropIfExists('bot_configs');
        parent::tearDown();
    }

    public function test_up_and_down(): void
    {
        $m = require database_path('migrations/2026_10_10_000001_add_classic_rearm_columns.php');
        $m->up();

        $this->assertTrue(Schema::hasColumns('bot_configs', ['rearm_exits', 'grid_generation']));
        $this->assertTrue(Schema::hasColumns('grid_orders', self::ORDER_COLUMNS));
        // No existing bot is switched on.
        $this->assertSame(0, (int) DB::table('bot_configs')->where('id', 48)->value('rearm_exits'));
        $this->assertSame(0, (int) DB::table('bot_configs')->where('id', 48)->value('grid_generation'));
        $this->assertNull(DB::table('grid_orders')->where('id', 1)->value('rearm_order_id'));
        $this->assertNull(DB::table('grid_orders')->where('id', 1)->value('grid_generation'));

        // One exit → one re-arm, at the database level (NULLs are not unique).
        DB::table('grid_orders')->where('id', 1)->update(['rearm_exit_order_id' => 9]);
        try {
            DB::table('grid_orders')->where('id', 2)->update(['rearm_exit_order_id' => 9]);
            $this->fail('rearm_exit_order_id must be unique');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }

        $m->up();   // idempotent
        $m->down();
        foreach (self::ORDER_COLUMNS as $c) {
            $this->assertFalse(Schema::hasColumn('grid_orders', $c));
        }
        $this->assertFalse(Schema::hasColumn('bot_configs', 'rearm_exits'));
        $this->assertFalse(Schema::hasColumn('bot_configs', 'grid_generation'));
        $this->assertSame(2, DB::table('grid_orders')->count());
    }
}
