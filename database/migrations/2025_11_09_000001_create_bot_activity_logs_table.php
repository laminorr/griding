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
        Schema::create('bot_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_config_id')->constrained('bot_configs')->onDelete('cascade');
            $table->string('action_type'); // CHECK_TRADES_START, CHECK_TRADES_END, API_CALL, ORDER_PLACED, ORDER_FILLED, ORDER_CANCELLED, PRICE_CHECK, GRID_ADJUST, ERROR
            $table->string('level'); // INFO, SUCCESS, WARNING, ERROR
            $table->string('message'); // پیام فارسی
            $table->json('details')->nullable(); // جزئیات اضافی
            $table->json('api_request')->nullable(); // درخواست ارسالی به نوبیتکس
            $table->json('api_response')->nullable(); // پاسخ نوبیتکس
            $table->integer('execution_time')->nullable(); // میلی‌ثانیه
            $table->timestamps();

            // Index for faster queries
            $table->index(['bot_config_id', 'created_at']);
            $table->index(['action_type']);
            $table->index(['level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_activity_logs');
    }
};
