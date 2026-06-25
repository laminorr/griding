<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The grid_orders.status column is a real MySQL ENUM, originally
     * created with only ['pending', 'placed', 'filled', 'cancelled']
     * (see 2025_07_24_215103_create_grid_orders_table.php). Application
     * code elsewhere already assigns 'failed' and 'partially_filled'
     * values that were never added to the schema, so this migration
     * also backfills those into the enum definition while adding the
     * new 'submission_unknown' value, to avoid leaving the column
     * silently mismatched with what the code actually writes.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE grid_orders MODIFY COLUMN status " .
            "ENUM('pending', 'placed', 'filled', 'cancelled', 'failed', 'partially_filled', 'submission_unknown') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE grid_orders MODIFY COLUMN status " .
            "ENUM('pending', 'placed', 'filled', 'cancelled', 'failed', 'partially_filled') NOT NULL"
        );
    }
};
