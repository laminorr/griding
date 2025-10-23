<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            // Status tracking (status column already exists, skip it)
            $table->string('stop_reason')->nullable()->after('status');

            // Lifecycle tracking
            $table->timestamp('last_check_at')->nullable()->after('last_run_at');
            $table->timestamp('last_rebalance_at')->nullable()->after('last_check_at');
            $table->integer('rebalance_count')->default(0)->after('last_rebalance_at');

            // Risk management
            $table->decimal('take_profit_percent', 5, 2)->nullable()->after('stop_loss_percent');
            $table->decimal('max_drawdown_percent', 5, 2)->nullable()->after('take_profit_percent');
            $table->decimal('rebalance_threshold', 5, 2)->default(5.0)->after('max_drawdown_percent');

            // Performance metrics
            $table->decimal('total_profit', 20, 0)->default(0)->after('rebalance_threshold');
            $table->decimal('win_rate', 5, 2)->default(0)->after('total_profit');

            // Notes
            $table->text('notes')->nullable()->after('win_rate');
        });
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->dropColumn([
                'stop_reason', 'last_check_at', 'last_rebalance_at',
                'rebalance_count', 'take_profit_percent', 'max_drawdown_percent',
                'rebalance_threshold', 'total_profit', 'win_rate', 'notes'
            ]);
        });
    }
};
