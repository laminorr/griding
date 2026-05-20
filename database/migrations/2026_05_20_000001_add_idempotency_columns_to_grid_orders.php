<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add client_order_id separately so the unique index is created cleanly.
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->string('client_order_id')->nullable()->after('nobitex_order_id');
            $table->unique('client_order_id');
        });

        // Add exchange_order_id in a second call to avoid MySQL bugs with
        // multiple index operations on the same ALTER TABLE statement.
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->string('exchange_order_id')->nullable()->after('client_order_id');
            $table->index('exchange_order_id');
        });
    }

    public function down(): void
    {
        // Drop indexes before columns — required on MySQL.
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropUnique(['client_order_id']);
            $table->dropIndex(['exchange_order_id']);
        });

        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropColumn(['client_order_id', 'exchange_order_id']);
        });
    }
};
