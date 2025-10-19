<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Grid Bot #1');
            $table->boolean('is_active')->default(false);
            
            // تغییر از 3000 دلار به 100 میلیون ریال (برای شروع)
            $table->decimal('total_capital', 20, 0)->default(100000000);
            
            $table->decimal('active_capital_percent', 5, 2)->default(30);
            $table->decimal('grid_spacing', 5, 2)->default(1.5);
            $table->integer('grid_levels')->default(10);
            
            // center_price هم باید دقت 0 داشته باشه چون قیمت BTC به ریال عدد بزرگیه
            $table->decimal('center_price', 20, 0)->nullable();
            
            $table->decimal('stop_loss_percent', 5, 2)->default(5);
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('bot_configs');
    }
};