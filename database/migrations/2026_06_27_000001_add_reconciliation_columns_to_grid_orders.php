<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9, Step 1 — purely additive reconciliation columns.
     *
     * These columns are nullable and NOTHING reads or writes them yet
     * (beyond the one-time historical backfill of original_amount below),
     * so this migration introduces zero behaviour change to existing flows.
     *
     * - original_amount / filled_amount / remaining_amount mirror the
     *   existing `amount` column type: DECIMAL(20,8).
     * - average_fill_price mirrors the existing `price` column type:
     *   DECIMAL(20,0).
     */
    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->decimal('original_amount', 20, 8)->nullable()->after('amount');
            $table->decimal('filled_amount', 20, 8)->nullable()->after('original_amount');
            $table->decimal('remaining_amount', 20, 8)->nullable()->after('filled_amount');
            $table->decimal('average_fill_price', 20, 0)->nullable()->after('remaining_amount');
            $table->timestamp('last_fill_at')->nullable()->after('average_fill_price');
        });

        // Backfill original_amount = amount for all existing rows so that
        // pre-existing orders retain their originally requested amount.
        // filled_amount / remaining_amount / average_fill_price / last_fill_at
        // are intentionally left NULL for existing rows.
        DB::statement('UPDATE grid_orders SET original_amount = amount');
    }

    public function down(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropColumn([
                'original_amount',
                'filled_amount',
                'remaining_amount',
                'average_fill_price',
                'last_fill_at',
            ]);
        });
    }
};
