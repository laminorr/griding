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
        .calc-select { appearance: none; -webkit-appearance: none; cursor: pointer; }
        .calc-select option { background: var(--at-surface); color: var(--at-text); }
        .calc-field__hint { font-size: 10.5px; color: var(--at-muted); }
        .calc-price-row { display: flex; gap: var(--at-gap-sm); align-items: stretch; }
        .calc-price-row .calc-input { flex: 1; min-inline-size: 0; }
        .calc-actions { display: flex; gap: var(--at-gap-sm); flex-wrap: wrap; align-items: center; }
        .calc-note {
            font-size: 11px;
            color: var(--at-muted);
            line-height: 1.6;
        }
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
                        <select class="calc-select" wire:model="mode">
                            <option value="both">دوطرفه (خرید + فروش)</option>
                            <option value="buy">فقط خرید</option>
                            <option value="sell">فقط فروش</option>
                        </select>
                    </div>

                    <div class="calc-field">
                        <label>فاصله بین سطوح (٪)</label>
                        <input type="number" class="calc-input" wire:model="gridSpacing" min="0.1" step="0.1">
                    </div>

                    <div class="calc-field">
                        <label>تعداد سطوح</label>
                        <select class="calc-select" wire:model="gridLevels">
                            @foreach ([4, 6, 8, 10, 12, 16, 20] as $lv)
                                <option value="{{ $lv }}">{{ $fa($lv) }} سطح</option>
                            @endforeach
                        </select>
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
                        <div class="metric-card is-row">
                            <span class="metric-label">سطوح هر سمت</span>
                            <span class="metric-value">{{ $fa($plan['per_side']) }}</span>
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
                            کارمزد = ۲ × ارزش سفارش × (fee_bps ÷ ۱۰۰۰۰). یک چرخه = یک خرید و یک فروش کامل روی یک پله.
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
                                <span class="metric-label">قرارگیری صعودی</span>
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
        @endif

    </div>
</x-filament-panels::page>
