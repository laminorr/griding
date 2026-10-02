<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fee model Phase 3 — 2026_10_02_000002_add_fee_capture_columns_to_grid_orders_table
 * is additive (nullable), leaves existing rows NULL (not backfilled), and is
 * reversible.
 */
final class FeeCaptureColumnsMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_10_02_000002_add_fee_capture_columns_to_grid_orders_table.php';
    private const COLUMNS   = ['fee_amount', 'fee_currency', 'fee_asset', 'fee_source', 'fee_quote', 'avg_fill_price', 'net_base_delta'];

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
        Schema::create('grid_orders', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 20, 8);
        });
        DB::table('grid_orders')->insert(['amount' => '0.001']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('grid_orders');
        parent::tearDown();
    }

    public function test_up_is_additive_and_down_reverts(): void
    {
        $m = require database_path(self::MIGRATION);

        $m->up();
        $this->assertTrue(Schema::hasColumns('grid_orders', self::COLUMNS));
        $row = DB::table('grid_orders')->first();
        foreach (self::COLUMNS as $c) {
            $this->assertNull($row->{$c}, "{$c} must start NULL");
        }
        $m->up(); // idempotent

        $m->down();
        foreach (self::COLUMNS as $c) {
            $this->assertFalse(Schema::hasColumn('grid_orders', $c));
        }
        $this->assertSame(1, DB::table('grid_orders')->count());
    }
}
