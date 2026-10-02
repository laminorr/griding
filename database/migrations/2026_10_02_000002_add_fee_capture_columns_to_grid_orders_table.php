<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fee model Phase 3 — per-order fee capture. All columns are nullable and
     * default NULL, so existing rows and write paths are unaffected; existing
     * rows are NOT backfilled (NULL = "fee not captured").
     *
     *  - fee_amount      fee as charged (cumulative for the order), in fee_currency
     *  - fee_currency    'base' | 'quote' (detected by FeeModel::classifyActualFee)
     *  - fee_asset       raw asset code the fee is in, e.g. 'btc' / 'rls'
     *  - fee_source      'actual' (from the exchange) | 'estimated' (FeeModel rate)
     *  - fee_quote       the fee valued in quote (rial); a base fee at avg_fill_price
     *  - avg_fill_price  exact average execution price (exchange averagePrice)
     *  - net_base_delta  signed base change caused by this order net of fees:
     *                    buy = +filled − baseFee, sell = −filled (− baseFee if a
     *                    sell is ever charged in base)
     *
     * DECIMAL(36,18): 18 integer digits cover any IRT value, 18 fractional
     * digits cover the finest observed fee precision (12 dp) with headroom.
     *
     * ⚠ Requires `php artisan migrate` on the host. Additive, MySQL-safe.
     */
    private const COLUMNS = [
        'fee_amount', 'fee_currency', 'fee_asset', 'fee_source',
        'fee_quote', 'avg_fill_price', 'net_base_delta',
    ];

    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('grid_orders', 'fee_amount')) {
                $table->decimal('fee_amount', 36, 18)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'fee_currency')) {
                $table->string('fee_currency', 8)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'fee_asset')) {
                $table->string('fee_asset', 8)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'fee_source')) {
                $table->string('fee_source', 16)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'fee_quote')) {
                $table->decimal('fee_quote', 36, 18)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'avg_fill_price')) {
                $table->decimal('avg_fill_price', 36, 18)->nullable();
            }
            if (! Schema::hasColumn('grid_orders', 'net_base_delta')) {
                $table->decimal('net_base_delta', 36, 18)->nullable();
            }
        });
    }

    public function down(): void
    {
        $drop = array_values(array_filter(self::COLUMNS, fn (string $c) => Schema::hasColumn('grid_orders', $c)));
        if ($drop !== []) {
            Schema::table('grid_orders', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
