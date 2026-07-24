<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 Step 1 — sqlite-friendly schema builder for the DB-backed harness.
 *
 * WHY THIS EXISTS (app-untestability finding for Step 2)
 * ------------------------------------------------------
 * The project's real migrations cannot run against the sqlite :memory: test DB
 * configured in phpunit.xml. Two of them issue raw MySQL-only DDL:
 *
 *   • database/migrations/2025_11_07_000001_fix_grid_orders_price_column.php
 *       ALTER TABLE grid_orders MODIFY COLUMN price DECIMAL(20,0) NOT NULL
 *   • database/migrations/2026_06_25_000001_add_submission_unknown_status_to_grid_orders.php
 *       ALTER TABLE grid_orders MODIFY COLUMN status ENUM(...'submission_unknown')
 *
 * sqlite supports neither `MODIFY COLUMN` nor `ENUM`, so `artisan migrate`
 * (and therefore RefreshDatabase) aborts. Because touching app/database code is
 * out of scope for this step, the lightest workable alternative is to build the
 * handful of tables these tests need directly, with their FINAL shape. The
 * `status` column is a plain string here (no ENUM/CHECK) precisely so the
 * post-Phase-9 values — 'submission_unknown', 'partially_filled' — can be
 * written, which is the whole point of the executor guard under test.
 *
 * A base column is dropped-then-created per test for isolation, since the
 * sqlite :memory: connection persists across a test process.
 *
 * PHASE 13 STEP 5 EXTENSION
 * -------------------------
 * Two gaps were closed so the database-backed tier (Steps 7-10) has a schema
 * that does not lie:
 *
 *   • A `completed_trades` table was added (built from the three real
 *     migrations) — required by CompletedTrade::createFromOrders, the
 *     max-drawdown branch of KillSwitchService, and the booking tests.
 *   • The `bot_configs` inventory columns the globally-registered
 *     GridOrderObserver writes on every GridOrder save — open_cycles_count and
 *     capital_locked_irt — plus the risk/lifecycle columns KillSwitchService
 *     reads. Before this, those writes hit a missing column and were silently
 *     swallowed by the observer's try/catch(\Throwable), so any observer test
 *     built on the old trait would have been a FALSE GREEN.
 *
 * WHERE sqlite CANNOT MATCH PRODUCTION (input to the Step 6 MySQL decision):
 * every DECIMAL(20,0) IRT column below (capital_locked_irt, grid_center_price,
 * total_capital, center_price on bot_configs; buy_price, sell_price, profit,
 * fee on completed_trades; and price/average_fill_price on grid_orders) is
 * stored under sqlite NUMERIC affinity, which falls back to REAL (double) once
 * the value exceeds a signed 64-bit integer. A ~20-digit IRT value therefore
 * cannot be round-tripped bit-for-bit here. Existence/relational assertions are
 * safe; exact-value assertions on these columns must wait for real MySQL.
 */
trait BuildsGridSchema
{
    protected function buildGridSchema(): void
    {
        // The config/database.php 'sqlite' connection hard-codes a file path
        // (database_path('database.sqlite')) and ignores DB_DATABASE, so the
        // phpunit.xml :memory: setting never reaches it. Force an in-memory,
        // FK-free connection here (test-only) and reconnect to get a clean DB.
        config([
            'database.default'                                    => 'sqlite',
            'database.connections.sqlite.database'                => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('completed_trades');
        Schema::dropIfExists('bot_activity_logs');
        Schema::dropIfExists('grid_orders');
        Schema::dropIfExists('bot_configs');

        Schema::create('bot_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('symbol')->default('BTCIRT');
            $table->string('mode')->nullable();
            $table->boolean('simulation')->default(true);
            $table->boolean('is_active')->default(true);
            $table->decimal('grid_spacing', 8, 2)->default(1.0);
            $table->unsignedBigInteger('budget_irt')->default(0);
            // Columns the BotConfig::creating() hook always stamps (BotConfig.php
            // :455-463); without them BotConfig::create() fails on insert.
            $table->integer('levels')->nullable();
            $table->decimal('step_pct', 8, 3)->nullable();
            $table->decimal('active_capital_percent', 8, 2)->nullable();
            $table->timestamp('stopped_at')->nullable();
            // Phase 11 health surface consumed by the Step 7 reconciler's
            // escalation path (last_error_summary / scopeHasError).
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message')->nullable();

            // ── Phase 13 Step 5 additions ─────────────────────────────────────
            // Every column below is mirrored from a real migration so the
            // GridOrderObserver (registered globally in AppServiceProvider and
            // firing on EVERY GridOrder save) and KillSwitchService can actually
            // write/read on this connection instead of silently swallowing a
            // "no such column" error inside their try/catch(\Throwable).

            // Phase 11 Step 1 inventory tracking — the two columns the observer
            // writes. In production: unsignedTinyInteger + decimal(20,0).
            //   (2026_07_07_000001_add_inventory_tracking_columns_to_bot_configs_table)
            $table->unsignedTinyInteger('open_cycles_count')->nullable()->default(null);
            // decimal(20,0) in production. sqlite has no true fixed-precision
            // DECIMAL — NUMERIC affinity falls back to REAL (double) for values
            // beyond its 64-bit integer range, so a ~20-digit IRT value CANNOT be
            // stored faithfully here. Precision-sensitive assertions on this
            // column must wait for the real MySQL harness (Step 6).
            $table->decimal('capital_locked_irt', 20, 0)->nullable()->default(null);

            // Kill Switch reference price (Phase 11 Step 3). decimal(20,0) in
            // production — same sqlite precision caveat as capital_locked_irt.
            //   (2026_07_07_000002_add_grid_center_price_to_bot_configs_table)
            $table->decimal('grid_center_price', 20, 0)->nullable()->default(null);

            // Grid init outcome (2026_06_25_000002_add_init_status_to_bot_configs_table).
            $table->string('init_status', 32)->nullable();

            // Legacy / risk-management columns.
            //   (2025_07_24_214742_create_bot_configs_table
            //    + 2025_10_23_000001_add_missing_columns_to_bot_configs_table)
            $table->unsignedSmallInteger('fee_bps')->default(35);       // 35 = 0.35%
            // total_capital & center_price are decimal(20,0) in production — same
            // sqlite precision caveat as capital_locked_irt above.
            $table->decimal('total_capital', 20, 0)->default(100000000);
            $table->decimal('center_price', 20, 0)->nullable();
            $table->integer('grid_levels')->default(10);
            $table->decimal('stop_loss_percent', 5, 2)->default(5);
            $table->decimal('take_profit_percent', 5, 2)->nullable();
            $table->decimal('max_drawdown_percent', 5, 2)->nullable();
            $table->string('stop_reason')->nullable();

            // Lifecycle timestamps.
            //   (2025_10_25_222346_add_started_stopped_at_to_bot_configs_table
            //    + 2025_10_23_000001_add_missing_columns_to_bot_configs_table)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->timestamp('last_rebalance_at')->nullable();

            $table->timestamps();
        });

        Schema::create('grid_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_config_id');
            $table->decimal('price', 20, 0);
            $table->decimal('amount', 20, 8);
            // Plain strings (NOT sqlite ENUM/CHECK) — see the class docblock.
            $table->string('type');
            $table->string('status');
            $table->string('nobitex_order_id')->nullable();
            $table->string('client_order_id')->nullable();
            $table->string('exchange_order_id')->nullable();
            $table->unsignedBigInteger('paired_order_id')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->decimal('original_amount', 20, 8)->nullable();
            $table->decimal('filled_amount', 20, 8)->nullable();
            $table->decimal('remaining_amount', 20, 8)->nullable();
            $table->decimal('average_fill_price', 20, 0)->nullable();
            $table->timestamp('last_fill_at')->nullable();
            $table->string('role')->nullable();
            // Phase 12 Step 7 reconcile-tracking columns (same shape as
            // 2026_07_20_000001_add_reconcile_tracking_to_grid_orders).
            $table->unsignedInteger('reconcile_attempts')->default(0);
            $table->unsignedInteger('reconcile_not_found_count')->default(0);
            $table->timestamp('reconcile_last_attempt_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bot_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_config_id');
            $table->string('action_type');
            $table->string('level');
            $table->string('message');
            $table->json('details')->nullable();
            $table->json('api_request')->nullable();
            $table->json('api_response')->nullable();
            $table->integer('execution_time')->nullable();
            $table->timestamps();
        });

        // ── completed_trades (Phase 13 Step 5) ────────────────────────────────
        // Mirrors the three real migrations that build this table:
        //   • 2025_07_24_215225_create_completed_trades_table
        //   • 2025_11_16_000001_add_order_ids_to_completed_trades
        //   • 2025_11_17_000001_add_advanced_metrics_to_completed_trades_table
        // Required by CompletedTrade::createFromOrders, the max-drawdown branch
        // of KillSwitchService (reads net_profit < 0), and the booking tests in
        // Steps 7-9.
        //
        // FK constraints are intentionally omitted: buildGridSchema() disables
        // foreign_key_constraints on the sqlite connection, and the trait builds
        // only the handful of tables these tests touch, so a plain unsigned
        // bigint column stands in for each foreignId(). status/type stay plain
        // strings for the same reason grid_orders does (no sqlite ENUM).
        Schema::create('completed_trades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_config_id');
            $table->unsignedBigInteger('buy_order_id')->nullable();
            $table->unsignedBigInteger('sell_order_id')->nullable();

            // IRT price columns: decimal(20,0) in production. sqlite NUMERIC
            // affinity cannot faithfully hold ~20-digit values (falls back to
            // REAL/double) — precision-sensitive assertions wait for Step 6.
            $table->decimal('buy_price', 20, 0);
            $table->decimal('sell_price', 20, 0);

            // BTC amount: decimal(20,8).
            $table->decimal('amount', 20, 8);

            // profit & fee are decimal(20,0) in the production migration even
            // though the CompletedTrade model casts them to decimal:8 on read.
            // We mirror the MIGRATION here (source of truth per the task).
            $table->decimal('profit', 20, 0);
            $table->decimal('fee', 20, 0);

            // Advanced metrics (2025_11_17 migration). gross_profit / net_profit
            // are decimal(20,8) — net_profit is the column KillSwitchService's
            // drawdown branch sums when < 0.
            $table->decimal('gross_profit', 20, 8)->nullable();
            $table->decimal('net_profit', 20, 8)->nullable();
            $table->decimal('profit_percentage', 10, 4)->nullable();
            $table->integer('execution_time_seconds')->nullable();
            $table->json('market_conditions')->nullable();
            $table->string('trade_type', 50)->nullable()->default('grid');
            $table->integer('grid_level_buy')->nullable();
            $table->integer('grid_level_sell')->nullable();
            $table->decimal('slippage', 10, 4)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    protected function dropGridSchema(): void
    {
        Schema::dropIfExists('completed_trades');
        Schema::dropIfExists('bot_activity_logs');
        Schema::dropIfExists('grid_orders');
        Schema::dropIfExists('bot_configs');
    }
}
