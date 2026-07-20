<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 12 Step 7 — purely additive reconciliation-tracking columns.
     *
     * ⚠ Requires `php artisan migrate` on the host.
     *
     * - reconcile_attempts: how many reconciler runs have examined the row;
     *   feeds the max_attempts escalation threshold.
     * - reconcile_not_found_count: consecutive runs on which Nobitex answered
     *   NotFound for the row's clientOrderId with no matching open order —
     *   the row is only resolved to 'cancelled' once this reaches
     *   config('trading.reconcile.not_found_confirmations').
     * - reconcile_last_attempt_at: operator visibility (grid:reconcile-submissions --list).
     *
     * All columns default to 0/NULL, so existing rows and every existing
     * write path are unaffected.
     */
    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->unsignedInteger('reconcile_attempts')->default(0)->after('role');
            $table->unsignedInteger('reconcile_not_found_count')->default(0)->after('reconcile_attempts');
            $table->timestamp('reconcile_last_attempt_at')->nullable()->after('reconcile_not_found_count');
        });
    }

    public function down(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropColumn([
                'reconcile_attempts',
                'reconcile_not_found_count',
                'reconcile_last_attempt_at',
            ]);
        });
    }
};
