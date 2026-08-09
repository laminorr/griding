<?php

namespace App\Filament\Pages;

use App\Services\GridPlanner;
use App\Services\GridCalculatorService;
use App\Services\NobitexService;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

/**
 * ماشین‌حساب گرید — HONEST dry-run of the real bot-initialization path (P5).
 *
 * This page is a *preview* of exactly what the live bot would place: it calls
 * the SAME {@see \App\Services\GridPlanner::plan()} the real init path
 * (TradingEngineService::placeGridOrders) uses, with the SAME argument
 * derivation, but PLACES NOTHING. Every number shown traces to one of three
 * honest sources ONLY:
 *
 *   • grid levels / prices / quantities / notionals  →  GridPlanner::plan()
 *   • fees + gross-profit-per-cycle                   →  the real fee_bps
 *     (config('trading.exchange.fee_bps') = 35 → 0.35%)
 *   • risk assessment                                 →  the PURE
 *     GridCalculatorService::assessGridRisk()
 *
 * Determinism is the whole point: the plan runs on the form's fixed
 * center-price and an explicit tick, so the preview never silently reaches the
 * exchange and never drifts. The ONLY exchange call on this page is the
 * user-initiated «قیمت زنده» button, which fills the center-price field once.
 *
 * Deliberately NOT surfaced (audited as fake/heuristic — see
 * docs/p5-calculator-audit.md): daily/weekly/monthly/yearly projections,
 * success probability, grid_efficiency, cycles-per-day, USD equivalents, and
 * anything from calculateOrderSize / calculateExpectedProfit / getOptimalSettings
 * / quickMarketAnalysis (they hit the exchange and/or use the wrong hard-coded
 * 0.25% fee).
 */
class GridCalculator extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'ماشین‌حساب گرید';

    protected static ?string $title = 'ماشین‌حساب گرید';

    // Flat sidebar: no navigationGroup. Sits with the other tools/pages
    // (ConnectionTest = 3), just before the bot resource (= 5).
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.grid-calculator';

    // ---- Inputs (mirror the bot-create form's grid fields) --------------
    public string $symbol = 'BTCIRT';
    public $totalCapital = 100_000_000;      // IRT
    public $activePercent = 30;              // %
    public string $mode = 'both';            // both | buy | sell
    public $gridSpacing = 1.5;               // %
    public $gridLevels = 10;                 // even count
    public $centerPrice = null;              // manual numeric input (IRT)

    // ---- Computed results (populated by calculate()) --------------------
    public ?array $plan = null;              // GridPlanner::plan() output
    public ?array $risk = null;              // assessGridRisk() output
    public ?int $repNotional = null;         // representative per-order notional (IRT)
    public ?int $grossPerCycle = null;       // notional * spacing/100 (IRT)
    public ?int $feePerCycle = null;         // engine fee: feeRate * notional * (2 + spacing/100) (IRT)
    public ?int $netPerCycle = null;         // gross - fee (IRT)
    public ?int $feeBps = null;              // the real fee driving the numbers

    // ---- Full-round total (sum over ALL priced levels, both sides) -------
    public ?int $roundCycles = null;         // number of priced levels summed (= N placed cycles, both sides)
    public ?int $roundGrossTotal = null;     // Σ gross over all priced levels (IRT)
    public ?int $roundFeeTotal = null;       // Σ fee   over all priced levels (IRT)
    public ?int $roundNetTotal = null;       // Σ net   over all priced levels (IRT)
    public ?string $calcError = null;        // Persian error for a neutral empty state
    public bool $hasResults = false;

    public function mount(): void
    {
        // Neutral, honest starting point: nothing is calculated until the user
        // enters a center price and presses «محاسبه». No hidden exchange fetch.
    }

    /**
     * Fetch the current price ONCE and fill the center-price field. This is the
     * ONLY exchange call on the page, and it is strictly user-initiated — the
     * calculation itself always runs on the field's fixed value so results stay
     * deterministic (task requirement).
     */
    public function fetchLivePrice(): void
    {
        try {
            $price = app(NobitexService::class)->getCurrentPrice($this->symbol);

            if ($price <= 0) {
                throw new \RuntimeException('قیمت نامعتبر دریافت شد');
            }

            $this->centerPrice = (int) round($price);

            Notification::make()
                ->title('قیمت زنده دریافت شد')
                ->body('قیمت مرکزی با آخرین قیمت بازار پر شد: ' . number_format($this->centerPrice) . ' ریال')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('خطا در دریافت قیمت زنده')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Run the honest dry-run. Mirrors the real init derivation exactly:
     *   activeBudget = total_capital * active_percent / 100
     * (identical to GridCalculatorService::calculateOrderSize /
     * TradingEngineService::initializeGrid), then calls the SAME
     * GridPlanner::plan() the live bot uses — with an explicit lastPrice
     * (the form's center price) and explicit tick so the plan is deterministic
     * and tick-aligned. No fixedQty is passed, so GridPlanner produces the
     * budget-derived per-level quantity itself (the deterministic analogue of
     * the forbidden, exchange-touching calculateOrderSize).
     */
    public function calculate(): void
    {
        $this->reset(['plan', 'risk', 'repNotional', 'grossPerCycle', 'feePerCycle', 'netPerCycle', 'feeBps', 'calcError', 'roundCycles', 'roundGrossTotal', 'roundFeeTotal', 'roundNetTotal']);
        $this->hasResults = false;

        // ---- Validate inputs (Persian, matches the engine's own guards) ----
        $center  = (int) round((float) $this->centerPrice);
        $spacing = (float) $this->gridSpacing;
        $levels  = (int) $this->gridLevels;
        $capital = (float) $this->totalCapital;
        $active  = (float) $this->activePercent;
        $mode    = in_array($this->mode, ['both', 'buy', 'sell'], true) ? $this->mode : 'both';

        if ($center <= 0) {
            $this->calcError = 'برای محاسبه، قیمت مرکزی باید یک عدد مثبت باشد. می‌توانید از دکمه «قیمت زنده» استفاده کنید.';
            return;
        }
        if ($capital <= 0) {
            $this->calcError = 'سرمایه کل باید عددی مثبت باشد.';
            return;
        }
        if ($active <= 0 || $active > 100) {
            $this->calcError = 'درصد سرمایه فعال باید بین ۱ تا ۱۰۰ باشد.';
            return;
        }
        if ($spacing <= 0) {
            $this->calcError = 'فاصله بین سطوح باید بزرگ‌تر از صفر باشد.';
            return;
        }
        if ($levels < 1) {
            $this->calcError = 'تعداد سطوح باید حداقل ۱ باشد.';
            return;
        }
        if ($mode === 'both' && $levels % 2 !== 0) {
            $this->calcError = 'در حالت دوطرفه، تعداد سطوح باید زوج باشد (هر سمت نیمی از سطوح را می‌گیرد).';
            return;
        }

        // ---- Derive budget the SAME way the real init does -----------------
        // activeBudget = capital * activePercent / 100 (see calculateOrderSize).
        $activeBudget = (int) floor($capital * ($active / 100));

        // Explicit, deterministic tick from config (no bot context here, so the
        // global config tick is the honest source; GridPlanner reads the same).
        $tick = (int) (config("trading.ticks.{$this->symbol}") ?? 10);

        try {
            // The SAME planner the live bot calls in placeGridOrders(). Explicit
            // lastPrice + tick → deterministic, tick-aligned. No fixedQty → the
            // planner derives per-level qty from the budget itself.
            $plan = app(GridPlanner::class)->plan(
                $this->symbol,
                lastPrice: $center,
                levels: $levels,
                stepPct: $spacing,
                mode: $mode,
                budgetIrt: $activeBudget,
                tick: $tick,
            );
        } catch (\Throwable $e) {
            $this->calcError = 'برنامه‌ریزی گرید ممکن نشد: ' . $e->getMessage();
            return;
        }

        $this->plan   = $plan;
        $this->feeBps = (int) ($plan['fee_bps'] ?? config('trading.exchange.fee_bps'));

        // ---- Per-cycle & full-round profit — the EXACT engine formula ------
        // For ONE completed cycle on a level (CompletedTrade::createFromOrders),
        // with buyNotional = that level's notional, paired
        // sellNotional = buyNotional × (1 + spacing/100), the same qty on both
        // legs, and feeRate = fee_bps/10000:
        //     gross = buyNotional × (spacing/100)                       (= (sell−buy)×qty)
        //     fee   = feeRate × (buyNotional + sellNotional)            ← BOTH legs, own notional
        //           = feeRate × buyNotional × (2 + spacing/100)
        //     net   = gross − fee
        // The sell leg's fee is on the LARGER sell notional; the earlier
        // 2×notional×feeRate form charged both legs on the buy notional and so
        // overstated net by feeRate×gross per cycle. See docs/profit-accounting-audit.md.
        $items = $plan['items'] ?? [];
        $s = $spacing / 100;                       // spacing as a fraction
        $f = $this->feeBps / 10000;                // fee rate as a fraction

        // Representative per-cycle figure: the first priced level's notional. In
        // budget mode every level shares the same notional (budgetIrt / count,
        // truncated), so this is representative; the box keeps showing ONE cycle.
        $repNotional = 0;
        foreach ($items as $it) {
            if ((int) ($it['notional'] ?? 0) > 0) {
                $repNotional = (int) $it['notional'];
                break;
            }
        }

        if ($repNotional > 0) {
            $this->repNotional   = $repNotional;
            $this->grossPerCycle = (int) round($repNotional * $s);
            $this->feePerCycle   = (int) round($repNotional * $f * (2 + $s));
            $this->netPerCycle   = $this->grossPerCycle - $this->feePerCycle;
        }

        // Full-round total: a "full round" is EVERY placed level round-tripping
        // its cycle exactly once, so the total = Σ (engine net) over ALL priced
        // levels on BOTH sides — not per_side. The read-only audit
        // (docs/both-sides-cycle-audit.md) plus real data from bot 46 confirmed
        // that in «both» mode the bot places all N levels on init (buy + sell)
        // and sell-initiated cycles book profit identically to buy-initiated
        // ones. So a full round = N cycles (all placed levels). Below-min /
        // zero-notional levels don't place orders, so they don't count, and the
        // count is DERIVED from the levels actually summed — never hard-coded,
        // never per_side. This is a deterministic sum of already-present
        // notionals, NOT a projection to any time horizon.
        $cycleItems = array_values(array_filter(
            $items,
            fn ($it) => ((int) ($it['notional'] ?? 0)) > 0 && ! ($it['below_min'] ?? false),
        ));

        if (count($cycleItems) > 0) {
            $grossTotal = 0;
            $feeTotal   = 0;
            $netTotal   = 0;
            // Round each level's gross/fee to integers FIRST — the engine books
            // an integer profit per completed trade (CompletedTrade), so a full
            // round is the sum of those integer per-cycle records. This is the
            // SAME per-cycle rounding the representative box above uses, applied
            // once per priced level, and it keeps net == gross − fee exactly.
            foreach ($cycleItems as $it) {
                $n     = (int) $it['notional'];
                $gross = (int) round($n * $s);
                $fee   = (int) round($n * $f * (2 + $s));
                $grossTotal += $gross;
                $feeTotal   += $fee;
                $netTotal   += $gross - $fee;
            }
            $this->roundCycles     = count($cycleItems);
            $this->roundGrossTotal = $grossTotal;
            $this->roundFeeTotal   = $feeTotal;
            $this->roundNetTotal   = $netTotal; // == grossTotal − feeTotal
        }

        // ---- Risk assessment (PURE assessGridRisk) -------------------------
        $riskResult = app(GridCalculatorService::class)->assessGridRisk(
            [
                'center_price'   => $center,
                'spacing'        => $spacing,
                'levels'         => $levels,
                'active_percent' => $active,
            ],
            $capital,
        );
        $this->risk = ($riskResult['success'] ?? false) ? $riskResult : null;

        $this->hasResults = true;
    }

    /** Persian label for a GridPlanner side. */
    public static function sideLabel(string $side): string
    {
        return $side === 'buy' ? 'خرید' : ($side === 'sell' ? 'فروش' : $side);
    }

    /** Persian label for an assessGridRisk risk_level bucket. */
    public static function riskLevelLabel(string $level): string
    {
        return match ($level) {
            'very_high' => 'بسیار بالا',
            'high'      => 'بالا',
            'medium'    => 'متوسط',
            'low'       => 'پایین',
            'very_low'  => 'بسیار پایین',
            default     => $level,
        };
    }

    /** Design-token tone (pos/neg/warn/muted) for a risk_level bucket. */
    public static function riskTone(string $level): string
    {
        return match ($level) {
            'very_high', 'high' => 'neg',
            'medium'            => 'warn',
            default             => 'pos',
        };
    }
}
