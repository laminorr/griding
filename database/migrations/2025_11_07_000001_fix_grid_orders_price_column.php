<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Raw MySQL-only DDL (MODIFY COLUMN / ADD CONSTRAINT). sqlite — used by
        // the fresh test schema (RefreshDatabase) — supports neither and would
        // abort the replay, so no-op there. The create migration already made
        // `price` a decimal(20,0); sqlite is dynamically typed regardless.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Ensure price column is DECIMAL(20,0) with no decimal places
        DB::statement('ALTER TABLE grid_orders MODIFY COLUMN price DECIMAL(20,0) NOT NULL');

        // Add check constraint to ensure positive prices
        try {
            DB::statement('ALTER TABLE grid_orders ADD CONSTRAINT chk_price_positive CHECK (price > 0)');
        } catch (\Exception $e) {
            // Constraint might already exist or MySQL version doesn't support CHECK
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE grid_orders DROP CONSTRAINT IF EXISTS chk_price_positive');
        // Don't revert column type - keep decimal(20,0)
    }
};
