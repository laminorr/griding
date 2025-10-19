<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Support\GridRunRecorder as Recorder;

class GridRunOnce extends Command
{
    protected $signature = 'grid:run 
        {symbol=BTCIRT}
        {--mode=buy : buy|sell|both}
        {--levels=3}
        {--budget=24000000}
        {--step=0.25 : step percent (e.g. 0.25)}
        {--live : place real orders (omit for dry-run)}';

    protected $description = 'Run plan → diff → (apply) once with full event recording';

    public function handle(): int
    {
        $symbol = strtoupper((string)$this->argument('symbol'));
        $mode   = strtolower((string)$this->option('mode'));
        $levels = (int)$this->option('levels');
        $budget = (int)$this->option('budget');
        $step   = (float)$this->option('step');
        $live   = (bool)$this->option('live');
        $sim    = !$live;

        // 1) شروع رکوردر
        $rec = Recorder::start([
            'symbol'     => $symbol,
            'mode'       => $mode,
            'levels'     => $levels,
            'step_pct'   => $step,
            'budget_irt' => $budget,
            'simulation' => $sim,
        ]);
        $run = $rec->run();
        $this->info("RUN #{$run->id} trace={$run->trace_id} sim=".($sim?'yes':'no'));

        try {
            // 2) Plan
            $planner = app(\App\Services\GridPlanner::class);
            $plan = $planner->plan($symbol, levels: $levels, stepPct: $step, mode: $mode, budgetIrt: $budget);
            $rec->event('PlanCreated', ['items' => count($plan['items'] ?? [])])
                ->attachPlan($plan);

            // 3) Diff (فعلاً فرض می‌کنیم سفارش باز نداریم؛ میشه بعداً وصل کرد)
            $sync = app(\App\Services\GridOrderSync::class);
            $diff = $sync->diff($plan, []);
            $counts = $diff['counts'] ?? [];
            $rec->event('DiffComputed', $counts)->attachDiff($diff);

            // 4) Apply
            if ($sim) {
                // Dry-run
                $rec->event('DryRun', ['to_place' => count($diff['to_place'] ?? [])]);
                $rec->finish('ok', ['placed'=>0,'canceled'=>0,'simulation'=>true]);
                $this->line('Dry-run complete.');
                return self::SUCCESS;
            }

            // Live placing via Nobitex API
            $headers = [
                'Authorization' => 'Token ' . config('trading.nobitex.api_key'),
                'Content-Type'  => 'application/json',
            ];

            [$src,$dst] = $this->pairFromSymbol($symbol); // e.g., BTCIRT → ['btc','rls']

            $placed = 0; $errors = 0;
            foreach (($diff['to_place'] ?? []) as $it) {
                $side  = strtolower($it['side'] ?? 'buy');
                $price = (int)$it['price'];
                $qty   = (string)$it['quantity'];

                $payload = [
                    'type'         => $side,       // buy|sell
                    'execution'    => 'limit',
                    'srcCurrency'  => $src,
                    'dstCurrency'  => $dst,
                    'amount'       => $qty,
                    'price'        => $price,
                    'clientOrderId'=> "grid-run-{$run->id}-".uniqid(),
                ];

                $rec->event('OrderPlaceRequested', ['payload' => $payload]);

                $res = Http::withHeaders($headers)
                    ->post('https://apiv2.nobitex.ir/market/orders/add', $payload)
                    ->json();

                if (($res['status'] ?? 'failed') === 'ok') {
                    $order = $res['order'] ?? [];
                    $rec->event('OrderPlaced', ['id'=>$order['id'] ?? null,'clientOrderId'=>$payload['clientOrderId'],'status'=>$order['status'] ?? null]);
                    $rec->addOrder(array_merge($order, ['clientOrderId' => $payload['clientOrderId']]));
                    $placed++;
                } else {
                    $errors++;
                    $rec->event('OrderRejected', ['response' => $res], 'error');
                }
            }

            // 5) پایان
            $rec->finish('ok', ['placed'=>$placed,'errors'=>$errors,'simulation'=>false]);
            $this->line("Live run complete. placed={$placed} errors={$errors}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $rec->fail($e);
            $this->error('Run failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function pairFromSymbol(string $symbol): array
    {
        // BTCIRT → ['btc','rls'], ETHUSDT → ['eth','usdt'] (fallback ساده)
        $symbol = strtoupper($symbol);
        if (str_ends_with($symbol, 'IRT') || str_ends_with($symbol, 'RLS')) {
            $base = strtolower(substr($symbol, 0, -3)); // BTC → btc
            return [$base, 'rls'];
        }
        // generic split: assume AAA/BBB of 3+3
        $base = strtolower(substr($symbol, 0, 3));
        $quote = strtolower(substr($symbol, 3));
        return [$base, $quote ?: 'rls'];
    }
}
