{{--
    WebSocket health tile — Phase P4 (part 2).

    Full rollout of the compact "admin / terminal" design system: the shell is a
    .panel-section, the summary is a .metric-card metric, and the symbol list is
    the shared .data-table (thin dividers, tiny uppercase heads, LTR/tabular
    numeric cells). Presentation only — getViewData() in the widget is untouched.
--}}
<div class="panel-section">
    {{-- Header --}}
    <div class="panel-section__head">
        <span class="panel-section__title">وضعیت WebSocket</span>
        @if ($error === null && $health !== null)
            @php
                $statusClass = match ($health['status']) {
                    'active' => 'pos',
                    'stale'  => 'warn',
                    'down'   => 'neg',
                    default  => 'muted',
                };
                $activeCount = collect($rows)->where('status', 'active')->count();
            @endphp
            <span class="at-badge {{ $statusClass }}">
                <span class="at-dot {{ $statusClass }}"></span>
                {{ $health['label'] }}
            </span>
        @endif
    </div>

    @if ($error !== null)
        {{-- Error fallback --}}
        <div class="at-empty">{{ $error }}</div>
    @else
        {{-- Summary metric: active vs total monitored symbols --}}
        <div class="panel-section__body" style="border-block-end: 1px solid var(--at-border);">
            <div class="metric-grid">
                <div>
                    <span class="metric-label">نمادهای فعال</span>
                    <span class="metric-value {{ $activeCount > 0 ? 'pos' : '' }}">{{ $activeCount }} / {{ count($rows) }}</span>
                </div>
                <div>
                    <span class="metric-label">آخرین بررسی</span>
                    <span class="metric-value" style="direction: ltr; text-align: start;">{{ $checkedAgo }}s</span>
                    <span class="metric-sub">ثانیه قبل</span>
                </div>
            </div>
        </div>

        {{-- Symbol table --}}
        <table class="data-table">
            <thead>
                <tr>
                    <th>نماد</th>
                    <th>وضعیت</th>
                    <th class="at-num">قیمت آخر</th>
                    <th class="at-num">عمر داده</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $symbol => $info)
                    @php
                        [$statusClass, $label] = match ($info['status']) {
                            'active' => ['pos',  'فعال'],
                            'stale'  => ['warn', 'تأخیر'],
                            'down'   => ['neg',  'قطع'],
                            default  => ['muted', '؟'],
                        };
                    @endphp
                    <tr>
                        <td class="at-mono">{{ $symbol }}</td>
                        <td>
                            <span class="at-badge {{ $statusClass }}">
                                <span class="at-dot {{ $statusClass }}"></span>{{ $label }}
                            </span>
                        </td>
                        <td class="at-num">{{ $info['price'] ?? '—' }}</td>
                        <td class="at-num" style="color: var(--at-muted);">
                            {{ $info['age_seconds'] !== null ? $info['age_seconds'] . 's' : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="at-empty">هیچ نمادی پیکربندی نشده است</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>
