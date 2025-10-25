<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('bot_configs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('bot_configs', 'stopped_at')) {
                $table->timestamp('stopped_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('bot_configs', 'started_at')) {
                $table->dropColumn('started_at');
            }
            if (Schema::hasColumn('bot_configs', 'stopped_at')) {
                $table->dropColumn('stopped_at');
            }
        });
    }
};
