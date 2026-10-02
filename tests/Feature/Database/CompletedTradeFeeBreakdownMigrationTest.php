<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Fee model Phase 6 — completed_trades breakdown columns are additive (existing rows untouched) and reversible. */
final class CompletedTradeFeeBreakdownMigrationTest extends TestCase
{
    private const COLUMNS = [
        'buy_fee_amount', 'buy_fee_currency', 'buy_fee_quote', 'sell_fee_amount', 'sell_fee_currency', 'sell_fee_quote',
        'fee_source', 'buy_filled_amount', 'sell_filled_amount', 'base_residual', 'fee_model_version', 'profit_v0', 'net_profit_v0',
    ];

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
        Schema::dropIfExists('completed_trades');
        Schema::create('completed_trades', function (Blueprint $t) {
            $t->id();
            $t->decimal('profit', 20, 0);
        });
        DB::table('completed_trades')->insert(['profit' => 9934380]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('completed_trades');
        parent::tearDown();
    }

    public function test_up_and_down(): void
    {
        $m = require database_path('migrations/2026_10_02_000005_add_fee_breakdown_to_completed_trades_table.php');
        $m->up();
        $this->assertTrue(Schema::hasColumns('completed_trades', self::COLUMNS));
        $row = DB::table('completed_trades')->first();
        $this->assertEquals(9934380, $row->profit);
        $this->assertNull($row->fee_model_version);
        $this->assertNull($row->profit_v0);
        $m->up();
        $m->down();
        foreach (self::COLUMNS as $c) {
            $this->assertFalse(Schema::hasColumn('completed_trades', $c));
        }
    }
}
