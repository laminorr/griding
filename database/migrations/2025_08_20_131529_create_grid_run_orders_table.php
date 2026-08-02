<?php
// ===== database/migrations/xxxx_xx_xx_xxxxxx_create_grid_run_orders_table.php =====


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    public function up(): void
    {
        // Idempotent + replayable: skip if already built, and only wire the
        // FK to grid_runs when that (System B, since-retired) parent table is
        // actually present. On the fresh sqlite test schema grid_runs is never
        // created, so a hard constrained() reference would leave a dangling FK.
        if (Schema::hasTable('grid_run_orders')) {
            return;
        }

        $hasGridRuns = Schema::hasTable('grid_runs');

        Schema::create('grid_run_orders', function (Blueprint $table) use ($hasGridRuns) {
            $table->id();

            if ($hasGridRuns) {
                $table->foreignId('run_id')->constrained('grid_runs')->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('run_id')->index();
            }


            $table->string('client_order_id', 64)->index(); // clientOrderId
            $table->unsignedBigInteger('exchange_order_id')->nullable()->index(); // Nobitex id


            $table->string('side', 8); // buy|sell
            $table->string('status', 24); // Active|Filled|Partial|Canceled|Rejected
            $table->unsignedBigInteger('price_irt'); // قیمت IRT (عدد صحیح)
            $table->decimal('amount', 24, 8);
            $table->decimal('matched', 24, 8)->default(0);
            $table->decimal('unmatched', 24, 8)->default(0);


            $table->json('raw_json')->nullable();
            $table->timestamps();


            $table->index(['run_id', 'status']);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('grid_run_orders');
    }
};
