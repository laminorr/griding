<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11, Step 3 — Kill Switch reference price.
 *
 * Adds a single nullable column, grid_center_price, that captures the mid
 * price used when the initial grid was planned (TradingEngineService::
 * initializeGrid). This is deliberately a SEPARATE column from the existing
 * `center_price`:
 *
 *   - `center_price` is a MOVING target. It is recomputed on every
 *     (re)initialization as a weighted blend of the live price and the prior
 *     center (calculateOptimalCenterPrice), so it drifts with the market and
 *     is unsuitable as a stable stop-loss anchor.
 *   - `grid_center_price` is the STABLE anchor the Kill Switch measures the
 *     stop-loss distance against: abs((current - grid_center_price) /
 *     grid_center_price * 100) > stop_loss_percent triggers.
 *
 * DECIMAL(20,0) mirrors the other IRT money columns in the schema
 * (grid_orders.price, completed_trades.buy_price, bot_configs.capital_locked_irt).
 * NULL means "no grid has been initialized yet" — with no anchor the Kill
 * Switch simply skips the stop-loss check, so this migration is purely
 * additive and changes no existing behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->decimal('grid_center_price', 20, 0)->nullable()->default(null)->after('center_price');
        });

        // Intentionally NO backfill. Existing rows stay NULL until their next
        // grid initialization populates the anchor.
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->dropColumn('grid_center_price');
        });
    }
};
