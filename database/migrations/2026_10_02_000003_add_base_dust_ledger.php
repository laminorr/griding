<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fee model Phase 4 — per-bot base-currency dust ledger.
     *
     *  - bot_configs.base_dust: BTC this bot's cycles left on the account that
     *    no exit has sold yet (credited − sold remainders below one quantity
     *    step, inventory-restore excess), minus any recorded shortfall. Folded
     *    into the next exit sell once it reaches one step (App\Services\ExitSizer).
     *    Existing rows start at 0.
     *  - grid_orders.exit_dust_delta: the change to base_dust caused by this
     *    exit row, so a cancelled-and-unlinked exit intent can be reverted
     *    exactly (and only once).
     *
     * ⚠ Requires `php artisan migrate` on the host. Additive, MySQL-safe.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('bot_configs', 'base_dust')) {
            Schema::table('bot_configs', function (Blueprint $table) {
                $table->decimal('base_dust', 36, 18)->default(0);
            });
        }
        if (! Schema::hasColumn('grid_orders', 'exit_dust_delta')) {
            Schema::table('grid_orders', function (Blueprint $table) {
                $table->decimal('exit_dust_delta', 36, 18)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('grid_orders', 'exit_dust_delta')) {
            Schema::table('grid_orders', function (Blueprint $table) {
                $table->dropColumn('exit_dust_delta');
            });
        }
        if (Schema::hasColumn('bot_configs', 'base_dust')) {
            Schema::table('bot_configs', function (Blueprint $table) {
                $table->dropColumn('base_dust');
            });
        }
    }
};
