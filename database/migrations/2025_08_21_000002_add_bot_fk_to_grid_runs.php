<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('grid_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('grid_runs', 'bot_id')) {
                $table->unsignedBigInteger('bot_id')->nullable()->after('id');
            }

            // اگر از قبل FK تعریف نشده:
            // اسم ایندکس/کلید ممکن است متفاوت باشد؛ ساده‌ترین کار:
            try {
                $table->foreign('bot_id')->references('id')->on('bot_configs')->nullOnDelete();
            } catch (\Throwable $e) {
                // نادیده بگیر اگر قبلاً وجود دارد
            }
        });
    }

    public function down(): void
    {
        Schema::table('grid_runs', function (Blueprint $table) {
            try {
                $table->dropForeign(['bot_id']);
            } catch (\Throwable $e) {}
            if (Schema::hasColumn('grid_runs', 'bot_id')) {
                $table->dropColumn('bot_id');
            }
        });
    }
};
