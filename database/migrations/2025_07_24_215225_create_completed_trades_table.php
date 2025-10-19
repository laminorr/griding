<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completed_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_config_id')->constrained('bot_configs')->onDelete('cascade');
            
            // قیمت‌ها به ریال - بدون اعشار
            $table->decimal('buy_price', 20, 0);
            $table->decimal('sell_price', 20, 0);
            
            // مقدار BTC - با 8 رقم اعشار
            $table->decimal('amount', 20, 8);
            
            // سود و کارمزد به ریال - بدون اعشار
            $table->decimal('profit', 20, 0);
            $table->decimal('fee', 20, 0);
            
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('completed_trades');
    }
};