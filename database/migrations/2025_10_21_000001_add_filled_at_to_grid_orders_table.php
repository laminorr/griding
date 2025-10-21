<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->timestamp('filled_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('grid_orders', function (Blueprint $table) {
            $table->dropColumn('filled_at');
        });
    }
};
