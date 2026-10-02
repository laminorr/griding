<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fee model Phase 6 — per-leg fee breakdown on completed_trades.
     *
     * Existing columns keep their meaning: `fee` = total fee in rial,
     * `net_profit` = true net in rial (now computed from real per-leg fees,
     * fill prices and filled quantities — CompletedTrade::createFromOrders).
     *
     *  - buy_/sell_fee_amount, _currency, _quote : each leg's fee as charged,
     *    its currency ('base'|'quote') and its value in rial (a base fee is
     *    valued at the BUY fill price — see docs/fees.md)
     *  - fee_source        'actual' | 'estimated' | 'mixed'
     *  - buy_/sell_filled_amount : the matched quantities of the two legs
     *  - base_residual     buyQty − buy base fee − sellQty − sell base fee
     *                      (the dust the cycle left on the account)
     *  - fee_model_version 1 for rows booked by this model; NULL = legacy row
     *                      not yet stamped (fees:backfill sets 0)
     *  - profit_v0 / net_profit_v0 : the legacy values, copied by
     *                      fees:backfill before any recomputation (audit trail)
     *
     * All nullable; existing rows are NOT rewritten by this migration.
     * ⚠ Requires `php artisan migrate`. Additive, MySQL-safe.
     */
    private const DECIMALS = [
        'buy_fee_amount', 'buy_fee_quote', 'sell_fee_amount', 'sell_fee_quote',
        'buy_filled_amount', 'sell_filled_amount', 'base_residual', 'profit_v0', 'net_profit_v0',
    ];

    public function up(): void
    {
        Schema::table('completed_trades', function (Blueprint $table) {
            foreach (self::DECIMALS as $c) {
                if (! Schema::hasColumn('completed_trades', $c)) {
                    $table->decimal($c, 36, 18)->nullable();
                }
            }
            foreach (['buy_fee_currency', 'sell_fee_currency'] as $c) {
                if (! Schema::hasColumn('completed_trades', $c)) {
                    $table->string($c, 8)->nullable();
                }
            }
            if (! Schema::hasColumn('completed_trades', 'fee_source')) {
                $table->string('fee_source', 16)->nullable();
            }
            if (! Schema::hasColumn('completed_trades', 'fee_model_version')) {
                $table->unsignedSmallInteger('fee_model_version')->nullable();
            }
        });
    }

    public function down(): void
    {
        $all  = array_merge(self::DECIMALS, ['buy_fee_currency', 'sell_fee_currency', 'fee_source', 'fee_model_version']);
        $drop = array_values(array_filter($all, fn (string $c) => Schema::hasColumn('completed_trades', $c)));
        if ($drop !== []) {
            Schema::table('completed_trades', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
