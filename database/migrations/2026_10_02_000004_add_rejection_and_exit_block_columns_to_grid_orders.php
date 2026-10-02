<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fee model Phase 5 — definitive rejections and blocked exits.
     *
     *  - last_error_code / last_error_message: why the exchange definitively
     *    rejected this order row (e.g. 'InsufficientBalance', 'SmallOrder').
     *    Such rows are 'cancelled', never 'submission_unknown'.
     *  - exit_state on a FILLED parent order: NULL (normal) | 'blocked' (its
     *    exit could not be placed; CheckTradesJob stops re-selecting it) |
     *    'cleared' (an operator resolved it by hand; never re-paired).
     *  - exit_blocked_reason / exit_blocked_at: why and when it was blocked.
     *    Operator tool: `php artisan grid:exit-blocked`.
     *
     * All nullable; existing rows unaffected. ⚠ Requires `php artisan migrate`.
     */
    private const COLUMNS = ['last_error_code', 'last_error_message', 'exit_state', 'exit_blocked_reason', 'exit_blocked_at'];

    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('grid_orders', 'last_error_code')) {
                $table->string('last_error_code', 64)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'last_error_message')) {
                $table->string('last_error_message', 255)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'exit_state')) {
                $table->string('exit_state', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('grid_orders', 'exit_blocked_reason')) {
                $table->string('exit_blocked_reason', 255)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'exit_blocked_at')) {
                $table->timestamp('exit_blocked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('grid_orders', 'exit_state')) {
            Schema::table('grid_orders', function (Blueprint $table) {
                $table->dropIndex(['exit_state']);
            });
        }
        $drop = array_values(array_filter(self::COLUMNS, fn (string $c) => Schema::hasColumn('grid_orders', $c)));
        if ($drop !== []) {
            Schema::table('grid_orders', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
