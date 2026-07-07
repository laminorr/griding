<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11, Step 1 — purely additive inventory tracking columns.
 *
 * These two columns are nullable and NOTHING reads or writes them yet, so this
 * migration introduces zero behaviour change to existing flows. They will be
 * populated by an observer in Step 2 and consumed for decisions (Kill Switch,
 * budget accounting) in later steps.
 *
 * - open_cycles_count: current number of open cycles (a cycle_exit sell order
 *   whose paired buy has filled but whose own fill has not yet completed —
 *   role = 'cycle_exit', status = 'placed'). Modelled as a nullable unsigned
 *   TINYINT: NULL means "not yet computed" (distinct from 0 = "computed and
 *   equals zero").
 * - capital_locked_irt: sum of notional (buy_price × amount) locked in those
 *   open cycle_exit sell orders — IRT capital that is not currently
 *   deployable. DECIMAL(20,0) mirrors the other IRT money columns in the
 *   schema (e.g. grid_orders.price, completed_trades.buy_price). NULL means
 *   "not yet computed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('open_cycles_count')->nullable()->default(null)->after('init_status');
            $table->decimal('capital_locked_irt', 20, 0)->nullable()->default(null)->after('open_cycles_count');
        });

        // Intentionally NO backfill. Existing rows stay NULL ("not yet
        // computed") and will be populated by the Step 2 observer.
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->dropColumn([
                'open_cycles_count',
                'capital_locked_irt',
            ]);
        });
    }
};
