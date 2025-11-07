<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing records with NULL to have default 100%
        DB::table('bot_configs')
            ->whereNull('active_capital_percent')
            ->update(['active_capital_percent' => 100.0]);

        // Alter column to have default value
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->decimal('active_capital_percent', 5, 2)
                ->default(100.0)
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->decimal('active_capital_percent', 5, 2)
                ->nullable()
                ->change();
        });
    }
};
