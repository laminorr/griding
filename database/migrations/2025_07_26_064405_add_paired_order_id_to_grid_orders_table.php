<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('paired_order_id')->nullable()->after('nobitex_order_id');
            $table->index('paired_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropColumn('paired_order_id');
        });
    }
};