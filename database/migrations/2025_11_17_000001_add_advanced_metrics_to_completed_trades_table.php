<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * این migration فیلدهای پیشرفته‌ای رو به جدول completed_trades اضافه می‌کنه که
     * مدل CompletedTrade بهشون نیاز داره برای محاسبات و آنالیز معاملات
     */
    public function up(): void
    {
        Schema::table('completed_trades', function (Blueprint $table) {
            // سود و آمار مالی پیشرفته
            $table->decimal('gross_profit', 20, 8)->nullable()->after('profit')
                ->comment('سود ناخالص قبل از کسر کارمزد');
            $table->decimal('net_profit', 20, 8)->nullable()->after('gross_profit')
                ->comment('سود خالص بعد از کسر کارمزد');
            $table->decimal('profit_percentage', 10, 4)->nullable()->after('net_profit')
                ->comment('درصد سود نسبت به سرمایه اولیه');

            // زمان اجرا (ثانیه)
            $table->integer('execution_time_seconds')->nullable()->after('profit_percentage')
                ->comment('مدت زمان بین خرید و فروش به ثانیه');

            // شرایط بازار و متادیتا
            $table->json('market_conditions')->nullable()->after('execution_time_seconds')
                ->comment('شرایط بازار در زمان معامله (قیمت BTC، ترند و...)');

            // نوع معامله
            $table->string('trade_type', 50)->nullable()->default('grid')->after('market_conditions')
                ->comment('نوع معامله: grid, manual, stop_loss, take_profit');

            // سطح‌های گرید
            $table->integer('grid_level_buy')->nullable()->after('trade_type')
                ->comment('سطح گرید خرید');
            $table->integer('grid_level_sell')->nullable()->after('grid_level_buy')
                ->comment('سطح گرید فروش');

            // اسلیپیج و یادداشت‌ها
            $table->decimal('slippage', 10, 4)->nullable()->after('grid_level_sell')
                ->comment('اختلاف قیمت اجرا با قیمت درخواستی');
            $table->text('notes')->nullable()->after('slippage')
                ->comment('یادداشت‌ها و توضیحات اضافی');

            // اضافه کردن indexes برای بهبود performance query ها
            $table->index(['bot_config_id', 'created_at'], 'idx_bot_created');
            $table->index('trade_type');
            $table->index('profit_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('completed_trades', function (Blueprint $table) {
            // حذف indexes
            $table->dropIndex('idx_bot_created');
            $table->dropIndex(['trade_type']);
            $table->dropIndex(['profit_percentage']);

            // حذف ستون‌ها
            $table->dropColumn([
                'gross_profit',
                'net_profit',
                'profit_percentage',
                'execution_time_seconds',
                'market_conditions',
                'trade_type',
                'grid_level_buy',
                'grid_level_sell',
                'slippage',
                'notes',
            ]);
        });
    }
};
