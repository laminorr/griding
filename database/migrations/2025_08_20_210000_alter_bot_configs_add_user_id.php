<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bot_configs') && !Schema::hasColumn('bot_configs', 'user_id')) {
            Schema::table('bot_configs', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
                $table->index('user_id', 'bot_configs_user_id_idx');
            });

            // اگر رکوردی دارید و فعلاً تک‌کاربره‌اید، همه را به ادمین وصل کنیم
            $adminId = DB::table('users')->where('is_admin', 1)->value('id');
            if ($adminId) {
                DB::table('bot_configs')->update(['user_id' => $adminId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bot_configs') && Schema::hasColumn('bot_configs', 'user_id')) {
            Schema::table('bot_configs', function (Blueprint $table) {
                $table->dropIndex('bot_configs_user_id_idx');
                $table->dropColumn('user_id');
            });
        }
    }
};
