<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\MarketData;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

class GridPlanner
{
    public function __construct(private MarketData $md) {}

    /**
     * @param string      $symbol    مثل BTCIRT
     * @param int|null    $lastPrice اگر null باشد از MarketData خوانده می‌شود
     * @param int         $levels    کل لِول‌ها (مثلاً 6 یعنی 3 خرید + 3 فروش در حالت both)
     * @param float       $stepPct   فاصله هر پله (%). مثال: 0.25 یعنی 0.25%
     * @param string      $mode      'both' | 'buy' | 'sell'
     * @param int         $budgetIrt بودجه ریالی برای گزارش (Dry-run)
     * @param string|null $fixedQty  اگر ست شود، مقدار qty همه لول‌ها این است (مثل "0.001")
     * @param int|null    $tick      اندازه تیک (پیش‌فرض از config یا 10)
     */
    public function plan(
        string $symbol,
        ?int $lastPrice = null,
        int $levels = 6,
        float $stepPct = 0.25,
        string $mode = 'both',
        int $budgetIrt = 0,
        ?string $fixedQty = null,
        ?int $tick = null
    ): array {
        $symbol = strtoupper(trim($symbol));
        $mode   = strtolower(trim($mode));

        // --- config های مرتبط
        $tick        ??= (int) (config("trading.ticks.$symbol") ?? 10);

        // Hard-coded fallback for min_order_value_irt to handle config loading issues
        $minNotional = (int) config('trading.min_order_value_irt');
        if (empty($minNotional)) {
            Log::warning('GridPlanner: min_order_value_irt not loaded from config, using fallback: 3,000,000 IRT');
            $minNotional = 3_000_000; // 3M IRT = 300K Toman
        }

        $feeBps       = (int) (config('trading.exchange.fee_bps') ?? 35);
        $qtyDecimals  = (int) (config("trading.exchange.precision.$symbol.qty_decimals") ?? 6);

        // --- اعتبارسنجی ساده
        if (!in_array($mode, ['both', 'buy', 'sell'], true)) {
            throw new \InvalidArgumentException("Invalid mode: $mode");
        }
        if ($levels < 1) {
            throw new \InvalidArgumentException('levels must be >= 1');
        }
        if ($stepPct <= 0) {
            throw new \InvalidArgumentException('stepPct must be > 0');
        }
        // در حالت 'both' باید levels زوج باشد، چون هر سمت (خرید/فروش) نیمی از آن را می‌گیرد.
        // عدد فرد یعنی یک لول به‌صورت خاموش حذف می‌شود؛ به‌جای آن خطای واضح می‌دهیم.
        if ($mode === 'both' && $levels % 2 !== 0) {
            throw new \InvalidArgumentException("levels must be even when mode is 'both' (got {$levels})");
        }
        $tick = max(1, $tick);

        // --- قیمت مرجع
        $mid = $lastPrice ?? $this->md->getLastPrice($symbol);
        if ($mid <= 0) {
            throw new \RuntimeException("Last price not available for {$symbol}");
        }

        $perSide = $mode === 'both' ? max(1, intdiv($levels, 2)) : max(1, $levels);
        $step    = $stepPct / 100.0;

        $rawItems = [];

        // خریدها (زیر mid)
        if ($mode !== 'sell') {
            for ($i = 1; $i <= $perSide; $i++) {
                // pow() computes only the geometric SPACING factor. Its exponent is an
                // integer (it merely distributes the levels), but its base is a float and
                // bcmath has no fractional pow, so the factor stays native float. The
                // level PRICE itself (mid × factor) is computed exactly on strings via
                // Money; the tick-rounding below absorbs any residual float noise from the
                // spacing calculation.
                $factor = pow(1 - $step, $i);
                $raw    = (int) Money::round(Money::mul((string) $mid, Money::normalize($factor)), 0);
                $price  = $this->roundToTick($raw, $tick, down: true);
                $rawItems[] = ['side' => 'buy', 'price' => $price];
            }
        }

        // فروش‌ها (بالای mid)
        if ($mode !== 'buy') {
            for ($i = 1; $i <= $perSide; $i++) {
                $factor = pow(1 + $step, $i);
                $raw    = (int) Money::round(Money::mul((string) $mid, Money::normalize($factor)), 0);
                $price  = $this->roundToTick($raw, $tick, down: false);
                $rawItems[] = ['side' => 'sell', 'price' => $price];
            }
        }

        // حذف آیتم‌های تکراری روی یک تیک
        $uniq = [];
        $collapsed = 0;
        foreach ($rawItems as $it) {
            $k = $it['side'] . ':' . $it['price'];
            if (isset($uniq[$k])) { $collapsed++; continue; }
            $uniq[$k] = $it;
        }
        $items = array_values($uniq);

        // مرتب‌سازی: buy ↑ ، sell ↓ ، buyها جلوتر
        usort($items, function ($a, $b) {
            if ($a['side'] === $b['side']) {
                return $a['side'] === 'buy'
                    ? ($a['price'] <=> $b['price'])
                    : ($b['price'] <=> $a['price']);
            }
            return $a['side'] === 'buy' ? -1 : 1;
        });

        // محاسبه qty/notional برای گزارش (dry-run)
        $count       = count($items);
        $sumNotional = 0;
        $belowMinCnt = 0;

        foreach ($items as &$it) {
            $price = (int) $it['price'];

            if ($fixedQty !== null) {
                $qty      = $fixedQty;
                $notional = (int) Money::round(Money::mul((string) $price, $qty), 0);
            } elseif ($budgetIrt > 0 && $count > 0) {
                // scale 0 truncates == floor/intdiv for positive operands
                $notional = (int) Money::div((string) $budgetIrt, (string) $count, 0);
                // exact division on strings; formatQty applies the same number_format
                // rounding + trailing-zero trim as before (10 guard digits keep the round stable).
                $qtyRaw   = Money::div((string) $notional, (string) max($price, 1), $qtyDecimals + 10);
                $qty      = $this->formatQty((float) $qtyRaw, $qtyDecimals);
            } else {
                $qty      = '0';
                $notional = 0;
            }

            $belowMin = ($notional > 0 && $notional < $minNotional);
            if ($belowMin) $belowMinCnt++;

            $it['quantity']   = $qty;        // فقط گزارش
            $it['notional']   = $notional;   // فقط گزارش
            $it['below_min']  = $belowMin;   // فقط گزارش
            $sumNotional     += $notional;
        }
        unset($it);

        // exact integer product, then ceil(n / 10_000) == floor((n + 9999) / 10_000) on strings
        $feeNumerator = Money::mul((string) $sumNotional, (string) $feeBps, 0);
        $estimatedFee = (int) Money::div(Money::add($feeNumerator, '9999'), '10000', 0);

        $plan = [
            'symbol'               => $symbol,
            'mid'                  => $mid,
            'levels'               => $levels,
            'per_side'             => $perSide,
            'mode'                 => $mode,
            'step_pct'             => $stepPct,
            'tick'                 => $tick,
            'qty_decimals'         => $qtyDecimals,
            'budget_irt'           => $budgetIrt,
            'min_order_value_irt'  => $minNotional,
            'fee_bps'              => $feeBps,
            'estimated_notional'   => $sumNotional,
            'estimated_fee_irt'    => $estimatedFee,
            'collapsed_levels'     => $collapsed,
            'below_min_orders'     => $belowMinCnt,
            'items'                => $items,
            'ts'                   => now()->timestamp,
        ];

        Log::channel('trading')->info('GRID_PLAN', $plan); // فقط گزارش

        return $plan;
    }

    protected function roundToTick(int $price, int $tick, bool $down): int
    {
        if ($tick <= 1) return $price;
        $q = intdiv($price, $tick);
        $hasRemainder = ($price % $tick) !== 0;

        if ($down) {
            return $q * $tick; // floor
        }
        // ceil به نزدیک‌ترین تیک بالاتر
        return $hasRemainder ? ($q + 1) * $tick : $price;
    }

    protected function formatQty(float $qty, int $dec = 6): string
    {
        $s = number_format($qty, $dec, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
