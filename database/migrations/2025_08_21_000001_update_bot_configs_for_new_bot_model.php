<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            // ——— فیلدهای نسل جدید (با چکِ وجود) ———
            if (! Schema::hasColumn('bot_configs', 'symbol')) {
                $table->string('symbol', 32)->default('BTCIRT')->after('name');
                $table->index('symbol');
            }

            if (! Schema::hasColumn('bot_configs', 'mode')) {
                $table->string('mode', 8)->default('buy')->after('symbol'); // buy|sell|both
            }

            if (! Schema::hasColumn('bot_configs', 'levels')) {
                $table->integer('levels')->default(3)->after('mode');
            }

            if (! Schema::hasColumn('bot_configs', 'step_pct')) {
                $table->decimal('step_pct', 8, 3)->default(0.250)->after('levels');
            }

            if (! Schema::hasColumn('bot_configs', 'budget_irt')) {
                $table->unsignedBigInteger('budget_irt')->default(0)->after('step_pct');
            }

            if (! Schema::hasColumn('bot_configs', 'simulation')) {
                $table->boolean('simulation')->default(true)->after('budget_irt');
                $table->index('simulation');
            }

            if (! Schema::hasColumn('bot_configs', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('simulation');
                $table->index('is_active');
            }

            if (! Schema::hasColumn('bot_configs', 'min_order_value_irt')) {
                $table->unsignedBigInteger('min_order_value_irt')->nullable()->after('is_active');
            }

            if (! Schema::hasColumn('bot_configs', 'fee_bps')) {
                $table->unsignedSmallInteger('fee_bps')->default(35)->after('min_order_value_irt'); // 35 = 0.35%
            }

            if (! Schema::hasColumn('bot_configs', 'qty_decimals')) {
                $table->unsignedTinyInteger('qty_decimals')->default(8)->after('fee_bps');
            }

            if (! Schema::hasColumn('bot_configs', 'tick')) {
                $table->unsignedInteger('tick')->default(10)->after('qty_decimals');
            }

            if (! Schema::hasColumn('bot_configs', 'settings_json')) {
                $table->json('settings_json')->nullable()->after('tick');
            }

            if (! Schema::hasColumn('bot_configs', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable()->after('settings_json');
                $table->index('last_run_at');
            }

            if (! Schema::hasColumn('bot_configs', 'last_error_code')) {
                $table->string('last_error_code', 64)->nullable()->after('last_run_at');
            }

            if (! Schema::hasColumn('bot_configs', 'last_error_message')) {
                $table->text('last_error_message')->nullable()->after('last_error_code');
            }

            // ——— ایندکس ترکیبی مفید برای لیست‌ها/فیلترها ———
            if (! $this->indexExists('bot_configs', 'bot_configs_is_active_simulation_symbol_index')) {
                $table->index(['is_active', 'simulation', 'symbol']);
            }
        });

        // ——— بک‌فیلِ داده‌های خالی به مقادیر امن ———
        // (در صورت وجود رکوردهای قدیمی)
        DB::table('bot_configs')->whereNull('symbol')->update(['symbol' => 'BTCIRT']);
        DB::table('bot_configs')->whereNull('mode')->update(['mode' => 'buy']);
        DB::table('bot_configs')->whereNull('levels')->update(['levels' => 3]);
        DB::table('bot_configs')->whereNull('step_pct')->update(['step_pct' => 0.250]);
        DB::table('bot_configs')->whereNull('simulation')->update(['simulation' => true]);
        DB::table('bot_configs')->whereNull('is_active')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            // حذف ایندکس‌های ساخته‌شده (اگر وجود داشته باشد)
            if ($this->indexExists('bot_configs', 'bot_configs_symbol_index')) {
                $table->dropIndex(['symbol']);
            }
            if ($this->indexExists('bot_configs', 'bot_configs_simulation_index')) {
                $table->dropIndex(['simulation']);
            }
            if ($this->indexExists('bot_configs', 'bot_configs_is_active_index')) {
                $table->dropIndex(['is_active']);
            }
            if ($this->indexExists('bot_configs', 'bot_configs_last_run_at_index')) {
                $table->dropIndex(['last_run_at']);
            }
            if ($this->indexExists('bot_configs', 'bot_configs_is_active_simulation_symbol_index')) {
                $table->dropIndex(['is_active', 'simulation', 'symbol']);
            }

            // ستون‌های افزوده‌شده را به‌صورت امن حذف کن
            foreach ([
                'last_error_message',
                'last_error_code',
                'last_run_at',
                'settings_json',
                'tick',
                'qty_decimals',
                'fee_bps',
                'min_order_value_irt',
                'is_active',
                'simulation',
                'budget_irt',
                'step_pct',
                'levels',
                'mode',
                'symbol',
            ] as $col) {
                if (Schema::hasColumn('bot_configs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * بررسی وجود ایندکس (برای درایورهای رایج MySQL)
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection()->getDoctrineSchemaManager();
        $indexes = $connection->listTableIndexes($table);

        return array_key_exists($indexName, $indexes);
    }
};
