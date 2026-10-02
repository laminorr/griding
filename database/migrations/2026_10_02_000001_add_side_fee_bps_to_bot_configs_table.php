<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fee model Phase 1 — optional per-bot, per-side fee rate overrides.
     *
     * NULL (the default, and the value every existing row gets) means "use
     * config('trading.fees.{buy|sell}_fee_bps')". Values are basis points,
     * DECIMAL(8,4) so a fractional tier (e.g. 17.5) is expressible.
     *
     * The legacy bot_configs.fee_bps column is left in place untouched but is
     * no longer read by any code (see App\Services\FeeModel). Existing rows are
     * NOT rewritten.
     *
     * ⚠ Requires `php artisan migrate` on the host. Additive, MySQL-safe.
     */
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_configs', 'buy_fee_bps')) {
                $table->decimal('buy_fee_bps', 8, 4)->nullable()->default(null);
            }
            if (! Schema::hasColumn('bot_configs', 'sell_fee_bps')) {
                $table->decimal('sell_fee_bps', 8, 4)->nullable()->default(null);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['buy_fee_bps', 'sell_fee_bps'],
                fn (string $c) => Schema::hasColumn('bot_configs', $c)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
