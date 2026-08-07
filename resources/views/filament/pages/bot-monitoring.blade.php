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
                                              x-text="(bot.profit_change_24h >= 0 ? '▲ ' : '▼ ') + faDigits(Math.abs(bot.profit_change_24h)) + '%'"></span>
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
                                        <span class="metric-chip">سیکل‌های موفق</span>
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
                                            <span class="metric-value" style="direction: ltr;" x-text="formatDuration(bot.avg_cycle_duration || 0)"></span>
                                        </div>
                                        <div class="metric-card is-row">
                                            <span class="metric-label">سفارشات فعال</span>
                                            <span class="metric-value" x-text="faDigits(bot.active_orders.length)"></span>
                                        </div>
                                        <div class="metric-card is-row">
                                            <span class="metric-label">نرخ موفقیت</span>
                                            <span class="metric-value pos" x-text="faDigits(bot.total_cycles > 0 ? '100' : '0') + '%'"></span>
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
                                    <span class="metric-label">وضعیت آخرین چرخه</span>
                                    <span class="metric-value"
                                          :class="{ 'pos': ['success','in_progress'].includes(bot.activity_summary.last_cycle_status), 'neg': bot.activity_summary.last_cycle_status === 'error', 'at-t-warn': bot.activity_summary.last_cycle_status === 'warning' }"
                                          x-text="cycleLabel(bot.activity_summary.last_cycle_status)"></span>
                                    <span class="metric-sub" x-show="bot.activity_summary.last_cycle_time" x-text="formatTimeAgo(bot.activity_summary.last_cycle_time)"></span>
                                </div>
                                <div class="metric-card is-row">
                                    <span class="metric-label">میانگین زمان چرخه</span>
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
                                    <span class="metric-label">چرخه‌ها ۲۴ ساعت</span>
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
                                چرخه‌ها <span class="at-t-muted" x-text="'(' + faDigits(bot.activity_cycles.filter(c => c.status !== 'ungrouped').length) + ')'"></span>
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
            return {
                bots: [],
                loading: true,
                clock: '',
                // Which bot the whole page is focused on. Seeded from the
                // Livewire $selectedBotId at render and kept in sync with the
                // top selector via $wire.$watch below — one source of truth.
                selectedBotId: initialBotId,

                init() {
                    this.tick();
                    this.fetchData();
                    setInterval(() => this.tick(), 1000);
                    setInterval(() => this.fetchData(), 30000);

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

                tick() {
                    // fa-IR locale already renders Persian digits.
                    this.clock = new Date().toLocaleString('fa-IR');
                },

                async fetchData() {
                    try {
                        const data = @json($this->getBotData());
                        this.bots = data;
                        this.loading = false;
                    } catch (error) {
                        console.error('Error:', error);
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
                        success: 'چرخه بررسی ربات',
                        warning: 'چرخه با هشدار',
                        error: 'چرخه با خطا',
                        in_progress: 'چرخه در حال اجرا',
                        ungrouped: 'لاگ‌های متفرقه'
                    }[status] || 'چرخه';
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
