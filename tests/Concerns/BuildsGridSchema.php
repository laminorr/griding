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
    }

    protected function dropGridSchema(): void
    {
        Schema::dropIfExists('bot_activity_logs');
        Schema::dropIfExists('grid_orders');
        Schema::dropIfExists('bot_configs');
    }
}
