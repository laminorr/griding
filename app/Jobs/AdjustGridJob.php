<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\BotConfig;
use App\Services\GridPlanner;
use App\Services\GridOrderSync;
use App\Support\OrderRegistry;
use App\Services\GridOrderExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdjustGridJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No constructor parameters - we get everything from BotConfig
    public function __construct() {}

    public function handle(
        GridPlanner $planner,
        GridOrderSync $sync,
        OrderRegistry $reg,
        GridOrderExecutor $exec
    ): void {
        // Global lock to prevent concurrent runs (1 second timeout)
        $globalLock = DB::select("SELECT GET_LOCK(?, 1) as locked", ['grid:adjust:global']);
        if (!$globalLock[0]->locked) {
            Log::channel('trading')->info('ADJUST_GRID_SKIP', [
                'reason' => 'Global lock busy - another instance running'
            ]);
            return;
        }

        try {
            // Get ONLY active bots
            $activeBots = BotConfig::where('is_active', true)->get();

            if ($activeBots->isEmpty()) {
                Log::channel('trading')->info('ADJUST_GRID_SKIP', [
                    'reason' => 'No active bots found'
                ]);
                return;
            }

            Log::channel('trading')->info('ADJUST_GRID_START', [
                'active_bots' => $activeBots->count(),
                'bot_ids' => $activeBots->pluck('id')->toArray()
            ]);

            // Whitelist allowed symbols from env
            $allowedSymbols = collect(explode(',', env('TRADING_ALLOWED_SYMBOLS', 'BTCIRT')))
                ->map(fn($s) => strtoupper(trim($s)))
                ->filter()
                ->values();

            foreach ($activeBots as $bot) {
                $symbol = strtoupper($bot->symbol ?? 'BTCIRT');

                // Check if symbol is whitelisted
                if (!$allowedSymbols->contains($symbol)) {
                    Log::channel('trading')->warning('SKIP_SYMBOL_NOT_ALLOWED', [
                        'bot_id' => $bot->id,
                        'bot_name' => $bot->name,
                        'symbol' => $symbol,
                        'allowed_symbols' => $allowedSymbols->toArray()
                    ]);
                    continue;
                }

                // Per-bot lock (1 second timeout)
                $botLockKey = "grid:adjust:bot:{$bot->id}";
                $botLock = DB::select("SELECT GET_LOCK(?, 1) as locked", [$botLockKey]);

                if (!$botLock[0]->locked) {
                    Log::channel('trading')->info('ADJUST_GRID_BOT_SKIP', [
                        'bot_id' => $bot->id,
                        'reason' => 'Bot lock busy'
                    ]);
                    continue;
                }

                try {
                    $simulate = (bool)($bot->is_simulation ?? false);

                    Log::channel('trading')->info('ADJUST_GRID_BOT_START', [
                        'bot_id' => $bot->id,
                        'bot_name' => $bot->name,
                        'symbol' => $symbol,
                        'simulation' => $simulate,
                        'grid_levels' => $bot->grid_levels ?? 6,
                        'grid_spacing' => $bot->grid_spacing ?? 0.25,
                        'capital' => $bot->total_capital ?? 50_000_000
                    ]);

                    // 1) Plan grid using bot's configuration
                    $plan = $planner->plan(
                        $symbol,
                        levels: (int)($bot->grid_levels ?? 6),
                        stepPct: (float)($bot->grid_spacing ?? 0.25),
                        mode: 'both',
                        budgetIrt: (int)($bot->total_capital ?? 50_000_000)
                    );

                    // 2) CRITICAL: Get existing orders FOR THIS BOT ONLY
                    // If getOpenForBot doesn't exist yet, use: $existing = $reg->getOpen($symbol);
                    // But add bot_id filtering in OrderRegistry ASAP
                    if (method_exists($reg, 'getOpenForBot')) {
                        $existing = $reg->getOpenForBot($bot->id, $symbol);
                    } else {
                        // Fallback - but this is dangerous with multiple bots!
                        $existing = $reg->getOpen($symbol);
                        Log::channel('trading')->warning('USING_UNSCOPED_ORDERS', [
                            'bot_id' => $bot->id,
                            'message' => 'OrderRegistry::getOpenForBot not implemented - orders not scoped to bot_id!'
                        ]);
                    }

                    // 3) Calculate diff
                    $diff = $sync->diff($plan, $existing, 1, 3.0);

                    // 4) Apply changes with bot_id context
                    if (method_exists($exec, 'applyForBot')) {
                        $exec->applyForBot($bot->id, $diff, simulation: $simulate);
                    } else {
                        $exec->apply($diff, simulation: $simulate);
                        Log::channel('trading')->warning('USING_UNSCOPED_APPLY', [
                            'bot_id' => $bot->id,
                            'message' => 'GridOrderExecutor::applyForBot not implemented'
                        ]);
                    }

                    Log::channel('trading')->info('ADJUST_GRID_BOT_COMPLETE', [
                        'bot_id' => $bot->id,
                        'symbol' => $symbol
                    ]);

                } catch (\Throwable $e) {
                    Log::channel('trading')->error('ADJUST_GRID_BOT_ERROR', [
                        'bot_id' => $bot->id,
                        'bot_name' => $bot->name ?? 'unknown',
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                } finally {
                    // Release per-bot lock
                    DB::select("SELECT RELEASE_LOCK(?)", [$botLockKey]);
                }
            }

            Log::channel('trading')->info('ADJUST_GRID_COMPLETE', [
                'processed_bots' => $activeBots->count()
            ]);

        } finally {
            // Release global lock
            DB::select("SELECT RELEASE_LOCK(?)", ['grid:adjust:global']);
        }
    }
}
