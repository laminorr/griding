<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classic-grid re-arm («چراغ‌ها») — see App\Services\GridRearmer.
     *
     * bot_configs
     *  - rearm_exits (bool, default false): the per-bot flag. OFF for every
     *    existing bot — this migration never turns it on.
     *  - grid_generation (uint, default 0): bumped by GridOrderExecutor every
     *    time a grid is (re)built (initial placement or an AdjustGridJob
     *    rebuild). A re-arm is only placed for a level of the CURRENT
     *    generation, so a rebuild never resurrects old levels.
     *
     * grid_orders
     *  - rearm_order_id (exit row): back-link to the re-arm order this exit
     *    fill spawned; set in the same transaction as the re-arm intent row.
     *  - rearm_state (exit row): NULL (undecided) | 'placed' | 'skipped:<REASON>'.
     *  - rearm_exit_order_id (re-arm row): the exit whose fill spawned it.
     *    UNIQUE — one exit fill can never own two re-arms, enforced by the DB.
     *  - rearm_root_order_id (re-arm row): the chain's root grid order (the
     *    level's price and size come from it).
     *  - grid_generation (grid rows): the generation the row was placed or
     *    kept by. NULL on rows that predate this migration.
     *
     * All additive; existing rows unaffected. ⚠ Requires `php artisan migrate`.
     */
    private const ORDER_COLUMNS = ['rearm_order_id', 'rearm_state', 'rearm_exit_order_id', 'rearm_root_order_id', 'grid_generation'];

    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_configs', 'rearm_exits')) {
                $table->boolean('rearm_exits')->default(false);
            }
            if (! Schema::hasColumn('bot_configs', 'grid_generation')) {
                $table->unsignedInteger('grid_generation')->default(0);
            }
        });

        Schema::table('grid_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('grid_orders', 'rearm_order_id')) {
                $table->unsignedBigInteger('rearm_order_id')->nullable()->index();
            }
            if (! Schema::hasColumn('grid_orders', 'rearm_state')) {
                $table->string('rearm_state', 48)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'rearm_exit_order_id')) {
                $table->unsignedBigInteger('rearm_exit_order_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('grid_orders', 'rearm_root_order_id')) {
                $table->unsignedBigInteger('rearm_root_order_id')->nullable()->index();
            }
            if (! Schema::hasColumn('grid_orders', 'grid_generation')) {
                $table->unsignedInteger('grid_generation')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        $indexes = ['rearm_order_id' => 'index', 'rearm_exit_order_id' => 'unique', 'rearm_root_order_id' => 'index', 'grid_generation' => 'index'];
        foreach ($indexes as $col => $kind) {
            if (Schema::hasColumn('grid_orders', $col)) {
                Schema::table('grid_orders', function (Blueprint $table) use ($col, $kind) {
                    $kind === 'unique' ? $table->dropUnique([$col]) : $table->dropIndex([$col]);
                });
            }
        }
        $drop = array_values(array_filter(self::ORDER_COLUMNS, fn (string $c) => Schema::hasColumn('grid_orders', $c)));
        if ($drop !== []) {
            Schema::table('grid_orders', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }

        $drop = array_values(array_filter(['rearm_exits', 'grid_generation'], fn (string $c) => Schema::hasColumn('bot_configs', $c)));
        if ($drop !== []) {
            Schema::table('bot_configs', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
