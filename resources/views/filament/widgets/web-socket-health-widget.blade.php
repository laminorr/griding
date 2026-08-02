{{--
    Phase P4 (part 1) demo tile: this widget's shell now uses the shared
    compact "admin / terminal" design-system classes (.metric-card,
    .metric-label, .metric-value, .panel-section) as a live proof that the
    tokens load. The table below is left as-is; the full rollout is part 2.
--}}
<div class="metric-card" style="padding: 0; overflow: hidden;">
    {{-- Header (panel-section head) --}}
    <div class="panel-section__head">
        <span class="metric-label" style="margin: 0;">وضعیت WebSocket</span>
        @if ($error === null && $health !== null)
            @php
                $overallEmoji = match ($health['status']) {
                    'active' => '🟢',
                    'stale'  => '🟡',
                    'down'   => '🔴',
                    default  => '⚪',
                };
                $activeCount = collect($rows)->where('status', 'active')->count();
            @endphp
            <span class="inline-flex items-center" style="gap: var(--at-gap-xs); color: var(--at-muted); font-size: var(--at-fs-label);">
                {{ $overallEmoji }} {{ $health['label'] }}
            </span>
        @endif
    </div>

    @if ($error === null && $health !== null)
        {{-- Demo metric: active vs total monitored symbols --}}
        <div style="padding: var(--at-gap-md); border-bottom: 1px solid var(--at-border);">
            <span class="metric-label">نمادهای فعال</span>
            <span class="metric-value {{ $activeCount > 0 ? 'pos' : '' }}">{{ $activeCount }} / {{ count($rows) }}</span>
        </div>
    @endif

    @if ($error !== null)
        {{-- Error fallback --}}
        <div class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
            {{ $error }}
        </div>
    @else
        {{-- Symbol table --}}
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-950/5 dark:border-white/10">
                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Symbol</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">وضعیت</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400 ltr">قیمت آخر</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">عمر داده</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                @forelse ($rows as $symbol => $info)
                    @php
                        [$emoji, $labelClass] = match ($info['status']) {
                            'active' => ['🟢', 'text-green-600 dark:text-green-400'],
                            'stale'  => ['🟡', 'text-yellow-600 dark:text-yellow-400'],
                            'down'   => ['🔴', 'text-red-600 dark:text-red-400'],
                            default  => ['⚪', 'text-gray-500'],
                        };
                        $label = match ($info['status']) {
                            'active' => 'فعال',
                            'stale'  => 'تأخیر',
                            'down'   => 'قطع',
                            default  => '؟',
                        };
                    @endphp
                    <tr>
                        <td class="px-4 py-2.5 font-mono text-gray-950 dark:text-white">{{ $symbol }}</td>
                        <td class="px-4 py-2.5 {{ $labelClass }}">{{ $emoji }} {{ $label }}</td>
                        <td class="px-4 py-2.5 ltr tabular-nums text-gray-700 dark:text-gray-300">
                            {{ $info['price'] ?? '—' }}
                        </td>
                        <td class="px-4 py-2.5 ltr tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $info['age_seconds'] !== null ? $info['age_seconds'] . ' ثانیه' : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-gray-400 dark:text-gray-500">
                            هیچ نمادی پیکربندی نشده است
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Footer --}}
        <div class="px-4 py-2 border-t border-gray-950/5 dark:border-white/10 text-xs text-gray-400 dark:text-gray-500 ltr">
            Last checked: {{ $checkedAgo }} seconds ago
        </div>
    @endif
</div>
