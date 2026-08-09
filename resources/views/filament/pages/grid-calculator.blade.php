{{--
    ماشین‌حساب گرید — HONEST dry-run of the real init path (P5).

    Every number rendered here traces to exactly ONE honest source:
      • levels / prices / quantities / notionals  → GridPlanner::plan()
      • fees + gross-profit-per-cycle              → the real fee_bps (0.35%)
      • risk level + factors                       → assessGridRisk()
    No daily/monthly projections, no success probability, no USD, no efficiency
    — those were audited as fake/heuristic and are deliberately absent.

    Styling reuses the existing compact terminal design tokens
    (resources/views/filament/theme/admin-terminal.blade.php); the only new CSS
    is a handful of input classes built ON those tokens (no new style system).
    Persian digits via Str::faDigits() / the @fa directive (never a bare
    faDigits()).
--}}
<x-filament-panels::page>
    @php
        $fa  = fn ($n) => \Illuminate\Support\Str::faDigits((string) $n);
        $fmt = fn ($n) => \Illuminate\Support\Str::faDigits(number_format((float) $n));
        $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.');
    @endphp

    <style>
        /* Page-scoped inputs — built on the shared --at-* tokens, not a new system. */
        .calc-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: var(--at-gap-md);
        }
        .calc-field { display: flex; flex-direction: column; gap: 5px; }
        .calc-field > label {
            font-size: var(--at-fs-label);
            font-weight: 600;
            color: var(--at-muted);
        }
        .calc-input, .calc-select {
            inline-size: 100%;
            block-size: 40px;
            border: 1px solid var(--at-border-strong);
            border-radius: var(--at-radius-sm);
            background: var(--at-surface-2);
            color: var(--at-text);
            padding-inline: 12px;
            font-family: 'Vazirmatn', system-ui, sans-serif;
            font-size: var(--at-fs-body);
            line-height: 40px;
            outline: none;
            transition: border-color .15s ease, background .15s ease;
        }
        .calc-input { direction: ltr; text-align: start; font-variant-numeric: tabular-nums; }
        .calc-input:focus, .calc-select:focus { border-color: var(--at-accent-line); }

        /* Native <select> reset: strip EVERY built-in indicator — the browser's
           own arrow AND any Filament / @tailwindcss/forms background chevron — so
           the ONLY arrow is the single CSS chevron drawn by .calc-select-wrap::after
           below. Without this the panel's select styling stacked a second (and a
           third) arrow on top of ours and the option text ran under them. Scoped to
           .calc-select so no other panel select is touched. */
        .calc-select {
            appearance: none !important;
            -webkit-appearance: none !important;
            background-image: none !important;
            /* Start-side gutter (physically the right edge in this RTL panel)
               reserves room for the chevron so the option text is never clipped. */
            padding-inline: 40px 12px;
            text-align: start;
            cursor: pointer;
        }
        .calc-select::-ms-expand { display: none; } /* legacy Edge/IE arrow */
        .calc-select option { background: var(--at-surface); color: var(--at-text); }

        /* Single RTL chevron — same pattern + tokens as the panel's .at-botselect
           (chevron on the inline-start edge = physically right in the RTL panel). */
        .calc-select-wrap { position: relative; inline-size: 100%; }
        .calc-select-wrap::after {
            content: "";
            position: absolute;
            inset-block-start: 50%;
            inset-inline-start: 16px;
            inline-size: 7px;
            block-size: 7px;
            border-inline-end: 1.5px solid var(--at-muted);
            border-block-end: 1.5px solid var(--at-muted);
            transform: translateY(-65%) rotate(45deg);
            pointer-events: none;
        }
        .calc-field__hint { font-size: 10.5px; color: var(--at-muted); }
        .calc-price-row { display: flex; gap: var(--at-gap-sm); align-items: stretch; }
        .calc-price-row .calc-input { flex: 1; min-inline-size: 0; }
        .calc-actions { display: flex; gap: var(--at-gap-sm); flex-wrap: wrap; align-items: center; }
        .calc-note {
            font-size: 11px;
            color: var(--at-muted);
            line-height: 1.6;
        }

        /* ============================================================
           Grid schematic (escalator) — P5 visual re-render of the SAME
           plan items the table above renders. Built entirely on the shared
           --at-* tokens (no new style system, no viteTheme). Every number
           traces to a plan/risk variable; the only arithmetic here is the
           display-only %-distance label = (price − mid) ÷ mid × 100, done in
           the Blade below on values already present. RTL-first via logical
           properties. ============================================================ */

        /* Compact summary strip above the diagram (all values already shown). */
        .sch-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: var(--at-gap-sm);
        }
        .sch-sum-item {
            border: 1px solid var(--at-border);
            border-radius: var(--at-radius-sm);
            background: var(--at-surface-2);
            padding: 7px 10px;
        }
        .sch-sum-item > label {
            display: block;
            font-size: var(--at-fs-label);
            color: var(--at-muted);
            margin-block-end: 3px;
        }
        .sch-sum-item strong {
            display: inline-block;
            font-size: var(--at-fs-body);
            font-weight: 700;
            color: var(--at-text);
            direction: ltr;
            font-variant-numeric: tabular-nums;
        }
        .sch-sum-item small {
            display: block;               /* own line — keeps «۴» and «۲ در هر سمت» from merging */
            margin-block-start: 2px;
            color: var(--at-muted);
            font-size: var(--at-fs-label);
        }

        /* Escalator canvas: sells on top (red) → center (blue) → buys (green). */
        .sch-diagram {
            border: 1px solid var(--at-border);
            border-radius: var(--at-radius);
            background: var(--at-bg);
            padding: var(--at-gap-md) var(--at-gap-lg);
            overflow: hidden;              /* never let a track/marker clip the page */
        }
        .sch-levels { display: flex; flex-direction: column; }

        .sch-level {
            display: grid;
            grid-template-columns: 84px 1fr 172px 74px;
            gap: var(--at-gap-md);
            align-items: center;
            min-block-size: 56px;
        }
        .sch-name { font-size: var(--at-fs-body); font-weight: 700; white-space: nowrap; }
        .sch-name.sell   { color: var(--at-neg); }
        .sch-name.buy    { color: var(--at-accent); }
        .sch-name.center { color: var(--at-blue); }

        /* Per-side colour drives the line + marker via a single custom prop. */
        .sch-level.sell   { --c: var(--at-neg); }
        .sch-level.buy    { --c: var(--at-accent); }
        .sch-level.center { --c: var(--at-blue); }

        .sch-track { position: relative; block-size: 22px; display: flex; align-items: center; }
        .sch-line {
            inline-size: 100%;
            block-size: 2px;
            border-radius: 2px;
            background: var(--c);
            opacity: 0.5;
        }
        .sch-marker {
            position: absolute;
            inset-inline-start: 14%;
            inline-size: 13px;
            block-size: 13px;
            border-radius: 50%;
            background: var(--at-bg);
            border: 3px solid var(--c);
        }

        .sch-price { text-align: end; direction: ltr; min-inline-size: 0; }
        .sch-price b {
            display: block;
            font-size: var(--at-fs-body);
            font-weight: 700;
            color: var(--at-text);
            font-variant-numeric: tabular-nums;
        }
        .sch-price span {
            display: block;
            margin-block-start: 2px;
            font-size: 10px;
            color: var(--at-muted);
            font-variant-numeric: tabular-nums;
        }

        .sch-pct {
            text-align: end;
            direction: ltr;
            font-size: var(--at-fs-body);
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .sch-pct.sell   { color: var(--at-neg); }
        .sch-pct.buy    { color: var(--at-accent); }
        .sch-pct.center { color: var(--at-blue); }

        /* «۱.۵٪ فاصله» chip between consecutive levels. */
        .sch-gap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--at-gap-sm);
            min-block-size: 24px;
        }
        .sch-gap-line {
            inline-size: 44px;
            max-inline-size: 12vw;
            border-block-start: 1px dashed var(--at-border-strong);
        }
        .sch-gap-chip {
            padding: 2px 8px;
            border: 1px solid var(--at-border);
            border-radius: 999px;
            background: var(--at-surface);
            color: var(--at-muted);
            font-size: 10px;
            white-space: nowrap;
        }

        .sch-legend {
            display: flex;
            gap: var(--at-gap-lg);
            flex-wrap: wrap;
            color: var(--at-muted);
            font-size: var(--at-fs-label);
        }
        .sch-legend span { display: inline-flex; align-items: center; gap: 5px; }
        .sch-legend i { inline-size: 8px; block-size: 8px; border-radius: 50%; display: inline-block; }

        @media (max-width: 640px) {
            .sch-level { grid-template-columns: 58px 1fr 118px 54px; gap: var(--at-gap-sm); }
            .sch-name { font-size: var(--at-fs-label); }
            .sch-price b { font-size: var(--at-fs-label); }
        }

        /* ============================================================
           Full-round total — the green headline box (LAST section). Built
           on the shared --at-* tokens (green accent = --at-accent #21d48b,
           navy surface, --at-radius 16, Vazirmatn from the panel). The green
           border + inset ring + faint top wash mark it as the page's summary
           figure; no new colour system, no viteTheme. ==================== */
        .calc-round {
            border-color: var(--at-accent-line);
            background:
                linear-gradient(180deg, var(--at-accent-dim), transparent 62%),
                var(--at-surface);
            box-shadow: inset 0 0 0 1px var(--at-accent-line);
        }
        .calc-round .panel-section__title { color: var(--at-accent); }

        .calc-round__net {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: var(--at-gap-sm);
        }
        .calc-round__net-label {
            font-size: var(--at-fs-body);
            font-weight: 600;
            color: var(--at-text-dim);
        }
        .calc-round__net-value {
            font-size: 26px;
            font-weight: 800;
            line-height: 1.1;
            color: var(--at-accent);
            direction: ltr;
            font-variant-numeric: tabular-nums;
        }
        .calc-round__net-unit { font-size: var(--at-fs-body); color: var(--at-muted); }

        .calc-round__subs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: var(--at-gap-sm);
        }
        .calc-round__sub {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: var(--at-gap-sm);
            border: 1px solid var(--at-border);
            border-radius: var(--at-radius-sm);
            background: var(--at-surface-2);
            padding: 7px 10px;
        }
        .calc-round__sub > span { font-size: var(--at-fs-label); color: var(--at-muted); }
        .calc-round__sub > strong {
            direction: ltr;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .calc-round__sub > strong.pos { color: var(--at-pos); }
        .calc-round__sub > strong.neg { color: var(--at-neg); }
        .calc-round__caption { margin-block-start: 0; }
    </style>

    <div class="at-stack">

        {{-- ============ INPUTS ============ --}}
        <div class="panel-section">
            <div class="panel-section__head">
                <span class="panel-section__title">ورودی‌ها</span>
                <span class="panel-section__sub">مطابق فرم ساخت ربات — محاسبه روی همین مقادیر ثابت انجام می‌شود</span>
            </div>
            <div class="panel-section__body at-stack">
                <div class="calc-form-grid">
                    <div class="calc-field">
                        <label>سرمایه کل (ریال)</label>
                        <input type="number" class="calc-input" wire:model="totalCapital" min="0" step="1000000">
                    </div>

                    <div class="calc-field">
                        <label>درصد سرمایه فعال</label>
                        <input type="number" class="calc-input" wire:model="activePercent" min="1" max="100" step="1">
                        <span class="calc-field__hint">بودجه فعال = سرمایه کل × درصد ÷ ۱۰۰</span>
                    </div>

                    <div class="calc-field">
                        <label>حالت معاملاتی</label>
                        <div class="calc-select-wrap">
                            <select class="calc-select" wire:model="mode">
                                <option value="both">دوطرفه (خرید + فروش)</option>
                                <option value="buy">فقط خرید</option>
                                <option value="sell">فقط فروش</option>
                            </select>
                        </div>
                    </div>

                    <div class="calc-field">
                        <label>فاصله بین سطوح (٪)</label>
                        <input type="number" class="calc-input" wire:model="gridSpacing" min="0.1" step="0.1">
                    </div>

                    <div class="calc-field">
                        <label>تعداد سطوح</label>
                        <div class="calc-select-wrap">
                            <select class="calc-select" wire:model="gridLevels">
                                @foreach ([4, 6, 8, 10, 12, 16, 20] as $lv)
                                    <option value="{{ $lv }}">{{ $fa($lv) }} سطح</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="calc-field">
                        <label>قیمت مرکزی (ریال)</label>
                        <div class="calc-price-row">
                            <input type="number" class="calc-input" wire:model="centerPrice" min="0" step="{{ (int) (config('trading.ticks.'.$symbol) ?? 10) }}" placeholder="مثلاً ۱۰۰٬۰۰۰٬۰۰۰">
                            <button type="button" class="at-btn" wire:click="fetchLivePrice" wire:loading.attr="disabled" wire:target="fetchLivePrice">
                                <span wire:loading.remove wire:target="fetchLivePrice">قیمت زنده</span>
                                <span wire:loading wire:target="fetchLivePrice">در حال دریافت…</span>
                            </button>
                        </div>
                        <span class="calc-field__hint">محاسبه روی همین عدد ثابت اجرا می‌شود؛ دریافت قیمت فقط یک‌بار و با فشردن دکمه است.</span>
                    </div>
                </div>

                <div class="calc-actions">
                    <button type="button" class="at-btn at-btn--accent" wire:click="calculate" wire:loading.attr="disabled" wire:target="calculate">
                        <span wire:loading.remove wire:target="calculate">محاسبه</span>
                        <span wire:loading wire:target="calculate">در حال محاسبه…</span>
                    </button>
                    <span class="calc-note">این ابزار چیزی ثبت نمی‌کند؛ فقط همان چیزی را که ربات واقعی می‌چیند، پیش‌نمایش می‌دهد.</span>
                </div>
            </div>
        </div>

        {{-- ============ EMPTY / ERROR STATE ============ --}}
        @if ($calcError)
            <div class="panel-section">
                <div class="panel-section__body">
                    <div class="at-empty">
                        <div class="at-empty__icon">⚠️</div>
                        <div>{{ $calcError }}</div>
                    </div>
                </div>
            </div>
        @elseif (! $hasResults)
            <div class="panel-section">
                <div class="panel-section__body">
                    <div class="at-empty">
                        <div class="at-empty__icon">🧮</div>
                        <div>مقادیر را وارد کنید و «محاسبه» را بزنید تا سطوح گرید، کارمزد و ارزیابی ریسک نمایش داده شود.</div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ RESULTS ============ --}}
        @if ($hasResults && $plan)
            @php
                $items   = $plan['items'] ?? [];
                $feeRate = $feeBps !== null ? $feeBps / 100 : null; // bps → percent
            @endphp

            {{-- Plan summary (all straight from GridPlanner::plan) --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <span class="panel-section__title">خلاصه برنامه گرید</span>
                    <span class="at-badge muted">{{ $plan['symbol'] }}</span>
                </div>
                <div class="panel-section__body">
                    <div class="metric-grid">
                        <div class="metric-card is-row">
                            <span class="metric-label">قیمت مرکزی</span>
                            <span class="metric-value" style="direction:ltr;">{{ $fmt($plan['mid']) }}</span>
                            <span class="metric-sub">ریال</span>
                        </div>
                        <div class="metric-card is-row">
                            <span class="metric-label">اندازه تیک</span>
                            <span class="metric-value" style="direction:ltr;">{{ $fmt($plan['tick']) }}</span>
                        </div>
                        {{-- «تعداد سطوح» is the TOTAL number of levels, matching the
                             engine's own meaning of `levels` (the live init path passes
                             bot_configs.grid_levels as the total, split per-side inside
                             GridPlanner) and matching the input above and the rows in
                             the levels table below. It reads count($items) — the levels
                             actually planned — so the selected count, the planned count,
                             and this summary always show the SAME number (any tick
                             collapse is surfaced separately by the badge below). --}}
                        <div class="metric-card is-row">
                            <span class="metric-label">تعداد سطوح</span>
                            <span class="metric-value">{{ $fa(count($items)) }}</span>
                            @if (($plan['mode'] ?? 'both') === 'both')
                                <span class="metric-sub">{{ $fa($plan['per_side']) }} در هر سمت</span>
                            @endif
                        </div>
                        <div class="metric-card is-row">
                            <span class="metric-label">بودجه فعال</span>
                            <span class="metric-value" style="direction:ltr;">{{ $fmt($plan['budget_irt']) }}</span>
                            <span class="metric-sub">ریال</span>
                        </div>
                        <div class="metric-card is-row">
                            <span class="metric-label">مجموع ارزش سفارش‌ها</span>
                            <span class="metric-value" style="direction:ltr;">{{ $fmt($plan['estimated_notional']) }}</span>
                            <span class="metric-sub">ریال</span>
                        </div>
                        <div class="metric-card is-row">
                            <span class="metric-label">حداقل ارزش هر سفارش</span>
                            <span class="metric-value" style="direction:ltr;">{{ $fmt($plan['min_order_value_irt']) }}</span>
                            <span class="metric-sub">ریال</span>
                        </div>
                    </div>

                    @if (($plan['below_min_orders'] ?? 0) > 0 || ($plan['collapsed_levels'] ?? 0) > 0)
                        <div class="at-row" style="margin-block-start: var(--at-gap-md); flex-wrap: wrap; gap: var(--at-gap-sm);">
                            @if (($plan['below_min_orders'] ?? 0) > 0)
                                <span class="at-badge warn">{{ $fa($plan['below_min_orders']) }} سفارش زیر حداقل ارزش</span>
                            @endif
                            @if (($plan['collapsed_levels'] ?? 0) > 0)
                                <span class="at-badge muted">{{ $fa($plan['collapsed_levels']) }} سطح ادغام‌شده روی یک تیک</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- 1) Grid levels table — exactly what the bot would place --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <span class="panel-section__title">سطوح گرید</span>
                    <span class="panel-section__sub">قیمت‌ها تیک‌تراز شده — همان چیزی که ربات ثبت می‌کند</span>
                </div>
                <div class="panel-section__body">
                    @if (count($items) === 0)
                        <div class="at-empty">
                            <div class="at-empty__icon">—</div>
                            <div>هیچ سطحی تولید نشد.</div>
                        </div>
                    @else
                        <div class="at-scroll" style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>سمت</th>
                                        <th class="at-num">قیمت (ریال)</th>
                                        <th class="at-num">مقدار</th>
                                        <th class="at-num">ارزش (ریال)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $i => $it)
                                        @php
                                            $side = $it['side'] ?? '';
                                            $tone = $side === 'buy' ? 'pos' : 'neg';
                                        @endphp
                                        <tr>
                                            <td class="at-num">{{ $fa($i + 1) }}</td>
                                            <td>
                                                <span class="at-badge {{ $tone }}">
                                                    <span class="at-dot {{ $tone }}"></span>
                                                    {{ \App\Filament\Pages\GridCalculator::sideLabel($side) }}
                                                </span>
                                                @if ($it['below_min'] ?? false)
                                                    <span class="at-badge warn" style="margin-inline-start:4px;">زیر حداقل</span>
                                                @endif
                                            </td>
                                            <td class="at-num">{{ $fmt($it['price'] ?? 0) }}</td>
                                            <td class="at-num">{{ $fa($trim($it['quantity'] ?? '0')) }}</td>
                                            <td class="at-num">{{ $fmt($it['notional'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- 1b) Grid schematic (escalator) — a VISUAL re-render of the exact
                 same $items the table above loops over. Nothing new is computed:
                 price / quantity / notional are read straight off each plan item,
                 the center price is $plan['mid'], and the only arithmetic is the
                 display-only per-level %-distance = (price − mid) ÷ mid × 100
                 (tick-alignment is why the outer levels read e.g. +۳.۰۲٪ / −۲.۹۸٪
                 rather than exactly ±۳٪). Order top→bottom is price-descending:
                 highest sell → … → center → … → lowest buy, matching the mockup.
                 The mockup's invented «کنترل ریسک» card (market-risk score / stop
                 loss / recommendation copy not produced in that form) is omitted;
                 fee and risk keep their own honest sections below. --}}
            @if (count($items) > 0)
                @php
                    $schSells = array_values(array_filter($items, fn ($it) => ($it['side'] ?? '') === 'sell'));
                    $schBuys  = array_values(array_filter($items, fn ($it) => ($it['side'] ?? '') === 'buy'));
                    // Display order: sells high→low (top), then center, then buys high→low.
                    usort($schSells, fn ($a, $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));
                    usort($schBuys,  fn ($a, $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));

                    $schCenter = (int) ($plan['mid'] ?? 0);
                    $schBase   = strtoupper(str_replace('IRT', '', (string) ($plan['symbol'] ?? '')));
                    $schStep   = $fa($trim($plan['step_pct'] ?? $gridSpacing));
                    $schMode   = match ($plan['mode'] ?? 'both') {
                        'both'  => 'دوطرفه (خرید + فروش)',
                        'buy'   => 'فقط خرید',
                        'sell'  => 'فقط فروش',
                        default => (string) ($plan['mode'] ?? ''),
                    };

                    // Ordered rows with per-side level numbers counted from the
                    // center outward (level ۱ = nearest the center), exactly like
                    // the mockup («فروش ۲» outer, «فروش ۱» inner, …).
                    $schRows  = [];
                    $nSells   = count($schSells);
                    foreach ($schSells as $i => $it) {
                        $schRows[] = ['kind' => 'sell', 'num' => $nSells - $i, 'it' => $it];
                    }
                    $schRows[] = ['kind' => 'center'];
                    foreach ($schBuys as $i => $it) {
                        $schRows[] = ['kind' => 'buy', 'num' => $i + 1, 'it' => $it];
                    }

                    // Display-only %-distance label off the tick-aligned price.
                    $schPct = function ($price) use ($schCenter, $fa) {
                        if ($schCenter <= 0) {
                            return $fa('0') . '٪';
                        }
                        $v    = ($price - $schCenter) / $schCenter * 100;
                        $sign = $v > 0 ? '+' : ($v < 0 ? '−' : '');
                        return $sign . $fa(number_format(abs($v), 2)) . '٪';
                    };
                @endphp

                <div class="panel-section">
                    <div class="panel-section__head">
                        <span class="panel-section__title">شماتیک ساختار گرید</span>
                        <span class="at-badge muted">{{ $plan['symbol'] }} · {{ $schMode }}</span>
                    </div>
                    <div class="panel-section__body at-stack">
                        <span class="panel-section__sub">
                            نمای پلکانی همان سطوحی که در جدول بالا محاسبه شد — قیمت‌ها تیک‌تراز؛ درصد هر سطح نسبت به قیمت مرکزی.
                        </span>

                        {{-- Summary strip — every value is one already shown above. --}}
                        <div class="sch-summary">
                            <div class="sch-sum-item">
                                <label>قیمت مرکزی</label>
                                <strong>{{ $fmt($plan['mid']) }}</strong> <small>ریال</small>
                            </div>
                            <div class="sch-sum-item">
                                <label>تعداد سطوح</label>
                                <strong>{{ $fa(count($items)) }}</strong>
                                @if (($plan['mode'] ?? 'both') === 'both')
                                    <small>{{ $fa($plan['per_side']) }} در هر سمت</small>
                                @endif
                            </div>
                            <div class="sch-sum-item">
                                <label>فاصله بین سطوح</label>
                                <strong>{{ $schStep }}٪</strong>
                            </div>
                            <div class="sch-sum-item">
                                <label>بودجه فعال</label>
                                <strong>{{ $fmt($plan['budget_irt']) }}</strong> <small>ریال</small>
                            </div>
                            <div class="sch-sum-item">
                                <label>ارزش هر سفارش</label>
                                <strong>{{ $repNotional !== null ? $fmt($repNotional) : '—' }}</strong>
                                @if ($repNotional !== null)<small>ریال</small>@endif
                            </div>
                            <div class="sch-sum-item">
                                <label>اندازه تیک</label>
                                <strong>{{ $fmt($plan['tick']) }}</strong>
                            </div>
                        </div>

                        {{-- The escalator itself. --}}
                        <div class="sch-diagram">
                            <div class="sch-levels">
                                @foreach ($schRows as $ri => $row)
                                    @if ($row['kind'] === 'center')
                                        <div class="sch-level center">
                                            <div class="sch-name center">قیمت مرکزی</div>
                                            <div class="sch-track"><span class="sch-line"></span><span class="sch-marker"></span></div>
                                            <div class="sch-price"><b>{{ $fmt($schCenter) }} ریال</b></div>
                                            <div class="sch-pct center">{{ $fa('0') }}٪</div>
                                        </div>
                                    @else
                                        @php $it = $row['it']; $kind = $row['kind']; @endphp
                                        <div class="sch-level {{ $kind }}">
                                            <div class="sch-name {{ $kind }}">
                                                {{ $kind === 'sell' ? 'فروش' : 'خرید' }} {{ $fa($row['num']) }}
                                            </div>
                                            <div class="sch-track"><span class="sch-line"></span><span class="sch-marker"></span></div>
                                            <div class="sch-price">
                                                <b>{{ $fmt($it['price'] ?? 0) }} ریال</b>
                                                <span>{{ $fa($trim($it['quantity'] ?? '0')) }} {{ $schBase }} · {{ $fmt($it['notional'] ?? 0) }} ریال</span>
                                            </div>
                                            <div class="sch-pct {{ $kind }}">{{ $schPct($it['price'] ?? 0) }}</div>
                                        </div>
                                    @endif

                                    @if ($ri !== count($schRows) - 1)
                                        <div class="sch-gap">
                                            <span class="sch-gap-line"></span>
                                            <span class="sch-gap-chip">{{ $schStep }}٪ فاصله</span>
                                            <span class="sch-gap-line"></span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        <div class="sch-legend">
                            <span><i style="background:var(--at-accent)"></i> خرید</span>
                            <span><i style="background:var(--at-neg)"></i> فروش</span>
                            <span><i style="background:var(--at-blue)"></i> قیمت مرکزی</span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 2 & 3) Fees + gross profit per cycle --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <span class="panel-section__title">کارمزد و سود هر چرخه</span>
                    <span class="at-badge muted">کارمزد: {{ $fa($trim($feeRate)) }}٪ (fee_bps = {{ $fa($feeBps) }})</span>
                </div>
                <div class="panel-section__body">
                    @if ($grossPerCycle !== null)
                        <div class="metric-grid">
                            <div class="metric-card is-row">
                                <span class="metric-label">ارزش هر سفارش (مبنا)</span>
                                <span class="metric-value" style="direction:ltr;">{{ $fmt($repNotional) }}</span>
                                <span class="metric-sub">ریال</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">سود ناخالص هر چرخه</span>
                                <span class="metric-value pos" style="direction:ltr;">{{ $fmt($grossPerCycle) }}</span>
                                <span class="metric-sub">ریال</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">کارمزد هر چرخه (۲ طرف)</span>
                                <span class="metric-value neg" style="direction:ltr;">{{ $fmt($feePerCycle) }}</span>
                                <span class="metric-sub">ریال</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">سود خالص هر چرخه</span>
                                <span class="metric-value {{ $netPerCycle >= 0 ? 'pos' : 'neg' }}" style="direction:ltr;">{{ $fmt($netPerCycle) }}</span>
                                <span class="metric-sub">ریال</span>
                            </div>
                        </div>
                        <p class="calc-note" style="margin-block-start: var(--at-gap-md);">
                            سود ناخالص هر چرخه = ارزش سفارش × (فاصله ÷ ۱۰۰) = {{ $fmt($repNotional) }} × {{ $fa($trim($gridSpacing)) }}٪.
                            کارمزد هر دو طرف حساب می‌شود: (fee_bps ÷ ۱۰۰۰۰) × (ارزش خرید + ارزش فروش)، که ارزش فروش = ارزش خرید × (۱ + فاصله ÷ ۱۰۰)
                            — یعنی همان فرمول موتور واقعی (کارمزد طرف فروش روی ارزش بزرگ‌تر). یک چرخه = یک خرید و یک فروش کامل روی یک پله.
                            هیچ تعمیمی به روز/هفته/ماه انجام نمی‌شود.
                        </p>
                    @else
                        <div class="at-empty">
                            <div class="at-empty__icon">—</div>
                            <div>هیچ سفارشی با ارزش مثبت وجود ندارد، بنابراین سود هر چرخه محاسبه نمی‌شود.</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- 4) Risk assessment (pure assessGridRisk) --}}
            @if ($risk)
                @php
                    $level = $risk['risk_level'] ?? 'medium';
                    $tone  = \App\Filament\Pages\GridCalculator::riskTone($level);
                    $score = $risk['overall_risk_score']['total_score'] ?? null;
                    $priceR = $risk['risk_breakdown']['price_risk'] ?? [];
                    $liqR   = $risk['risk_breakdown']['liquidity_risk'] ?? [];
                    $mktR   = $risk['risk_breakdown']['market_risk'] ?? [];
                    $sl     = $risk['stop_loss_recommendation'] ?? [];
                    $recs   = $risk['recommendations'] ?? [];
                @endphp
                <div class="panel-section">
                    <div class="panel-section__head">
                        <span class="panel-section__title">ارزیابی ریسک</span>
                        <span class="at-badge {{ $tone }}">
                            <span class="at-dot {{ $tone }}"></span>
                            سطح ریسک: {{ \App\Filament\Pages\GridCalculator::riskLevelLabel($level) }}
                            @if ($score !== null)
                                · امتیاز {{ $fa($trim($score)) }}
                            @endif
                        </span>
                    </div>
                    <div class="panel-section__body at-stack">
                        <div class="metric-grid">
                            <div class="metric-card is-row">
                                <span class="metric-label">ریسک کاهش قیمت</span>
                                <span class="metric-value neg" style="direction:ltr;">{{ $fa($trim($priceR['downward_risk_percent'] ?? 0)) }}٪</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">ریسک صعود</span>
                                <span class="metric-value" style="direction:ltr;">{{ $fa($trim($priceR['upward_exposure_percent'] ?? 0)) }}٪</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">سرمایه فعال</span>
                                <span class="metric-value" style="direction:ltr;">{{ $fa($trim($liqR['active_capital_ratio'] ?? 0)) }}٪</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">ذخیره نقدینگی</span>
                                <span class="metric-value pos" style="direction:ltr;">{{ $fa($trim($liqR['reserve_capital_ratio'] ?? 0)) }}٪</span>
                            </div>
                            <div class="metric-card is-row">
                                <span class="metric-label">امتیاز ریسک بازار</span>
                                <span class="metric-value" style="direction:ltr;">{{ $fa($trim($mktR['total_market_risk_score'] ?? 0)) }}</span>
                            </div>
                            @if (isset($sl['recommended_stop_loss_percent']))
                                <div class="metric-card is-row">
                                    <span class="metric-label">حد ضرر پیشنهادی</span>
                                    <span class="metric-value warn" style="direction:ltr;">{{ $fa($trim($sl['recommended_stop_loss_percent'])) }}٪</span>
                                </div>
                            @endif
                        </div>

                        @if (count($recs) > 0)
                            <div>
                                <span class="metric-label" style="margin-block-end: var(--at-gap-sm); display:block;">توصیه‌ها</span>
                                <div class="at-stack-sm">
                                    @foreach ($recs as $rec)
                                        <div class="at-row" style="align-items: baseline; gap: var(--at-gap-sm);">
                                            <span class="at-dot {{ $tone }}"></span>
                                            <span style="font-size: var(--at-fs-body); color: var(--at-text-dim);">{{ $rec }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- 5) FULL-ROUND TOTAL — the green summary box, LAST on the page.
                 Deterministic total: if EVERY priced level round-trips its cycle
                 exactly once, the sum of each level's engine net profit. Each
                 cycle uses the SAME formula the engine records
                 (CompletedTrade::createFromOrders) on that level's own notional:
                     gross = notional × (فاصله ÷ ۱۰۰)
                     fee   = (fee_bps ÷ ۱۰۰۰۰) × (ارزش خرید + ارزش فروش)
                           = (fee_bps ÷ ۱۰۰۰۰) × notional × (۲ + فاصله ÷ ۱۰۰)
                     net   = gross − fee
                 summed over ALL priced levels on BOTH sides (= N placed cycles,
                 not per_side). NOT a projection — no ×day/week/month. Every value
                 comes from GridCalculator::calculate(), which sums plan['items']. --}}
            @if ($roundNetTotal !== null)
                <div class="panel-section calc-round">
                    <div class="panel-section__head">
                        <span class="panel-section__title">سود کامل این دور</span>
                        <span class="at-badge pos">
                            <span class="at-dot pos"></span>
                            {{ $fa($roundCycles) }} چرخه کامل
                        </span>
                    </div>
                    <div class="panel-section__body at-stack">
                        <div class="calc-round__net">
                            <span class="calc-round__net-label">سود خالص کل:</span>
                            <span class="calc-round__net-value">{{ $fmt($roundNetTotal) }}</span>
                            <span class="calc-round__net-unit">ریال</span>
                        </div>

                        <div class="calc-round__subs">
                            <div class="calc-round__sub">
                                <span>سود ناخالص کل</span>
                                <strong class="pos">{{ $fmt($roundGrossTotal) }} ریال</strong>
                            </div>
                            <div class="calc-round__sub">
                                <span>کارمزد کل (۲ طرف هر چرخه)</span>
                                <strong class="neg">{{ $fmt($roundFeeTotal) }} ریال</strong>
                            </div>
                        </div>

                        <p class="calc-note calc-round__caption">
                            اگر هر پله یک‌بار چرخه‌اش را کامل کند — بدون هیچ تعمیمی به روز/هفته/ماه.
                            مجموع سود خالص همان پله‌های قیمت‌گذاری‌شده، با همان فرمول موتور واقعی (کارمزد هر دو طرف).
                        </p>
                    </div>
                </div>
            @endif
        @endif

    </div>
</x-filament-panels::page>
