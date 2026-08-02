{{--
    Bot Intelligence — Phase P4 (part 2): deep single-bot analysis, dense.

    ROLE: deep dive on ONE selected bot — health snapshot, capital
    concentration, grid drift and the grid map — as opposed to the Dashboard
    (macro fleet overview) and Bot Monitoring (live across active bots). Restyled
    onto the shared compact design system; every data method on the page
    (getSnapshotMetrics / getCapitalConcentration / getGridDrift / …) is
    untouched, so the P2/P3 metric-correctness tests keep passing.
--}}
<x-filament-panels::page>
    @php
        $availableBots        = $this->getAvailableBots();
        $metrics              = $this->getSnapshotMetrics();
        $gridMap              = $this->getGridMapData();
        $openOrders           = $this->getOpenOrders();
        $completedPairs       = $this->getCompletedPairs();
        $capitalConcentration = $this->getCapitalConcentration();
        $gridDrift            = $this->getGridDrift();
        $systemHealth         = $this->getSystemHealth();
        $activityLogs         = $this->getActivityLogs();

        // Map Filament semantic colours → terminal tones (accent / red / amber / muted).
        $tone = fn (?string $c): string => [
            'success'   => 'pos',
            'primary'   => 'pos',
            'info'      => 'pos',
            'danger'    => 'neg',
            'warning'   => 'warn',
        ][$c] ?? 'muted';
    @endphp

    <div class="bot-intel at-stack">
        @if($selectedBot)
            {{-- Head: role + bot selector --}}
            <div class="at-page-head">
                <div>
                    <p class="at-page-head__title">تحلیل تک‌ربات</p>
                    <p class="at-page-head__sub">سلامت، تمرکز سرمایه، انحراف و نقشه گرید ربات انتخاب‌شده</p>
                </div>
                <div class="at-page-head__aside">
                    @if($availableBots->isNotEmpty())
                        <select wire:model.live="selectedBotId" class="at-select">
                            @foreach($availableBots as $bot)
                                <option value="{{ $bot['id'] }}">{{ $bot['name'] }} ({{ $bot['symbol'] }})</option>
                            @endforeach
                        </select>
                        <span class="at-badge {{ $selectedBot->is_active ? 'pos' : 'muted' }}">
                            <span class="at-dot {{ $selectedBot->is_active ? 'pos' : 'muted' }}"></span>
                            {{ $selectedBot->is_active ? 'فعال' : 'متوقف' }}
                        </span>
                    @else
                        <span class="at-t-muted">هیچ رباتی پیکربندی نشده</span>
                    @endif
                </div>
            </div>

            {{-- Snapshot metrics --}}
            <div class="panel-section">
                <div class="panel-section__body">
                    <div class="metric-grid">
                        @foreach($metrics as $metric)
                            @php $t = $tone($metric['color'] ?? null); @endphp
                            <div class="metric-card">
                                <span class="metric-label">{{ $metric['label'] }}</span>
                                <span class="metric-value {{ $t === 'pos' ? 'pos' : ($t === 'neg' ? 'neg' : '') }} {{ $t === 'warn' ? 'at-t-warn' : '' }}">
                                    {{ $metric['value'] }}
                                </span>
                                <span class="metric-sub">{{ $metric['caption'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Grid Map --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <div>
                        <span class="panel-section__title">نقشه گرید</span>
                        <p class="panel-section__sub">توزیع سفارش‌های فعال در سطوح گرید</p>
                    </div>
                    @if($gridMap['has_data'] ?? false)
                        <span class="at-badge muted">{{ $gridMap['total_levels'] }} سطح</span>
                    @endif
                </div>

                @if($gridMap['has_data'] ?? false)
                    <div class="panel-section__body" style="padding-block: var(--at-gap-sm);">
                        <div class="at-row" style="justify-content: space-between; gap: var(--at-gap-md);">
                            <span class="at-kv__k">بالا: <span class="at-num at-t-dim">{{ $gridMap['top_price'] }} IRT</span></span>
                            <span class="at-kv__k">پایین: <span class="at-num at-t-dim">{{ $gridMap['bottom_price'] }} IRT</span></span>
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
                                        <td class="at-t-muted">L{{ $level['index'] }}</td>
                                        <td class="at-num at-mono">{{ $level['price'] }}</td>
                                        <td class="at-num at-t-dim">{{ $level['amount'] }}</td>
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

            {{-- Open Orders | Completed Pairs --}}
            <div class="at-cols-2">
                {{-- Open Orders --}}
                <div class="panel-section">
                    <div class="panel-section__head">
                        <div>
                            <span class="panel-section__title">سفارش‌های باز</span>
                            <p class="panel-section__sub">سفارش‌های فعال اخیر</p>
                        </div>
                    </div>
                    @if($openOrders->isNotEmpty())
                        <table class="data-table">
                            <thead>
                                <tr><th>نوع</th><th class="at-num">قیمت</th><th class="at-num">مقدار</th><th class="at-num">زمان</th></tr>
                            </thead>
                            <tbody>
                                @foreach($openOrders as $order)
                                    <tr>
                                        <td><span class="at-badge {{ $order['side'] === 'buy' ? 'pos' : 'neg' }}">{{ $order['type'] }}</span></td>
                                        <td class="at-num at-mono">{{ $order['price'] }}</td>
                                        <td class="at-num at-t-dim">{{ $order['amount'] }}</td>
                                        <td class="at-num at-t-muted">{{ $order['time_ago'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="at-empty">سفارش بازی وجود ندارد</div>
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
                                        <td class="at-num at-mono at-t-dim">{{ $pair['buy_price'] }} → {{ $pair['sell_price'] }}</td>
                                        <td class="at-num {{ $pair['is_profitable'] ? 'pos' : 'neg' }}">{{ $pair['profit_formatted'] }}</td>
                                        <td class="at-num at-t-muted">{{ $pair['duration'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="at-empty">هنوز معامله‌ای تکمیل نشده</div>
                    @endif
                </div>
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
                                <span class="at-t-pos" style="font-size: var(--at-fs-label);">{{ $capitalConcentration['buy']['percent'] }}%</span>
                            </div>
                            <div class="at-bar" style="margin-block: 4px;"><div class="at-bar__fill" style="inline-size: {{ min(100, $capitalConcentration['buy']['percent']) }}%;"></div></div>
                            <p class="at-kv__k">{{ $capitalConcentration['buy']['count'] }} سفارش · {{ $capitalConcentration['buy']['capital'] }} IRT</p>
                        </div>
                        {{-- Sell --}}
                        <div>
                            <div class="at-row" style="justify-content: space-between;">
                                <span class="at-kv__k">سفارش‌های فروش</span>
                                <span class="at-t-neg" style="font-size: var(--at-fs-label);">{{ $capitalConcentration['sell']['percent'] }}%</span>
                            </div>
                            <div class="at-bar" style="margin-block: 4px;"><div class="at-bar__fill neg" style="inline-size: {{ min(100, $capitalConcentration['sell']['percent']) }}%;"></div></div>
                            <p class="at-kv__k">{{ $capitalConcentration['sell']['count'] }} سفارش · {{ $capitalConcentration['sell']['capital'] }} IRT</p>
                        </div>
                        {{-- Free --}}
                        <div>
                            <div class="at-row" style="justify-content: space-between;">
                                <span class="at-kv__k">سرمایه آزاد</span>
                                <span class="at-t-muted" style="font-size: var(--at-fs-label);">{{ $capitalConcentration['free']['percent'] }}%</span>
                            </div>
                            <div class="at-bar" style="margin-block: 4px;"><div class="at-bar__fill muted" style="inline-size: {{ min(100, $capitalConcentration['free']['percent']) }}%;"></div></div>
                            <p class="at-kv__k">{{ $capitalConcentration['free']['capital'] }} IRT قابل استفاده</p>
                        </div>
                    </div>
                </div>

                {{-- Grid Drift --}}
                <div class="panel-section">
                    <div class="panel-section__head">
                        <div>
                            <span class="panel-section__title">انحراف گرید</span>
                            <p class="panel-section__sub">شاخص ناحیه معاملاتی</p>
                        </div>
                    </div>
                    <div class="panel-section__body">
                        <div style="text-align: center;">
                            <span class="metric-value {{ $tone($gridDrift['color'] ?? null) === 'pos' ? 'pos' : '' }} {{ $tone($gridDrift['color'] ?? null) === 'warn' ? 'at-t-warn' : '' }}"
                                  style="font-size: 24px;">{{ round($gridDrift['position']) }}%</span>
                            <p style="font-size: var(--at-fs-body); color: var(--at-text); margin-block-start: var(--at-gap-xs);">{{ $gridDrift['status'] }}</p>
                            <p class="at-kv__k">{{ $gridDrift['description'] }}</p>
                        </div>
                        <div style="margin-block-start: var(--at-gap-md);">
                            <div class="at-row at-kv__k" style="justify-content: space-between;">
                                <span>پایین</span><span>بالا</span>
                            </div>
                            <div class="at-bar" style="block-size: 8px; margin-block-start: 4px;">
                                <div style="position: absolute; inset-block: 0; inset-inline-start: {{ min(100, max(0, $gridDrift['position'])) }}%; inline-size: 3px; background: var(--at-accent); border-radius: 2px; transform: translateX(-50%);"></div>
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
                            <p class="at-kv__k">{{ $systemHealth['stability']['errors_24h'] }} خطا در ۲۴ ساعت گذشته</p>
                        </div>
                        @if($systemHealth['stability']['errors_24h'] > 0)
                            <p class="at-kv__k at-t-warn" style="margin-block-start: var(--at-gap-sm); text-align: center;">برای جزئیات، خط زمانی فعالیت را بررسی کنید</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- System Health --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <div>
                        <span class="panel-section__title">سلامت سیستم</span>
                        <p class="panel-section__sub">وضعیت زیرساخت و اتصال</p>
                    </div>
                </div>
                <div class="panel-section__body">
                    <div class="metric-grid">
                        @foreach($systemHealth as $key => $health)
                            @if($key !== 'stability')
                                <div class="metric-card is-row">
                                    <span class="metric-label">
                                        <span class="at-dot {{ $tone($health['color'] ?? null) }}"></span>
                                        {{ $health['label'] }}
                                    </span>
                                    <span class="metric-value" style="font-size: var(--at-fs-body);">{{ $health['value'] }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Activity Timeline --}}
            <div class="panel-section">
                <div class="panel-section__head">
                    <div>
                        <span class="panel-section__title">خط زمانی فعالیت</span>
                        <p class="panel-section__sub">رویدادهای اخیر ربات</p>
                    </div>
                </div>

                @if($activityLogs->isNotEmpty())
                    <div class="at-scroll" style="max-height: 460px;">
                        @foreach($activityLogs as $log)
                            <div style="border-block-end: 1px solid var(--at-border); padding: var(--at-gap-sm) var(--at-gap-md);"
                                 @if($log['has_api_data']) x-data="{ open: false }" @endif>
                                <div class="at-row" style="justify-content: space-between; gap: var(--at-gap-sm); align-items: baseline;">
                                    <div class="at-row" style="align-items: baseline; gap: var(--at-gap-sm); flex: 1; min-inline-size: 0;">
                                        <span class="at-dot {{ $tone($log['color'] ?? null) }}"></span>
                                        <span style="font-size: var(--at-fs-body); color: var(--at-text-dim); flex: 1;">{{ $log['message'] }}</span>
                                    </div>
                                    <span class="at-mono at-t-muted" style="font-size: var(--at-fs-label);">{{ $log['time_ago'] }}</span>
                                </div>
                                <div class="at-row at-kv__k" style="gap: var(--at-gap-xs); margin-inline-start: 16px; margin-block-start: 2px;">
                                    <span>{{ $log['action_type'] }}</span>
                                    @if($log['execution_time'])
                                        <span class="at-t-warn at-mono">· {{ $log['execution_time'] }}ms</span>
                                    @endif
                                    @if($log['has_api_data'])
                                        <button type="button" @click="open = !open" class="at-t-pos" style="background: none; border: none; cursor: pointer; font-size: var(--at-fs-label); padding: 0;">
                                            <span x-show="!open">· جزئیات API</span>
                                            <span x-show="open" style="display: none;">· بستن</span>
                                        </button>
                                    @endif
                                </div>
                                @if($log['has_api_data'])
                                    <div x-show="open" x-collapse style="display: none; margin-block-start: var(--at-gap-xs); background: var(--at-bg); border: 1px solid var(--at-border); border-radius: var(--at-radius-sm); padding: var(--at-gap-sm);">
                                        @if($log['api_request'])
                                            <p class="at-kv__k" style="margin-block-end: 2px;">درخواست:</p>
                                            <pre class="at-mono at-scroll" style="font-size: 11px; color: var(--at-text-dim); direction: ltr; margin: 0 0 var(--at-gap-sm); max-height: 160px;">{{ json_encode($log['api_request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        @endif
                                        @if($log['api_response'])
                                            <p class="at-kv__k" style="margin-block-end: 2px;">پاسخ:</p>
                                            <pre class="at-mono at-scroll" style="font-size: 11px; color: var(--at-text-dim); direction: ltr; margin: 0; max-height: 200px;">{{ json_encode($log['api_response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="at-empty">
                        <div class="at-empty__icon">⌁</div>
                        هنوز گزارشی از فعالیت وجود ندارد
                    </div>
                @endif
            </div>

        @else
            {{-- No bot selected --}}
            <div class="panel-section">
                <div class="at-empty" style="padding: var(--at-gap-lg);">
                    <div class="at-empty__icon">🤖</div>
                    <p style="color: var(--at-text); font-size: var(--at-fs-body);">رباتی انتخاب نشده است</p>
                    <p class="at-kv__k">برای مشاهده تحلیل، یک ربات پیکربندی کنید</p>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
