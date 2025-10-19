<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // فیلدهای وضعیت و نقش
            if (!Schema::hasColumn('users', 'is_active')) $table->boolean('is_active')->default(true)->after('remember_token');
            if (!Schema::hasColumn('users', 'is_admin'))  $table->boolean('is_admin')->default(false)->after('is_active');

            // نمایه و تماس
            if (!Schema::hasColumn('users', 'avatar')) $table->string('avatar')->nullable()->after('is_admin');
            if (!Schema::hasColumn('users', 'phone'))  $table->string('phone', 32)->nullable()->after('avatar');

            // ناحیه زمانی و زبان
            if (!Schema::hasColumn('users', 'timezone')) $table->string('timezone', 64)->nullable()->after('phone');
            if (!Schema::hasColumn('users', 'language')) $table->string('language', 8)->nullable()->after('timezone');

            // تأیید‌ها
            if (!Schema::hasColumn('users', 'email_verified_at')) $table->timestamp('email_verified_at')->nullable()->after('email');
            if (!Schema::hasColumn('users', 'phone_verified_at')) $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');

            // لاگین
            if (!Schema::hasColumn('users', 'last_login_at')) $table->timestamp('last_login_at')->nullable()->after('language');
            if (!Schema::hasColumn('users', 'login_count'))   $table->unsignedInteger('login_count')->default(0)->after('last_login_at');

            // تنظیمات
            if (!Schema::hasColumn('users', 'preferences'))            $table->json('preferences')->nullable()->after('login_count');
            if (!Schema::hasColumn('users', 'api_settings'))           $table->json('api_settings')->nullable()->after('preferences');
            if (!Schema::hasColumn('users', 'notification_settings'))  $table->json('notification_settings')->nullable()->after('api_settings');

            // پروفایل ترید
            if (!Schema::hasColumn('users', 'trading_experience'))   $table->string('trading_experience', 32)->nullable()->after('notification_settings');
            if (!Schema::hasColumn('users', 'risk_tolerance'))       $table->string('risk_tolerance', 32)->nullable()->after('trading_experience');
            if (!Schema::hasColumn('users', 'max_concurrent_bots'))  $table->unsignedInteger('max_concurrent_bots')->nullable()->after('risk_tolerance');
            if (!Schema::hasColumn('users', 'max_total_capital'))    $table->decimal('max_total_capital', 12, 2)->nullable()->after('max_concurrent_bots');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = [
                'is_active','is_admin','avatar','phone','timezone','language',
                'email_verified_at','phone_verified_at','last_login_at','login_count',
                'preferences','api_settings','notification_settings',
                'trading_experience','risk_tolerance','max_concurrent_bots','max_total_capital',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
