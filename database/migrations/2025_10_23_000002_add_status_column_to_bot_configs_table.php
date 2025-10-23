<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the 'status' column that is referenced throughout the codebase
     * but was never created in the original migrations.
     */
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            // Add status column if it doesn't exist
            if (!Schema::hasColumn('bot_configs', 'status')) {
                $table->string('status', 32)->default('inactive')->after('is_active');
                $table->index('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('bot_configs', 'status')) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            }
        });
    }
};
