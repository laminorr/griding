<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align the bot_configs.stop_loss_percent DB default with
     * config('trading.grid.default_stop_loss_percent') (=15).
     *
     * The historical create migration (2025_07_24_214742) set the default to 5,
     * so a BotConfig row inserted without an explicit stop_loss_percent silently
     * got a 5% kill-switch distance — 3x more sensitive than the 15% the config
     * intends. Only the DEFAULT changes here; the column stays DECIMAL(5,2) and
     * NOT NULL exactly as before (no ->nullable()).
     */
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->decimal('stop_loss_percent', 5, 2)->default(15)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->decimal('stop_loss_percent', 5, 2)->default(5)->change();
        });
    }
};
