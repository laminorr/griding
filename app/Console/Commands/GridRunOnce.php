<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

            // Live placing via NobitexService — inherits timeouts (connect+read),
            // retry policy, rate limiting, and the correct `client_ref`
            // idempotency field. Previously this was a raw Http::post that
            // bypassed all of that and sent `clientOrderId` (wrong field).
            $nobitex = app(\App\Services\NobitexService::class);

            $placed = 0; $errors = 0;
            foreach (($diff['to_place'] ?? []) as $it) {
                $side  = strtolower($it['side'] ?? 'buy');
                $price = (int)$it['price'];
                $qty   = (string)$it['quantity'];

                // Per-run-unique idempotency ref. Kept as-is (not
                // GridOrder::buildClientOrderId) because this standalone CLI run
                // has no botId and intentionally scopes ids to the run; only the
                // field NAME changes (clientOrderId → client_ref).
                $clientRef = "grid-run-{$run->id}-".uniqid();

                // Snapshot for the event log. placeOrder() derives src/dst and
                // builds the wire payload itself, so this mirrors the inputs.
                $payload = [
                    'type'        => $side,       // buy|sell
                    'execution'   => 'limit',
                    'symbol'      => $symbol,
                    'amount'      => $qty,
                    'price'       => $price,
                    'client_ref'  => $clientRef,
                ];

                $rec->event('OrderPlaceRequested', ['payload' => $payload]);

                try {
                    $res = $nobitex->placeOrder($symbol, $side, $price, $qty, $clientRef);
                } catch (\Throwable $e) {
                    // request() throws on transport/domain failures (e.g.
                    // DuplicateOrder). Record and keep going with the rest.
                    $errors++;
                    $rec->event('OrderRejected', ['error' => $e->getMessage()], 'error');
                    continue;
                }

                if (($res['status'] ?? 'failed') === 'ok') {
                    $order = $res['order'] ?? [];
                    $rec->event('OrderPlaced', ['id'=>$order['id'] ?? null,'client_ref'=>$clientRef,'status'=>$order['status'] ?? null]);
                    $rec->addOrder(array_merge($order, ['client_order_id' => $clientRef]));
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
}
