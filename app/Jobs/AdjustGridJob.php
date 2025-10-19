<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\GridPlanner;
use App\Services\GridOrderSync;
use App\Support\OrderRegistry;
use App\Services\GridOrderExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AdjustGridJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected ?string $symbol = null) {}

    public function handle(
        GridPlanner $planner,
        GridOrderSync $sync,
        OrderRegistry $reg,
        GridOrderExecutor $exec
    ): void {
        $symbols = $this->symbol
            ? [$this->symbol]
            : (array) config('trading.exchange.allowed_symbols', ['BTCIRT','ETHIRT','USDTIRT']);

        $simulate = (bool) config('trading.grid.simulation', true);

        foreach ($symbols as $symbol) {
            try {
                // 1) پلن
                $plan = $planner->plan(
                    $symbol,
                    levels: 6,
                    stepPct: 0.25,
                    mode: 'both',
                    budgetIrt: 50_000_000
                );
                // GridPlanner خودش GRID_PLAN را لاگ می‌کند.

                // 2) سفارش‌های باز این بات
                $existing = $reg->getOpen($symbol);

                // 3) diff — از پارامترهای پوزیشنی استفاده کن تا با امضا سازگار بماند
                $diff = $sync->diff($plan, $existing, 1, 3.0); // toleranceTicks=1, qtyTolerancePct=3.0
                // GridOrderSync خودش GRID_DIFF را لاگ می‌کند.

                // 4) اجرا (واقعی/شبیه‌سازی)
                $exec->apply($diff, simulation: $simulate);

            } catch (\Throwable $e) {
                Log::channel('trading')->error('ADJUST_GRID_ERROR', [
                    'symbol' => $symbol,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }
}