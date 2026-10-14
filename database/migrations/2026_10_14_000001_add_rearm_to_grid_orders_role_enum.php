<?php

use App\Support\Schema\EnumColumns;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Hotfix — grid_orders.role is a real MySQL/MariaDB ENUM created with
     * ('initial_grid','cycle_exit','rebalance','manual') (2026_06_27_000002).
     * The classic-grid re-arm (2026_10_10_000001 / GridRearmer) writes
     * role = 'rearm', which production rejected with "Data truncated for
     * column 'role'" (SQLSTATE 01000 / 1265) on every attempt; the sqlite test
     * schema has no ENUM, so the suite never saw it.
     *
     * Adds 'rearm' and nothing else. EnumColumns rebuilds the rest of the
     * column definition (nullability, default, charset/collation, comment)
     * from information_schema at run time instead of restating a guess.
     * Idempotent (no-op when 'rearm' is already allowed); no-op on sqlite.
     */
    public function up(): void
    {
        app(EnumColumns::class)->addValues('grid_orders', 'role', ['initial_grid', 'cycle_exit', 'rebalance', 'manual', 'rearm']);
    }

    /**
     * Refuses while any row is a re-arm: MySQL would turn its role into ''
     * (or fail in strict mode). Checked on every driver.
     */
    public function down(): void
    {
        $enums = app(EnumColumns::class);
        $enums->assertUnused('grid_orders', 'role', 'rearm');
        $enums->setValues('grid_orders', 'role', ['initial_grid', 'cycle_exit', 'rebalance', 'manual']);
    }
};
