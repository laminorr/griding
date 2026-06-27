<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9, Step 2 — purely additive `role` column.
     *
     * `role` is a real MySQL ENUM (mirroring the convention used for the
     * `status` column) describing why an order exists. It is nullable
     * because existing rows have no role yet, and NOTHING reads or writes
     * it yet beyond the one-time historical backfill below, so this
     * migration introduces zero behaviour change to existing flows.
     *
     * Allowed values: 'initial_grid', 'cycle_exit', 'rebalance', 'manual'.
     */
    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->enum('role', ['initial_grid', 'cycle_exit', 'rebalance', 'manual'])
                ->nullable()
                ->after('paired_order_id');
        });

        // Best-effort historical backfill for pre-existing rows only.
        // This is a reasonable approximation, NOT an authoritative
        // classification: orders that have a paired_order_id were created
        // as the exit side of a cycle, while the rest are treated as the
        // initial grid. New code does not yet set this column at creation
        // time — that wiring is a later, separately-reviewed step.
        DB::statement("UPDATE grid_orders SET role = 'cycle_exit' WHERE paired_order_id IS NOT NULL");
        DB::statement("UPDATE grid_orders SET role = 'initial_grid' WHERE paired_order_id IS NULL");
    }

    public function down(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
