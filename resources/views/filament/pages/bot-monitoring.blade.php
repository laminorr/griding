{{--
    Bot Monitoring — Phase P4-final: the merged «مانیتورینگ زنده» page.

    ROLE: the single status page. The former «هوش ربات» (Bot Intelligence) page
    was merged in here, so this page now carries BOTH:
      1. the LIVE fleet view (Alpine, self-refreshing) — open orders, in-flight
         cycles and the latest event stream across the active bots, and
      2. the DEEP single-bot analytics (Livewire, bot-picker-driven) folded in
         from Bot Intelligence — grid map, capital concentration, grid drift,
         stability and completed pairs for one selected bot.
    The duplicate sections the two pages shared (open-orders + event stream) are
    kept ONCE, in the live view. Every data method is untouched — the analytics
    methods moved over verbatim from BotIntelDashboard.

    Numbers are shown with Persian digits (۰-۹): server-rendered values via the
    @fa directive, client-rendered (Alpine) values via the faDigits() helper
    defined below. Display-only — no metric or value is recomputed.
--}}
<x-filament-panels::page>

    {{-- «نمودار قیمت» styles — scoped to .at-pchart only (no sidebar/layout
         rules; the panel design system lives in the admin-terminal partial). --}}
    <style>
        .at-pchart { min-width: 0; }
        .at-pchart .panel-section__head { flex-wrap: wrap; gap: var(--at-gap-sm, 8px); }
        .at-pchart__tools { display: flex; flex-wrap: wrap; align-items: center; gap: var(--at-gap-sm, 8px); }
        .at-pchart__tf { display: flex; flex-wrap: wrap; gap: 4px; }
        .at-pchart__tf .at-btn { min-width: 0; }
        .at-pchart__body { min-width: 0; }
        .at-pchart__canvas {
            position: relative;
            inline-size: 100%;
            max-inline-size: 100%;
            min-inline-size: 0;
            overflow: hidden;
            border-radius: 14px;
            background: #0B1220;
            border: 1px solid var(--at-border, #233349);
        }
        .at-pchart__empty { min-block-size: 180px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        /* Crosshair legend for fill markers: one quiet line over the chart's
           top-left corner, only while the crosshair is on a candle with fills. */
        .at-pchart__legend {
            position: absolute; top: 6px; left: 8px; z-index: 3; /* physical: the canvas is LTR, legend text RTL */
            max-inline-size: calc(100% - 90px); pointer-events: none;
            font-family: Vazirmatn, system-ui, sans-serif; font-size: 11px; line-height: 1.6;
            color: #c9d4e3; background: rgba(11, 18, 32, 0.78); border-radius: 6px; padding: 2px 6px;
        }
        .at-pchart__legend .buy { color: #34D399; }
        .at-pchart__legend .sell { color: #ff5d68; }
        .at-pchart__note { font-size: 11px; margin-block-start: 6px; }
    </style>

    {{-- =================================================================
         TOP BOT SELECTOR (v3 mockup .bot-selector-wrap). One prominent «انتخاب
         ربات» dropdown at the top of the page — the single source of truth for
         which bot the whole page shows. Changing it sets $selectedBotId on the
         Livewire component (wire:model.live → updatedSelectedBotId), which
         (a) re-renders the deep analytics block below for that bot and (b) is
         picked up by the live view's Alpine ($wire.$watch) so it focuses the
         SAME bot. The «فعال» status affordance mirrors the selected bot's real
         active state. Options are the real active-bots list.
         ================================================================= --}}
    @php
        $pickerAll    = $this->getAvailableBots();
        $pickerActive = $pickerAll->where('is_active', true)->values();
        $pickerBots   = $pickerActive->isNotEmpty() ? $pickerActive : $pickerAll;
    @endphp
    @if($pickerBots->isNotEmpty())
        <div class="at-botselect-wrap">
            <label class="at-botselect-label" for="botSelector">انتخاب ربات</label>
            <div class="at-botselect">
                <select id="botSelector" wire:model.live="selectedBotId" aria-label="انتخاب ربات">
                    @foreach($pickerBots as $b)
                        <option value="{{ $b['id'] }}">{{ $b['name'] }} — {{ $b['symbol'] }}</option>
                    @endforeach
                </select>
                @if($selectedBot)
                    <span class="at-botselect-status {{ $selectedBot->is_active ? '' : 'is-off' }}">
                        {{ $selectedBot->is_active ? 'فعال' : 'متوقف' }}
                    </span>
                @endif
            </div>
        </div>
    @endif

    {{-- =================================================================
         LIVE FLEET VIEW (Alpine, self-refreshing). wire:ignore keeps its
         Alpine state + DOM intact across the analytics picker's Livewire
         round-trips below. The @js(...) seed + $wire.$watch below keep the
         Alpine `selectedBotId` in lock-step with the top selector so the live
         view focuses the same bot the analytics block shows.
         ================================================================= --}}
    <div wire:ignore x-data="botMonitoring(@js($selectedBotId))" x-init="init()" class="at-stack" style="margin-block-end: var(--at-gap-lg);">

        {{-- Live header --}}
        <div class="at-page-head">
            <div class="at-row">
                <span class="at-dot pos"></span>
                <div>
                    <p class="at-page-head__title">مانیتورینگ زنده ربات‌ها</p>
                    <p class="at-page-head__sub">ردیابی لحظه‌ای · به‌روزرسانی خودکار هر ۳۰ ثانیه</p>
                </div>
            </div>
            <div class="at-page-head__aside">
                <span class="at-badge muted at-mono" style="direction: ltr;" x-text="clock"></span>
            </div>
        </div>

        {{-- Loading --}}
        <div x-show="loading" class="at-empty">در حال بارگذاری…</div>

        {{-- No active bots --}}
        <template x-if="!loading && bots.length === 0">
            <div class="panel-section">
                <div class="at-empty">
                    <div class="at-empty__icon">🤖</div>
                    هیچ ربات فعالی برای نمایش وجود ندارد
                </div>
            </div>
        </template>

        {{-- Price chart when the selected bot is not in the active fleet:
             a stopped bot (history only, no live order lines) or no bot at all
             (market price only). Keyed on the selection so it is re-created —
             and refetched — when the selector changes. --}}
        <template x-if="!loading && !selectedInFleet">
            <div>
                <template x-for="key in [String(selectedBotId ?? 'market')]" :key="key">
                    <div class="at-stack" style="margin-block-end: var(--at-gap-lg);">
                        @include('filament.pages.partials.bot-monitoring-price-chart')
                    </div>
                </template>
            </div>
        </template>

        {{-- Bots --}}
        <div x-show="!loading">
            <template x-for="bot in visibleBots" :key="bot.id">
                <div class="at-stack" style="margin-block-end: var(--at-gap-lg);">

                    {{-- Bot header + snapshot metrics --}}
                    <div class="panel-section">
                        <div class="panel-section__head">
                            <div class="at-row">
                                <span class="at-dot pos"></span>
                                <div>
                                    <p class="panel-section__title" x-text="bot.name"></p>
                                    <p class="panel-section__sub">
                                        <span class="at-mono" x-text="bot.symbol"></span>
                                        <span> · </span><span x-text="faDigits(bot.grid_levels) + ' سطح'"></span>
                                        <span> · </span><span x-text="'فاصله ' + faDigits((bot.grid_spacing * 100).toFixed(1)) + '%'"></span>
                                        <span> · </span><span x-text="'گرید کلاسیک: ' + (bot.debug.rearm_exits ? 'روشن' : 'خاموش')"></span>
                                    </p>
                                </div>
                            </div>
                            <div class="at-row" style="flex-wrap: wrap; justify-content: flex-end;">
                                <span class="at-badge pos"><span class="at-dot pos"></span>فعال</span>
                                <span class="at-badge muted" x-text="'بررسی: ' + faDigits(bot.last_check_at || 'هرگز')"></span>
                            </div>
                        </div>

                        <div class="panel-section__body">
                            <div class="metric-grid kpis">
                                {{-- سرمایه کل --}}
                                <div class="metric-card kpi wallet">
                                    <div class="metric-head">
                                        <span class="metric-label">سرمایه کل</span>
                                        <span class="metric-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18a2 2 0 0 1 2 2v2H8a2 2 0 0 0 0 4h12v4a2 2 0 0 1-2 2H6.5A2.5 2.5 0 0 1 4 16.5v-9Z"/>
                                                <path d="M18 9h3v4h-3a2 2 0 1 1 0-4Z"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <span class="metric-value" style="direction: ltr; text-align: start;" x-text="formatNum(bot.capital)"></span>
                                    <div class="metric-foot">
                                        <span class="metric-sub">ریال</span>
                                        <span class="metric-chip">کل بودجه ربات</span>
                                    </div>
                                </div>
                                {{-- سفارشات فعال --}}
                                <div class="metric-card kpi orders">
                                    <div class="metric-head">
                                        <span class="metric-label">سفارشات فعال</span>
                                        <span class="metric-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/>
                                                <circle cx="4" cy="6" r="1.4"/><circle cx="4" cy="12" r="1.4"/><circle cx="4" cy="18" r="1.4"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <span class="metric-value" x-text="faDigits(bot.active_orders.length)"></span>
                                    {{-- Active-vs-filled context: a filled level + its just-placed
                                         opposite is why "active" can read one below grid_levels.
                                         Uses currently_filled (levels held right now), NOT
                                         total_filled (cumulative all-time fills across both legs). --}}
                                    <div class="metric-foot">
                                        <span class="metric-sub" x-text="'از ' + faDigits(bot.grid_levels) + ' سطح · ' + faDigits(bot.debug.currently_filled || 0) + ' پرشده'"></span>
                                        <span class="metric-chip">وضعیت فعلی</span>
                                    </div>
                                </div>
                                {{-- معاملات ۲۴ ساعت --}}
                                <div class="metric-card kpi trades">
                                    <div class="metric-head">
                                        <span class="metric-label">معاملات ۲۴ ساعت</span>
                                        <span class="metric-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M5 17V7"/><path d="M12 17V4"/><path d="M19 17v-8"/><path d="M3 20h18"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <span class="metric-value" x-text="faDigits(bot.completed_trades_24h)"></span>
                                    <div class="metric-foot">
                                        <span class="metric-sub">تکمیل‌شده</span>
                                        <span class="metric-chip">عملکرد روزانه</span>
                                    </div>
                                </div>
                                {{-- سود ۲۴ ساعت --}}
                                <div class="metric-card kpi profit">
                                    <div class="metric-head">
                                        <span class="metric-label">سود ۲۴ ساعت</span>
                                        <span class="metric-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 16l5-5 4 4 7-8"/><path d="M14 7h6v6"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <span class="metric-value" :class="bot.profit_24h >= 0 ? 'pos' : 'neg'"
                                          style="direction: ltr; text-align: start;"
                                          x-text="(bot.profit_24h >= 0 ? '+' : '') + formatNum(bot.profit_24h)"></span>
                                    <div class="metric-foot">
                                        <span class="metric-sub" :class="bot.profit_change_24h >= 0 ? 'pos' : 'neg'"
                                              x-text="bot.profit_change_24h === null ? '—' : (bot.profit_change_24h >= 0 ? '▲ ' : '▼ ') + faDigits(Math.abs(bot.profit_change_24h)) + '%'"></span>
                                        <span class="metric-chip">سود روز جاری</span>
                                    </div>
                                </div>
                                {{-- چرخه‌های کامل --}}
                                <div class="metric-card kpi cycles">
                                    <div class="metric-head">
                                        <span class="metric-label">چرخه‌های کامل</span>
                                        <span class="metric-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 12a8.5 8.5 0 0 1-14.5 6l-2.5-2.5"/><path d="M3 12A8.5 8.5 0 0 1 17.5 6L20 8.5"/>
                                                <path d="M20 4v4.5h-4.5"/><path d="M4 20v-4.5h4.5"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <span class="metric-value" x-text="faDigits(bot.total_cycles || 0)"></span>
                                    <div class="metric-foot">
                                        <span class="metric-sub">از خرید تا فروش</span>
                                        <span class="metric-chip">معاملات تکمیل‌شده</span>
                                    </div>
                                </div>
                                {{-- زمان از آخرین معامله --}}
                                <div class="metric-card kpi time">
                                    <div class="metric-head">
                                        <span class="metric-label">زمان از آخرین معامله</span>
                                        <span class="metric-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3.5 2"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <span class="metric-value" style="font-size: var(--at-fs-title);"
                                          x-text="bot.last_trade_at ? formatTimeAgo(bot.last_trade_at) : 'بدون معامله'"></span>
                                    <div class="metric-foot">
                                        <span class="metric-sub" x-text="faDigits(bot.filled_24h || 0) + ' پُرشده ۲۴ ساعت'"></span>
                                        <span class="metric-chip">آخرین فعالیت</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Price chart («نمودار قیمت») — selected bot only. Candles from
                         CandleService (RIAL for IRT markets) + the bot's real open
                         grid orders as price lines, polled via $wire.getChartData().
                         Re-created when the selection changes (x-for key + x-if). --}}
                    <template x-if="String(bot.id) === String(selectedBotId)">
                        @include('filament.pages.partials.bot-monitoring-price-chart')
                    </template>

                    {{-- Open orders  |  cycle summary + next targets --}}
                    <div class="at-cols-2">

                        {{-- Open orders (live) --}}
                        <div class="panel-section">
                            <div class="panel-section__head">
                                <span class="panel-section__title">سفارش‌های باز</span>
                                <span class="at-badge muted" x-text="faDigits(bot.active_orders.length) + ' سفارش'"></span>
                            </div>
                            <div class="at-scroll" style="max-height: 320px;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>نوع</th>
                                            <th class="at-num">قیمت (ریال)</th>
                                            <th class="at-num">مقدار</th>
                                            <th>وضعیت</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="order in getSortedOrders(bot.active_orders)" :key="order.id">
                                            <tr>
                                                <td>
                                                    <span class="at-badge" :class="order.type === 'buy' ? 'pos' : 'neg'"
                                                          x-text="order.type === 'buy' ? 'خرید' : 'فروش'"></span>
                                                </td>
                                                <td class="at-num" x-text="formatNum(order.price)"></td>
                                                <td class="at-num at-t-dim" x-text="faDigits(order.amount)"></td>
                                                <td>
                                                    <span class="at-badge" :class="order.paired_order_id ? '' : 'muted'"
                                                          x-text="order.paired_order_id ? '🔗 جفت‌شده' : '⏳ در انتظار'"></span>
                                                </td>
                                            </tr>
                                        </template>
                                        <template x-if="bot.active_orders.length === 0">
                                            <tr><td colspan="4" class="at-empty">سفارش بازی وجود ندارد</td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Cycle status: donut (active از سطوح) + dense KV list --}}
                        <div class="panel-section">
                            <div class="panel-section__head">
                                <span class="panel-section__title">وضعیت چرخه</span>
                                <span class="at-badge pos"><span class="at-dot pos"></span>فعال</span>
                            </div>
                            <div class="panel-section__body">
                                <div class="at-row" style="align-items: center; gap: var(--at-gap-md);">
                                    {{-- Donut: active orders out of grid levels --}}
                                    <div class="at-donut"
                                         :style="`--pct:${Math.min(100, Math.round((bot.active_orders.length / (bot.grid_levels || 1)) * 100))}`">
                                        <div class="at-donut__hole">
                                            <span class="at-donut__num" x-text="faDigits(bot.active_orders.length)"></span>
                                            <span class="at-donut__den" x-text="'از ' + faDigits(bot.grid_levels)"></span>
                                        </div>
                                    </div>
                                    {{-- Dense single-line KV list --}}
                                    <div class="at-stack-sm" style="flex: 1; min-inline-size: 0;">
                                        <div class="metric-card is-row">
                                            <span class="metric-label">چرخه‌های کامل</span>
                                            <span class="metric-value" x-text="faDigits(bot.total_cycles || 0)"></span>
                                        </div>
                                        <div class="metric-card is-row">
                                            <span class="metric-label">میانگین مدت چرخه</span>
                                            <span class="metric-value" style="direction: ltr;" x-text="bot.avg_cycle_duration === null ? '—' : formatDuration(bot.avg_cycle_duration)"></span>
                                        </div>
                                        <div class="metric-card is-row">
                                            <span class="metric-label">سفارشات فعال</span>
                                            <span class="metric-value" x-text="faDigits(bot.active_orders.length)"></span>
                                        </div>
                                        <div class="metric-card is-row">
                                            <span class="metric-label">نرخ موفقیت</span>
                                            <span class="metric-value pos" x-text="bot.success_rate === null ? '—' : faDigits(bot.success_rate) + '%'"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="at-stack-sm" style="margin-block-start: var(--at-gap-sm);">
                                    <div class="metric-card is-row">
                                        <span class="metric-label">معاملات ۲۴ ساعت</span>
                                        <span class="metric-value" x-text="faDigits(bot.completed_trades_24h)"></span>
                                    </div>
                                    <div class="metric-card is-row">
                                        <span class="metric-label">سود ۲۴ ساعت</span>
                                        <span class="metric-value" :class="bot.profit_24h >= 0 ? 'pos' : 'neg'" style="direction: ltr;"
                                              x-text="(bot.profit_24h >= 0 ? '+' : '') + formatNum(bot.profit_24h) + ' ریال'"></span>
                                    </div>
                                    <div class="metric-card is-row">
                                        <span class="metric-label">سود کل</span>
                                        <span class="metric-value pos" style="direction: ltr;" x-text="formatNum(bot.debug.profit_total) + ' ریال'"></span>
                                    </div>
                                </div>

                                <div style="margin-block-start: var(--at-gap-sm);">
                                    <span class="metric-label">اهداف بعدی</span>
                                    <div style="margin-block-start: var(--at-gap-xs);">
                                        <template x-for="order in bot.active_orders.filter(o => !o.paired_order_id).slice(0, 4)" :key="'wait-' + order.id">
                                            <div class="at-kv">
                                                <span class="at-kv__k" :class="order.type === 'buy' ? 'at-t-pos' : 'at-t-neg'"
                                                      x-text="order.type === 'buy' ? 'خرید در' : 'فروش در'"></span>
                                                <span class="at-kv__v at-num" x-text="formatNum(order.price) + ' ریال'"></span>
                                            </div>
                                        </template>
                                        <template x-if="bot.active_orders.filter(o => !o.paired_order_id).length === 0">
                                            <div class="at-empty" style="padding: var(--at-gap-sm);">هدف بازی در انتظار نیست</div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Activity: cycle summary metrics + event stream --}}
                    <div class="panel-section" x-data="activityLog()"
                         x-show="bot.activity_summary && bot.activity_summary.last_cycle_status">
                        <div class="panel-section__head">
                            <span class="panel-section__title">گزارش فعالیت‌ها</span>
                            <span class="at-badge pos"><span class="at-dot pos"></span>زنده</span>
                        </div>

                        {{-- Summary metrics --}}
                        <div class="panel-section__body" style="border-block-end: 1px solid var(--at-border);">
                            <div class="at-stack-sm">
                                <div class="metric-card is-row">
                                    <span class="metric-label">وضعیت آخرین اجرای بررسی</span>
                                    <span class="metric-value"
                                          :class="{ 'pos': ['success','in_progress'].includes(bot.activity_summary.last_cycle_status), 'neg': bot.activity_summary.last_cycle_status === 'error', 'at-t-warn': bot.activity_summary.last_cycle_status === 'warning' }"
                                          x-text="cycleLabel(bot.activity_summary.last_cycle_status)"></span>
                                    <span class="metric-sub" x-show="bot.activity_summary.last_cycle_time" x-text="formatTimeAgo(bot.activity_summary.last_cycle_time)"></span>
                                </div>
                                <div class="metric-card is-row">
                                    <span class="metric-label">میانگین زمان اجرای بررسی</span>
                                    <span class="metric-value" style="direction: ltr;" x-text="formatCycleDuration(bot.activity_summary.avg_cycle_duration)"></span>
                                    <span class="metric-sub">۲۴ ساعت گذشته</span>
                                </div>
                                <div class="metric-card is-row">
                                    <span class="metric-label">میانگین پاسخ API</span>
                                    <span class="metric-value" :class="bot.activity_summary.avg_api_latency > 1000 ? 'at-t-warn' : 'pos'"
                                          style="direction: ltr;"
                                          x-text="faDigits(bot.activity_summary.avg_api_latency.toFixed(0)) + 'ms'"></span>
                                    <span class="metric-sub">نوبیتکس</span>
                                </div>
                                <div class="metric-card is-row">
                                    <span class="metric-label">اجرای بررسی ۲۴ ساعت</span>
                                    <span class="metric-value" x-text="faDigits(bot.activity_summary.cycles_count_24h)"></span>
                                    <span class="metric-sub">اجرای CheckTrades</span>
                                </div>
                            </div>
                        </div>

                        {{-- Filter chips --}}
                        <div class="at-row" style="flex-wrap: wrap; padding: var(--at-gap-sm) var(--at-gap-md); border-block-end: 1px solid var(--at-border);"
                             x-show="bot.activity_cycles && bot.activity_cycles.length > 0">
                            <button type="button" class="at-btn" :class="activeFilter === 'all' ? 'at-btn--accent' : ''" @click="activeFilter = 'all'">
                                همه <span class="at-t-muted" x-text="'(' + faDigits(bot.activity_cycles.length) + ')'"></span>
                            </button>
                            <button type="button" class="at-btn" :class="activeFilter === 'errors' ? 'at-btn--accent' : ''" @click="activeFilter = 'errors'">
                                خطاها <span class="at-t-muted" x-text="'(' + faDigits(getErrorCyclesCount(bot.activity_cycles)) + ')'"></span>
                            </button>
                            <button type="button" class="at-btn" :class="activeFilter === 'api' ? 'at-btn--accent' : ''" @click="activeFilter = 'api'">
                                API <span class="at-t-muted" x-text="'(' + faDigits(getApiCallsCount(bot.activity_cycles)) + ')'"></span>
                            </button>
                            <button type="button" class="at-btn" :class="activeFilter === 'cycles' ? 'at-btn--accent' : ''" @click="activeFilter = 'cycles'">
                                اجراهای بررسی <span class="at-t-muted" x-text="'(' + faDigits(bot.activity_cycles.filter(c => c.status !== 'ungrouped').length) + ')'"></span>
                            </button>
                        </div>

                        {{-- Empty --}}
                        <div x-show="!bot.activity_cycles || bot.activity_cycles.length === 0" class="at-empty">
                            <div class="at-empty__icon">📝</div>
                            هنوز فعالیتی ثبت نشده است
                        </div>

                        {{-- Cycle stream --}}
                        <div class="at-scroll" style="max-height: 460px;" x-show="bot.activity_cycles && bot.activity_cycles.length > 0">
                            <template x-for="cycle in getFilteredCycles(bot.activity_cycles)" :key="cycle.id">
                                <div style="border-block-end: 1px solid var(--at-border);" x-data="{ expanded: false }">
                                    {{-- Cycle header row --}}
                                    <div @click="expanded = !expanded"
                                         style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: var(--at-gap-sm); padding: var(--at-gap-sm) var(--at-gap-md);">
                                        <div class="at-row">
                                            <span class="at-dot" :class="cycleDot(cycle.status)"></span>
                                            <div>
                                                <span style="font-size: var(--at-fs-body); color: var(--at-text);" x-text="cycleTitle(cycle.status)"></span>
                                                <div class="at-row at-t-muted" style="gap: var(--at-gap-xs); font-size: var(--at-fs-label); margin-block-start: 2px; flex-wrap: wrap;">
                                                    <span x-text="formatTimeAgo(cycle.started_at_iso)"></span>
                                                    <span x-show="cycle.duration_ms" class="at-mono" x-text="'· ' + formatCycleDuration(cycle.duration_ms)"></span>
                                                    <span x-show="cycle.summary.api_calls > 0" x-text="'· ' + faDigits(cycle.summary.api_calls) + ' API'"></span>
                                                    <span x-show="cycle.summary.orders_active > 0" x-text="'· ' + faDigits(cycle.summary.orders_active) + ' سفارش'"></span>
                                                    <span x-show="cycle.summary.errors > 0" class="at-t-neg" x-text="'· ' + faDigits(cycle.summary.errors) + ' خطا'"></span>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="at-badge" :class="cycleDot(cycle.status)" x-text="cycleLabel(cycle.status)"></span>
                                    </div>
                                    {{-- Expanded event list --}}
                                    <div x-show="expanded" x-collapse style="border-block-start: 1px solid var(--at-border); padding: var(--at-gap-xs) var(--at-gap-md) var(--at-gap-sm);">
                                        <template x-for="event in cycle.events" :key="event.id">
                                            <div class="at-kv" style="align-items: baseline;">
                                                <span class="at-badge muted" style="min-inline-size: 64px; justify-content: center;" x-text="eventTag(event.type)"></span>
                                                <span style="flex: 1; text-align: start; margin-inline: var(--at-gap-sm); font-size: var(--at-fs-body); color: var(--at-text-dim);" x-text="event.message"></span>
                                                <span x-show="event.type === 'API_CALL' && event.execution_time" class="at-t-warn at-mono" style="font-size: var(--at-fs-label);" x-text="faDigits(event.execution_time || 0) + 'ms'"></span>
                                                <span class="at-mono at-t-muted" style="font-size: var(--at-fs-label);"
                                                      x-text="new Date(event.time_iso).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Debug (collapsed, compact) --}}
                    <div class="panel-section" x-data="{ debugOpen: false }">
                        <div class="panel-section__head" @click="debugOpen = !debugOpen" style="cursor: pointer;">
                            <span class="panel-section__title">🔍 اطلاعات Debug</span>
                            <span class="at-badge muted" x-text="debugOpen ? 'بستن' : 'نمایش'"></span>
                        </div>
                        <div x-show="debugOpen" x-collapse class="panel-section__body">
                            <div class="metric-grid">
                                <div class="metric-card is-row"><span class="metric-label">کل Orders</span><span class="metric-value" x-text="faDigits(bot.debug.total_orders)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">active/placed</span><span class="metric-value" x-text="faDigits(bot.debug.total_with_status_active)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">fill نشده</span><span class="metric-value" x-text="faDigits(bot.debug.total_not_executed)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">pair نشده</span><span class="metric-value" x-text="faDigits(bot.debug.total_not_paired)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">fill شده (کل)</span><span class="metric-value" x-text="faDigits(bot.debug.total_filled)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">پرشده فعلی</span><span class="metric-value" x-text="faDigits(bot.debug.currently_filled)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">Completed Trades</span><span class="metric-value pos" x-text="faDigits(bot.debug.completed_trades_total)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">Trades ۲۴ ساعت</span><span class="metric-value pos" x-text="faDigits(bot.debug.completed_trades_24h_actual)"></span></div>
                                <div class="metric-card is-row"><span class="metric-label">سود کل</span><span class="metric-value pos" style="direction: ltr;" x-text="formatNum(bot.debug.profit_total)"></span><span class="metric-sub">ریال</span></div>
                            </div>
                        </div>
                    </div>

                </div>
            </template>
        </div>
    </div>

    {{-- =================================================================
         DEEP SINGLE-BOT ANALYTICS (folded in from «هوش ربات»). Livewire /
         bot-picker driven: grid map, capital concentration, grid drift,
         stability and completed pairs for the selected bot. Open-orders and
         the event stream are intentionally NOT repeated here — the live view
         above owns them.
         ================================================================= --}}
    @php
        $gridMap              = $this->getGridMapData();
        $completedPairs       = $this->getCompletedPairs();
        $capitalConcentration = $this->getCapitalConcentration();
        $gridDrift            = $this->getGridDrift();
        $systemHealth         = $this->getSystemHealth();

        // Filament semantic colour → terminal tone (accent / red / amber / muted).
        $tone = fn (?string $c): string => [
            'success' => 'pos', 'primary' => 'pos', 'info' => 'pos',
            'danger'  => 'neg', 'warning' => 'warn',
        ][$c] ?? 'muted';
    @endphp

    <div class="at-stack">
        <div class="at-page-head">
            <div>
                <p class="at-page-head__title">تحلیل سیکل‌ها</p>
                <p class="at-page-head__sub">تمرکز سرمایه، امتیاز موقعیت، پایداری و نقشه گرید ربات انتخاب‌شده</p>
            </div>
            {{-- Read-only mirror of the top-of-page selector (the single bot
                 selector now lives in the page header). Shows which bot this
                 analytics block is for; switch bots from the top pills. --}}
            <div class="at-page-head__aside">
                @if($selectedBot)
                    <span class="at-badge muted at-mono">{{ $selectedBot->symbol }}</span>
                    <span class="at-badge {{ $selectedBot->is_active ? 'pos' : 'muted' }}">
                        <span class="at-dot {{ $selectedBot->is_active ? 'pos' : 'muted' }}"></span>
                        {{ $selectedBot->name }} · {{ $selectedBot->is_active ? 'فعال' : 'متوقف' }}
                    </span>
                @else
                    <span class="at-t-muted">هیچ رباتی پیکربندی نشده</span>
                @endif
            </div>
        </div>

        @if($selectedBot)
            {{-- Grid Map --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <div>
                        <span class="panel-section__title">نقشه گرید</span>
                        <p class="panel-section__sub">توزیع سفارش‌های فعال در سطوح گرید</p>
                    </div>
                    @if($gridMap['has_data'] ?? false)
                        <span class="at-badge muted">@fa($gridMap['total_levels']) سطح</span>
                    @endif
                </div>

                @if($gridMap['has_data'] ?? false)
                    <div class="panel-section__body" style="padding-block: var(--at-gap-sm);">
                        <div class="at-row" style="justify-content: space-between; gap: var(--at-gap-md);">
                            <span class="at-kv__k">بالا: <span class="at-num at-t-dim">@fa($gridMap['top_price']) IRT</span></span>
                            <span class="at-kv__k">پایین: <span class="at-num at-t-dim">@fa($gridMap['bottom_price']) IRT</span></span>
                        </div>
                    </div>
                    <div class="at-scroll" style="max-height: 320px;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>سطح</th>
                                    <th class="at-num">قیمت (IRT)</th>
                                    <th class="at-num">مقدار</th>
                                    <th>سمت</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($gridMap['levels'] as $level)
                                    <tr>
                                        <td class="at-t-muted">L{{ \Illuminate\Support\Str::faDigits($level['index']) }}</td>
                                        <td class="at-num at-mono">@fa($level['price'])</td>
                                        <td class="at-num at-t-dim">@fa($level['amount'])</td>
                                        <td>
                                            <span class="at-badge {{ $level['side'] === 'buy' ? 'pos' : 'neg' }}">
                                                {{ $level['side'] === 'buy' ? 'خرید' : 'فروش' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="at-empty">
                        <div class="at-empty__icon">▦</div>
                        {{ $gridMap['message'] ?? 'داده‌ای موجود نیست' }}
                    </div>
                @endif
            </div>

            {{-- Completed Pairs --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <div>
                        <span class="panel-section__title">معاملات تکمیل‌شده</span>
                        <p class="panel-section__sub">چرخه‌های تکمیل‌شده اخیر</p>
                    </div>
                </div>
                @if($completedPairs->isNotEmpty())
                    <table class="data-table">
                        <thead>
                            <tr><th>#</th><th class="at-num">خرید → فروش</th><th class="at-num">سود</th><th class="at-num">مدت</th></tr>
                        </thead>
                        <tbody>
                            @foreach($completedPairs as $pair)
                                <tr>
                                    <td class="at-mono at-t-muted">{{ $pair['id'] }}</td>
                                    <td class="at-num at-mono at-t-dim">@fa($pair['buy_price']) → @fa($pair['sell_price'])</td>
                                    <td class="at-num {{ $pair['is_profitable'] ? 'pos' : 'neg' }}">@fa($pair['profit_formatted'])</td>
                                    <td class="at-num at-t-muted">@fa($pair['duration'])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="at-empty">هنوز معامله‌ای تکمیل نشده</div>
                @endif
            </div>

            {{-- Risk & Drift: concentration | drift | stability --}}
            <div class="at-cols-3">
                {{-- Capital Concentration --}}
                <div class="panel-section">
                    <div class="panel-section__head">
                        <div>
                            <span class="panel-section__title">تمرکز سرمایه</span>
                            <p class="panel-section__sub">توزیع بین انواع سفارش</p>
                        </div>
                    </div>
                    <div class="panel-section__body at-stack-sm">
                        {{-- Buy --}}
                        <div>
                            <div class="at-row" style="justify-content: space-between;">
                                <span class="at-kv__k">سفارش‌های خرید</span>
                                <span class="at-t-pos" style="font-size: var(--at-fs-label);">@fa($capitalConcentration['buy']['percent'])%</span>
                            </div>
                            <div class="at-bar" style="margin-block: 4px;"><div class="at-bar__fill" style="inline-size: {{ min(100, $capitalConcentration['buy']['percent']) }}%;"></div></div>
                            <p class="at-kv__k">@fa($capitalConcentration['buy']['count']) سفارش · @fa($capitalConcentration['buy']['capital']) IRT</p>
                        </div>
                        {{-- Sell --}}
                        <div>
                            <div class="at-row" style="justify-content: space-between;">
                                <span class="at-kv__k">سفارش‌های فروش</span>
                                <span class="at-t-neg" style="font-size: var(--at-fs-label);">@fa($capitalConcentration['sell']['percent'])%</span>
                            </div>
                            <div class="at-bar" style="margin-block: 4px;"><div class="at-bar__fill neg" style="inline-size: {{ min(100, $capitalConcentration['sell']['percent']) }}%;"></div></div>
                            <p class="at-kv__k">@fa($capitalConcentration['sell']['count']) سفارش · @fa($capitalConcentration['sell']['capital']) IRT</p>
                        </div>
                        {{-- Free --}}
                        <div>
                            <div class="at-row" style="justify-content: space-between;">
                                <span class="at-kv__k">سرمایه آزاد</span>
                                <span class="at-t-muted" style="font-size: var(--at-fs-label);">@fa($capitalConcentration['free']['percent'])%</span>
                            </div>
                            <div class="at-bar" style="margin-block: 4px;"><div class="at-bar__fill muted" style="inline-size: {{ min(100, $capitalConcentration['free']['percent']) }}%;"></div></div>
                            <p class="at-kv__k">@fa($capitalConcentration['free']['capital']) IRT قابل استفاده</p>
                        </div>
                    </div>
                </div>

                {{-- Grid position score — big % + quality bar (mockup «امتیاز کل») --}}
                <div class="panel-section">
                    <div class="panel-section__head">
                        <div>
                            <span class="panel-section__title">امتیاز موقعیت</span>
                            <p class="panel-section__sub">شاخص ناحیه معاملاتی گرید</p>
                        </div>
                    </div>
                    <div class="panel-section__body">
                        <div style="text-align: center;">
                            <span class="metric-value {{ $tone($gridDrift['color'] ?? null) === 'pos' ? 'pos' : '' }} {{ $tone($gridDrift['color'] ?? null) === 'warn' ? 'at-t-warn' : '' }}"
                                  style="font-size: 34px; line-height: 1;">@fa(round($gridDrift['position']))%</span>
                            <p style="font-size: var(--at-fs-body); color: var(--at-text); margin-block-start: var(--at-gap-xs);">{{ $gridDrift['status'] }}</p>
                            <p class="at-kv__k">{{ $gridDrift['description'] }}</p>
                        </div>
                        {{-- Quality bar: filled to the position %, with pole/high edges labelled --}}
                        <div style="margin-block-start: var(--at-gap-md);">
                            <div class="at-bar" style="block-size: 8px;">
                                <div class="at-bar__fill {{ $tone($gridDrift['color'] ?? null) === 'warn' ? 'warn' : '' }}"
                                     style="inline-size: {{ min(100, max(0, $gridDrift['position'])) }}%;"></div>
                            </div>
                            <div class="at-row at-kv__k" style="justify-content: space-between; margin-block-start: 4px;">
                                <span>پایین</span><span>بالا</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Stability --}}
                <div class="panel-section">
                    <div class="panel-section__head">
                        <div>
                            <span class="panel-section__title">پایداری</span>
                            <p class="panel-section__sub">پایش خطا (۲۴ ساعت)</p>
                        </div>
                    </div>
                    <div class="panel-section__body">
                        <div style="text-align: center;">
                            <span class="at-dot {{ $tone($systemHealth['stability']['color'] ?? null) }}" style="width: 12px; height: 12px; margin-block-end: var(--at-gap-sm);"></span>
                            <p style="font-size: var(--at-fs-body); color: var(--at-text);">{{ $systemHealth['stability']['value'] }}</p>
                            <p class="at-kv__k">@fa($systemHealth['stability']['errors_24h']) خطا در ۲۴ ساعت گذشته</p>
                        </div>
                        @if($systemHealth['stability']['errors_24h'] > 0)
                            <p class="at-kv__k at-t-warn" style="margin-block-start: var(--at-gap-sm); text-align: center;">برای جزئیات، خط زمانی فعالیت را در بخش زنده بالا بررسی کنید</p>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="panel-section">
                <div class="at-empty" style="padding: var(--at-gap-lg);">
                    <div class="at-empty__icon">🤖</div>
                    <p style="color: var(--at-text); font-size: var(--at-fs-body);">رباتی برای تحلیل انتخاب نشده است</p>
                    <p class="at-kv__k">برای مشاهده تحلیل، یک ربات پیکربندی کنید</p>
                </div>
            </div>
        @endif
    </div>

    @push('scripts')
    <script>
        // Persian-digit display helper for all client-rendered (Alpine) numbers.
        // Display-only: rewrites the 0-9 glyphs to ۰-۹ and leaves everything else
        // (separators, signs, units, Latin text) untouched.
        window.faDigits = function (value) {
            if (value === null || value === undefined) return '';
            const map = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            return String(value).replace(/[0-9]/g, d => map[d]);
        };

        function botMonitoring(initialBotId = null) {
            // Server-rendered snapshot, used ONLY to seed the first paint.
            // Every refresh after that asks the server again via $wire.
            const seed = @json($this->botDataPayload());

            return {
                bots: Array.isArray(seed) ? seed : [],
                loading: !Array.isArray(seed),
                fetching: false,
                clock: '',
                // Which bot the whole page is focused on. Seeded from the
                // Livewire $selectedBotId at render and kept in sync with the
                // top selector via $wire.$watch below — one source of truth.
                selectedBotId: initialBotId,

                init() {
                    this.tick();
                    // First paint comes from the server-rendered seed (no extra
                    // round-trip); fetch immediately only if there was none.
                    if (this.loading) this.fetchData();
                    // Both timers stop for good once the stale-panel guard
                    // (filament.components.stale-panel-guard) has seen a failed
                    // Livewire request: a tab left open across a deploy must
                    // not keep posting its old snapshot every 30s.
                    const clockTimer = setInterval(() => this.tick(), 1000);
                    const dataTimer = setInterval(() => this.fetchData(), 30000);
                    const stop = () => { clearInterval(clockTimer); clearInterval(dataTimer); };
                    if (window.__atPanelStale) stop();
                    window.addEventListener('at-panel-stale', stop, { once: true });

                    // Follow the top selector: when Livewire's selectedBotId
                    // changes (a pill click), refocus the live view on it too.
                    if (this.$wire) {
                        this.$wire.$watch('selectedBotId', (value) => {
                            this.selectedBotId = value;
                        });
                    }
                },

                // The bot(s) the live view renders: just the selected one when
                // it is present in the active fleet, otherwise the whole fleet
                // (covers "nothing selected yet" and any stale selection).
                get visibleBots() {
                    if (this.selectedBotId === null || this.selectedBotId === undefined) {
                        return this.bots;
                    }
                    const match = this.bots.filter(b => String(b.id) === String(this.selectedBotId));
                    return match.length ? match : this.bots;
                },

                // True when the selected bot is one of the active bots rendered
                // below (its chart lives inside its card). Otherwise the page
                // shows the standalone chart: stopped bot, or market only.
                get selectedInFleet() {
                    if (this.selectedBotId === null || this.selectedBotId === undefined || this.selectedBotId === '') return false;
                    return this.bots.some(b => String(b.id) === String(this.selectedBotId));
                },

                tick() {
                    // fa-IR locale already renders Persian digits.
                    this.clock = new Date().toLocaleString('fa-IR');
                },

                // Fresh data from the server on every call (Livewire 3 action;
                // renderless on the server, so it does not re-render the page).
                // A failed refresh keeps the last good data on screen.
                async fetchData() {
                    if (this.fetching || !this.$wire || window.__atPanelStale) return;
                    this.fetching = true;
                    try {
                        const data = await this.$wire.getBotData();
                        if (Array.isArray(data)) {
                            this.bots = data;
                        }
                    } catch (error) {
                        console.error('[bot-monitoring] live refresh failed', error);
                    } finally {
                        this.fetching = false;
                        this.loading = false;
                    }
                },

                getSortedOrders(orders) {
                    return [...orders].sort((a, b) => b.price - a.price);
                },

                formatNum(v) {
                    if (v === null || v === undefined || isNaN(v)) return '—';
                    return faDigits(Math.round(v).toLocaleString('en-US'));
                },

                formatDuration(minutes) {
                    if (!minutes || minutes === 0) return '۰';
                    if (minutes < 60) return faDigits(Math.round(minutes)) + ' دقیقه';
                    if (minutes < 1440) return faDigits((minutes / 60).toFixed(1)) + ' ساعت';
                    return faDigits((minutes / 1440).toFixed(1)) + ' روز';
                },

                formatTimeAgo(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    const now = new Date();
                    const seconds = Math.floor((now - date) / 1000);
                    if (seconds < 60) return 'چند لحظه پیش';
                    if (seconds < 3600) return faDigits(Math.floor(seconds / 60)) + ' دقیقه پیش';
                    if (seconds < 86400) return faDigits(Math.floor(seconds / 3600)) + ' ساعت پیش';
                    if (seconds < 604800) return faDigits(Math.floor(seconds / 86400)) + ' روز پیش';
                    return date.toLocaleDateString('fa-IR', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                }
            }
        }

        // ------------------------------------------------------------------
        // «نمودار قیمت» — TradingView Lightweight Charts (pinned, loaded once
        // from the CDN on first use; attribution logo left enabled per its
        // license). Everything is wrapped so a chart/CDN failure only ever
        // shows this section's empty state and never breaks the page.
        // ------------------------------------------------------------------
        const AT_LWC_URL = 'https://cdn.jsdelivr.net/npm/lightweight-charts@5.2.1/dist/lightweight-charts.standalone.production.js';
        const AT_LWC_SRI = 'sha384-KkYqTZlM13Zya6fVUF3IGCBOQ1ehFeJVVgxaVvD/0MebineF9EI8Jkd3NnWNChqN';

        window.atLoadLightweightCharts = window.atLoadLightweightCharts || function () {
            if (window.LightweightCharts) return Promise.resolve(window.LightweightCharts);
            if (!window.__atLwcPromise) {
                window.__atLwcPromise = new Promise((resolve, reject) => {
                    const s = document.createElement('script');
                    s.src = AT_LWC_URL;
                    s.integrity = AT_LWC_SRI;
                    s.crossOrigin = 'anonymous';
                    s.async = true;
                    s.onload = () => window.LightweightCharts
                        ? resolve(window.LightweightCharts)
                        : reject(new Error('LightweightCharts global missing'));
                    s.onerror = () => { window.__atLwcPromise = null; reject(new Error('LightweightCharts failed to load')); };
                    document.head.appendChild(s);
                });
            }
            return window.__atLwcPromise;
        };

        function priceChart() {
            const TEHRAN = 'Asia/Tehran';
            const COLORS = {
                bg: '#0B1220', text: '#8d9cb0', grid: 'rgba(35, 51, 73, 0.45)', border: '#233349',
                up: '#23d18b', down: '#ff5d68', buyMark: '#34D399', start: '#8d9cb0',
            };

            // Non-reactive handles (kept out of Alpine's proxies on purpose).
            let chart = null, series = null, lines = [], ro = null, timer = null, lwc = null;
            let levelRange = null, userMoved = false;
            // Fill / start markers: one markers primitive per chart, updated in
            // place with setMarkers() on every refresh (the chart is never
            // re-created for them). fillsByT feeds the crosshair legend.
            let markersApi = null, lastMarkers = [], lastStartT = null, fillsByT = new Map();
            // «معاملات» toggle: in memory for this page session only (shared by
            // re-created chart components, gone on reload).
            if (typeof window.__atChartShowFills !== 'boolean') window.__atChartShowFills = true;

            return {
                timeframes: [
                    { res: '1',  label: '۱ دقیقه' },
                    { res: '15', label: '۱۵ دقیقه' },
                    { res: '60', label: '۱ ساعت' },
                    { res: 'D',  label: '۱ روز' },
                ],
                resolution: '15',
                status: 'loading',
                live: false,
                unit: null,
                symbol: null,
                candleCount: 0,
                levelCount: 0,
                fetching: false,
                destroyed: false,
                seq: 0,
                fitNext: true,
                mode: 'bot',
                staleLevels: 0,
                showFills: window.__atChartShowFills,
                legend: '',

                get hasData() { return this.status === 'ok' && this.candleCount > 0; },
                get unitLabel() { return this.unit === 'IRR' ? 'ریال' : (this.unit || ''); },
                get emptyMessage() {
                    if (this.status === 'loading') return 'در حال بارگذاری نمودار…';
                    if (this.status === 'lib_error') return 'نمودار بارگذاری نشد — داده‌ای در دسترس نیست';
                    return 'داده‌ای در دسترس نیست';
                },
                // One honest line under the chart when it is not a live bot view.
                get note() {
                    if (this.mode === 'market') return 'ربات فعالی انتخاب نشده — فقط قیمت بازار';
                    if (this.mode === 'inactive') {
                        return 'ربات متوقف — فقط معاملات گذشته'
                            + (this.staleLevels > 0 ? ' · ' + faDigits(this.staleLevels) + ' سفارش «ثبت‌شده» رسم نشد' : '');
                    }
                    return '';
                },

                toggleFills() {
                    this.showFills = !this.showFills;
                    window.__atChartShowFills = this.showFills;
                    this.legend = '';
                    this.drawMarkers();
                },

                init() {
                    // Selector change: the live view re-creates this component for the
                    // newly selected bot (x-for key + x-if), which re-runs init() and
                    // fetches that bot's chart. The parent's $wire.$watch('selectedBotId')
                    // drives that; nothing extra to subscribe to here.
                    this.load(true);
                    timer = setInterval(() => this.load(false), 10000);
                    // Stale panel (see botMonitoring.init): stop refreshing.
                    window.addEventListener('at-panel-stale', () => {
                        if (timer) clearInterval(timer);
                        timer = null;
                    }, { once: true });
                },

                destroy() {
                    this.destroyed = true;
                    if (timer) clearInterval(timer);
                    try { if (ro) ro.disconnect(); } catch (e) {}
                    try { if (chart) chart.remove(); } catch (e) {}
                    chart = series = ro = timer = markersApi = null;
                    lines = [];
                    fillsByT = new Map();
                },

                setResolution(res) {
                    if (res === this.resolution) return;
                    this.resolution = res;
                    this.fitNext = true;
                    this.load(true);
                },

                async load(force) {
                    if (this.destroyed || !this.$wire || window.__atPanelStale) return;
                    if (this.fetching && !force) return;
                    const mySeq = ++this.seq;
                    const res = this.resolution;
                    this.fetching = true;
                    try {
                        const data = await this.$wire.getChartData(res);
                        if (this.destroyed || mySeq !== this.seq || res !== this.resolution) return;
                        await this.apply(data);
                    } catch (error) {
                        console.error('[price-chart] refresh failed', error);
                        // Keep the last good candles on screen, but stop calling them live.
                        if (mySeq === this.seq) this.live = false;
                        if (mySeq === this.seq && !this.hasData && this.status === 'loading') this.status = 'error';
                    } finally {
                        if (mySeq === this.seq) this.fetching = false;
                    }
                },

                async apply(data) {
                    const candles = (data && Array.isArray(data.candles)) ? data.candles : [];
                    this.symbol = data && data.symbol ? data.symbol : null;
                    this.unit = data && data.unit ? data.unit : null;
                    this.mode = data && data.mode ? data.mode : 'bot';
                    this.staleLevels = data && Number(data.stale_levels) > 0 ? Number(data.stale_levels) : 0;

                    if (!data || data.status !== 'ok' || candles.length === 0) {
                        this.status = (data && data.status && data.status !== 'ok') ? data.status : 'no_data';
                        this.live = false;
                        this.candleCount = 0;
                        return;
                    }

                    try {
                        lwc = lwc || await window.atLoadLightweightCharts();
                    } catch (e) {
                        console.error('[price-chart] library unavailable', e);
                        this.status = 'lib_error';
                        this.candleCount = 0;
                        return;
                    }
                    if (this.destroyed) return;

                    this.status = 'ok';
                    this.live = data.live === true;
                    this.candleCount = candles.length;
                    await this.$nextTick(); // canvas container is now displayed

                    try {
                        this.ensureChart();
                        const isIrr = this.unit === 'IRR';
                        series.applyOptions({
                            priceFormat: { type: 'price', precision: isIrr ? 0 : 2, minMove: isIrr ? 1 : 0.01 },
                        });
                        chart.applyOptions({ timeScale: { timeVisible: this.resolution !== 'D', secondsVisible: false } });
                        series.setData(candles.map(c => ({
                            time: Number(c.t),
                            open: Number(c.o), high: Number(c.h), low: Number(c.l), close: Number(c.c),
                        })));
                        this.drawLevels(Array.isArray(data.levels) ? data.levels : []);
                        lastMarkers = Array.isArray(data.markers) ? data.markers : [];
                        lastStartT = Number.isFinite(Number(data.start_t)) && data.start_t !== null ? Number(data.start_t) : null;
                        this.drawMarkers();
                        if (this.fitNext) { this.fit(); this.fitNext = false; }
                    } catch (e) {
                        console.error('[price-chart] render failed', e);
                        this.status = 'lib_error';
                        this.candleCount = 0;
                    }
                },

                fit() {
                    chart.timeScale().fitContent();
                    userMoved = false;
                },

                // Remove every previous line, then draw the current open orders.
                drawLevels(levels) {
                    lines.forEach(l => { try { series.removePriceLine(l); } catch (e) {} });
                    lines = [];
                    levelRange = null;
                    levels.forEach(lv => {
                        const price = Number(lv.price);
                        if (!isFinite(price) || price <= 0) return;
                        const buy = lv.side === 'buy';
                        levelRange = levelRange === null
                            ? { min: price, max: price }
                            : { min: Math.min(levelRange.min, price), max: Math.max(levelRange.max, price) };
                        lines.push(series.createPriceLine({
                            price,
                            color: buy ? COLORS.up : COLORS.down,
                            lineWidth: 1,
                            lineStyle: lwc.LineStyle.Dashed,
                            axisLabelVisible: true,
                            title: buy ? 'خرید' : 'فروش',
                        }));
                    });
                    this.levelCount = lines.length;
                },

                // Fill markers (toggle) + the bot start marker, via setMarkers().
                // Server already merged fills per (candle, side) and capped them.
                // No text on fill markers; the start marker carries «شروع».
                drawMarkers() {
                    if (!series || !lwc) return;
                    fillsByT = new Map();
                    const out = [];
                    if (this.showFills) {
                        lastMarkers.forEach(m => {
                            const t = Number(m.t);
                            if (!Number.isFinite(t)) return;
                            const buy = m.side === 'buy';
                            out.push({
                                time: t,
                                position: buy ? 'belowBar' : 'aboveBar',
                                shape: buy ? 'arrowUp' : 'arrowDown',
                                color: buy ? COLORS.buyMark : COLORS.down,
                                size: 0.6,
                            });
                            if (!fillsByT.has(t)) fillsByT.set(t, []);
                            fillsByT.get(t).push(m);
                        });
                    }
                    if (lastStartT !== null) {
                        out.push({ time: lastStartT, position: 'aboveBar', shape: 'circle', color: COLORS.start, size: 0.4, text: 'شروع' });
                    }
                    out.sort((a, b) => a.time - b.time);
                    if (!markersApi) {
                        markersApi = lwc.createSeriesMarkers(series, out);
                    } else {
                        markersApi.setMarkers(out);
                    }
                },

                // Crosshair legend: only on a candle that has fills.
                onCrosshair(param) {
                    const t = param && param.time !== undefined && param.point ? Number(param.time) : null;
                    const rows = t !== null ? fillsByT.get(t) : null;
                    if (!rows || !rows.length) { if (this.legend) this.legend = ''; return; }
                    const esc = (v) => String(v).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
                    this.legend = rows.map(m => {
                        const buy = m.side === 'buy';
                        const num = (v) => '<bdi dir="ltr">' + esc(v) + '</bdi>';
                        let txt = '<span class="' + (buy ? 'buy' : 'sell') + '">' + (buy ? 'خرید' : 'فروش')
                            + (Number(m.count) > 1 ? ' ×' + faDigits(m.count) : '') + '</span> '
                            + (Number(m.count) > 1 ? 'میانگین ' : '') + num(this.fmtPrice(Number(m.price)))
                            + ' · مقدار ' + num(faDigits(String(m.amount)));
                        if (m.cycle_profit !== null && m.cycle_profit !== undefined) {
                            const p = Number(m.cycle_profit);
                            txt += ' · سود چرخه <span class="' + (p >= 0 ? 'buy' : 'sell') + '">'
                                + num(faDigits(Math.round(p).toLocaleString('en-US'))) + '</span>';
                        }
                        return txt;
                    }).join('<br>');
                },

                fmtPrice(p) {
                    if (p === null || p === undefined || !isFinite(p)) return '';
                    const digits = this.unit === 'IRR' ? 0 : 2;
                    return faDigits(Number(p).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits }));
                },

                fmtTime(t, opts) {
                    const ts = typeof t === 'number' ? t * 1000 : Date.UTC(t.year, t.month - 1, t.day);
                    return new Date(ts).toLocaleString('fa-IR', Object.assign({ timeZone: TEHRAN }, opts));
                },

                chartHeight(width) { return width < 520 ? 280 : 380; },

                ensureChart() {
                    if (chart) return;
                    const el = this.$refs.canvas;
                    const width = Math.max(el.clientWidth || 0, 280);
                    const self = this;
                    chart = lwc.createChart(el, {
                        width,
                        height: this.chartHeight(width),
                        layout: {
                            background: { type: 'solid', color: COLORS.bg },
                            textColor: COLORS.text,
                            fontFamily: "Vazirmatn, system-ui, sans-serif",
                            fontSize: 11,
                            attributionLogo: true,
                        },
                        grid: { vertLines: { color: COLORS.grid }, horzLines: { color: COLORS.grid } },
                        rightPriceScale: { borderColor: COLORS.border },
                        timeScale: { borderColor: COLORS.border, timeVisible: true, secondsVisible: false,
                            tickMarkFormatter: (time, type) => {
                                // 0 Year, 1 Month, 2 DayOfMonth, 3 Time, 4 TimeWithSeconds
                                if (type === 0) return self.fmtTime(time, { year: 'numeric' });
                                if (type === 1) return self.fmtTime(time, { month: 'short' });
                                if (type === 2) return self.fmtTime(time, { day: 'numeric', month: 'short' });
                                return self.fmtTime(time, { hour: '2-digit', minute: '2-digit', hour12: false });
                            },
                        },
                        crosshair: { mode: 0 },
                        localization: {
                            locale: 'fa-IR',
                            priceFormatter: (p) => self.fmtPrice(p),
                            timeFormatter: (t) => self.fmtTime(t, self.resolution === 'D'
                                ? { year: 'numeric', month: 'short', day: 'numeric' }
                                : { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }),
                        },
                    });
                    series = chart.addSeries(lwc.CandlestickSeries, {
                        upColor: COLORS.up, downColor: COLORS.down,
                        borderUpColor: COLORS.up, borderDownColor: COLORS.down,
                        wickUpColor: COLORS.up, wickDownColor: COLORS.down,
                        // No series last-price line: it would read like a grid level.
                        // The last price stays labelled on the axis.
                        priceLineVisible: false,
                        // Keep the open grid levels inside the visible price range.
                        autoscaleInfoProvider: (original) => {
                            const res = original();
                            if (!res || !res.priceRange || levelRange === null) return res;
                            return {
                                priceRange: {
                                    minValue: Math.min(res.priceRange.minValue, levelRange.min),
                                    maxValue: Math.max(res.priceRange.maxValue, levelRange.max),
                                },
                                margins: res.margins,
                            };
                        },
                    });

                    chart.subscribeCrosshairMove((param) => this.onCrosshair(param));

                    if (window.ResizeObserver) {
                        let lastW = width;
                        ro = new ResizeObserver(entries => {
                            const w = Math.floor(entries[0].contentRect.width);
                            if (!chart || w <= 0 || w === lastW) return;
                            lastW = w;
                            chart.resize(w, this.chartHeight(w));
                            // Re-fit while the user has not panned/zoomed yet (first layout).
                            if (!userMoved) chart.timeScale().fitContent();
                        });
                        ro.observe(el);
                    }
                    // Once the user pans/zooms, stop auto-fitting on resize.
                    ['pointerdown', 'wheel', 'touchstart'].forEach(ev =>
                        el.addEventListener(ev, () => { userMoved = true; }, { passive: true }));
                },
            };
        }

        function activityLog() {
            return {
                activeFilter: 'all',

                getFilteredCycles(cycles) {
                    if (!cycles) return [];
                    if (this.activeFilter === 'errors') return cycles.filter(c => c.summary.errors > 0);
                    if (this.activeFilter === 'api') return cycles.filter(c => c.summary.api_calls > 0);
                    if (this.activeFilter === 'cycles') return cycles.filter(c => c.status !== 'ungrouped');
                    return cycles;
                },

                getErrorCyclesCount(cycles) {
                    if (!cycles) return 0;
                    return cycles.filter(c => c.summary.errors > 0).length;
                },

                getApiCallsCount(cycles) {
                    if (!cycles) return 0;
                    return cycles.reduce((total, c) => total + c.summary.api_calls, 0);
                },

                cycleDot(status) {
                    return { success: 'pos', warning: 'warn', error: 'neg', in_progress: 'pos', ungrouped: 'muted' }[status] || 'muted';
                },

                cycleTitle(status) {
                    return {
                        success: 'اجرای بررسی موفق',
                        warning: 'اجرای بررسی با هشدار',
                        error: 'اجرای بررسی با خطا',
                        in_progress: 'اجرای بررسی در حال انجام',
                        ungrouped: 'لاگ‌های متفرقه'
                    }[status] || 'اجرای بررسی';
                },

                cycleLabel(status) {
                    return {
                        success: 'موفق',
                        warning: 'هشدار',
                        error: 'ناموفق',
                        in_progress: 'در اجرا',
                        ungrouped: 'متفرقه'
                    }[status] || '—';
                },

                eventTag(type) {
                    if (!type) return 'EVENT';
                    if (type.includes('CHECK')) return 'CYCLE';
                    return ({
                        API_CALL: 'API',
                        ORDERS_RECEIVED: 'ORDERS',
                        ORDER_PLACED: 'ORDER',
                        ORDER_FILLED: 'FILL',
                        ORDER_PAIRED: 'PAIR',
                        TRADE_COMPLETED: 'TRADE',
                        ERROR: 'ERROR'
                    }[type] || 'EVENT');
                },

                formatCycleDuration(durationMs) {
                    if (!durationMs || durationMs === 0) return '0s';
                    const seconds = durationMs / 1000;
                    if (seconds < 1) return faDigits(durationMs.toFixed(0)) + 'ms';
                    if (seconds < 60) return faDigits(seconds.toFixed(1)) + 's';
                    if (seconds < 3600) return faDigits((seconds / 60).toFixed(1)) + 'm';
                    return faDigits((seconds / 3600).toFixed(1)) + 'h';
                },

                formatTimeAgo(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    const now = new Date();
                    const seconds = Math.floor((now - date) / 1000);
                    if (seconds < 10) return 'چند لحظه پیش';
                    if (seconds < 60) return faDigits(seconds) + ' ثانیه پیش';
                    if (seconds < 3600) return faDigits(Math.floor(seconds / 60)) + ' دقیقه پیش';
                    if (seconds < 86400) return faDigits(Math.floor(seconds / 3600)) + ' ساعت پیش';
                    if (seconds < 604800) return faDigits(Math.floor(seconds / 86400)) + ' روز پیش';
                    return date.toLocaleDateString('fa-IR', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
