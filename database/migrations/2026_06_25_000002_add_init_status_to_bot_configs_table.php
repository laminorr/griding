<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bot_configs has no real `status` column — `status` is purely a computed
 * accessor on the BotConfig model (derived from is_active + latest GridRun),
 * so any ->update(['status' => ...]) call has always been silently dropped
 * (the key isn't fillable). This adds a real, persisted column dedicated to
 * tracking the outcome of grid initialization specifically, distinct from
 * the existing UI-facing `status` accessor.
 *
 * Values written by TradingEngineService::initializeGrid(): 'running',
 * 'partially_initialized', 'failed'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->string('init_status', 32)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->dropColumn('init_status');
        });
    }
};
