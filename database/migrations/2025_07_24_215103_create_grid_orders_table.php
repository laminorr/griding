<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_config_id')->constrained('bot_configs')->onDelete('cascade');
            
            // قیمت به ریال - بدون اعشار
            $table->decimal('price', 20, 0);
            
            // مقدار BTC - با 8 رقم اعشار (مطابق با دقت نوبیتکس برای BTC)
            $table->decimal('amount', 20, 8);
            
            $table->enum('type', ['buy', 'sell']);
            $table->enum('status', ['pending', 'placed', 'filled', 'cancelled']);
            $table->string('nobitex_order_id')->nullable();
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('grid_orders');
    }
};