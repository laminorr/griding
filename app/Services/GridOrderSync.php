<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class GridOrderSync
{
    /**
     * مقایسهٔ «پلن گرید» با «سفارشات موجود» و تولید دستور کار (place/cancel/keep).
     *
     * @param array $plan خروجی GridPlanner::plan (دارای keys: symbol,tick,min_order_value_irt,items[...])
     * @param array<int,array{id?:string,side:string,price:int,quantity:string}> $existing  از OrderRegistry
     * @param int   $toleranceTicks    تلورانس قیمتی بر حسب «تیک»
     * @param float $qtyTolerancePct   تلورانس حجمی بر حسب درصد
     * @param int|null $tick           اندازهٔ تیک (اختیاری؛ اگر null باشد از plan/config خوانده می‌شود)
     * @param int|null $minOrderValueIrt حداقل ارزش سفارش به ریال (اختیاری؛ اگر null باشد از plan/config خوانده می‌شود)
     * @return array ساختار خروجی شامل to_place/to_cancel/keep + آمار
     */
    public function diff(
        array $plan,
        array $existing,
        int $toleranceTicks = 1,
        float $qtyTolerancePct = 3.0,
        ?int $tick = null,
        ?int $minOrderValueIrt = null,
    ): array {
        $symbol = (string) ($plan['symbol'] ?? 'UNKNOWN');

        // تعیین tick و min IRT از پارامتر → plan → config
        $tick = (int) ($tick
            ?? ($plan['tick'] ?? (int) config("trading.ticks.$symbol", 1)));
        if ($tick <= 0) { $tick = 1; }

        // Hard-coded fallback for min_order_value_irt to handle config loading issues
        $minIrt = (int) ($minOrderValueIrt ?? ($plan['min_order_value_irt'] ?? null));
        if (empty($minIrt)) {
            $minIrt = (int) config('trading.exchange.min_order_value_irt');
            if (empty($minIrt)) {
                Log::warning('GridOrderSync: min_order_value_irt not loaded from config, using fallback: 3,000,000 IRT');
                $minIrt = 3_000_000; // 3M IRT = 300K Toman
            }
        }

        $planItems = (array) ($plan['items'] ?? []);

        $toPlace  = [];
        $toCancel = [];
        $keep     = [];
        $skippedBelowMin = 0;

        // مارکر استفاده‌شده برای existing
        $used = array_fill(0, count($existing), false);

        // 1) برای هر آیتمِ پلن دنبال مچ در existing بگرد
        foreach ($planItems as $pi) {
            $piSide    = (string) ($pi['side'] ?? '');
            $piPrice   = (int)    ($pi['price'] ?? 0);
            $piQtyStr  = (string) ($pi['quantity'] ?? '0');
            $piQty     = (float)  $piQtyStr;

            // اگر notional در پلن حاضر نبود، خودمان حساب می‌کنیم
            $piNotional = (int) ($pi['notional'] ?? (int) round($piQty * $piPrice));

            $matchIdx = null;

            foreach ($existing as $idx => $eo) {
                if ($used[$idx]) continue;
                if ((string) ($eo['side'] ?? '') !== $piSide) continue;

                $ePrice = (int)    ($eo['price'] ?? 0);
                $eQty   = (float)  ((string) ($eo['quantity'] ?? '0'));

                $priceOk = (abs($ePrice - $piPrice) <= $toleranceTicks * $tick);
                $qtyOk   = $this->qtyClose($eQty, $piQty, $qtyTolerancePct);

                if ($priceOk && $qtyOk) {
                    $matchIdx = $idx;
                    break;
                }
            }

            if ($matchIdx !== null) {
                $used[$matchIdx] = true;
                $keep[] = $existing[$matchIdx] + ['reason' => 'match'];
            } else {
                if ($piNotional < $minIrt) {
                    $skippedBelowMin++;
                    // می‌تونی اگر خواستی این آیتم‌ها رو هم لاگ کنی
                    continue;
                }
                $toPlace[] = [
                    'symbol'   => $symbol,
                    'side'     => $piSide,
                    'price'    => $piPrice,
                    'quantity' => $piQtyStr,
                    'notional' => $piNotional,
                    'reason'   => 'missing',
                ];
            }
        }

        // 2) هر existing که مچ نشد → to_cancel
        foreach ($existing as $idx => $eo) {
            if (!$used[$idx]) {
                $toCancel[] = $eo + ['reason' => 'not_in_plan'];
            }
        }

        $out = [
            'symbol'              => $symbol,
            'tick'                => $tick,
            'tolerance_ticks'     => $toleranceTicks,
            'qty_tolerance_pct'   => $qtyTolerancePct,
            'min_order_value_irt' => $minIrt,
            'counts'  => [
                'plan_items'        => count($planItems),
                'existing'          => count($existing),
                'skipped_below_min' => $skippedBelowMin,
                'to_place'          => count($toPlace),
                'to_cancel'         => count($toCancel),
                'keep'              => count($keep),
            ],
            'to_place' => $toPlace,
            'to_cancel'=> $toCancel,
            'keep'     => $keep,
            'ts'       => time(),
        ];

        Log::channel('trading')->info('GRID_DIFF', $out);
        return $out;
    }

    /**
     * آیا دو مقدارِ حجم به اندازهٔ pct% به هم نزدیک‌اند؟
     */
    protected function qtyClose(float $a, float $b, float $pct): bool
    {
        if ($a == 0.0 && $b == 0.0) return true;
        $den = max(abs($a), abs($b), 1e-12);
        $diffPct = (abs($a - $b) / $den) * 100.0;
        return $diffPct <= $pct;
    }
}
