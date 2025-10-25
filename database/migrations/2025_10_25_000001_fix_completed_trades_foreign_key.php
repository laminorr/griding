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
            // Check if the wrong column exists and rename it
            if (Schema::hasColumn('completed_trades', 'bot_id')) {
                // Drop foreign key if it exists
                $table->dropForeign(['bot_id']);

                // Rename the column
                $table->renameColumn('bot_id', 'bot_config_id');

                // Re-add the foreign key with correct column name
                $table->foreign('bot_config_id')
                    ->references('id')
                    ->on('bot_configs')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('completed_trades', function (Blueprint $table) {
            if (Schema::hasColumn('completed_trades', 'bot_config_id')) {
                $table->dropForeign(['bot_config_id']);
                $table->renameColumn('bot_config_id', 'bot_id');
                $table->foreign('bot_id')
                    ->references('id')
                    ->on('bot_configs')
                    ->onDelete('cascade');
            }
        });
    }
};
