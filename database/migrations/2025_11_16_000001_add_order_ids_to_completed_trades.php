<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('completed_trades', function (Blueprint $table) {
            // اضافه کردن فیلدهای buy_order_id و sell_order_id
            $table->foreignId('buy_order_id')
                ->nullable()
                ->after('bot_config_id')
                ->constrained('grid_orders')
                ->onDelete('set null');

            $table->foreignId('sell_order_id')
                ->nullable()
                ->after('buy_order_id')
                ->constrained('grid_orders')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('completed_trades', function (Blueprint $table) {
            $table->dropForeign(['buy_order_id']);
            $table->dropForeign(['sell_order_id']);
            $table->dropColumn(['buy_order_id', 'sell_order_id']);
        });
    }
};
