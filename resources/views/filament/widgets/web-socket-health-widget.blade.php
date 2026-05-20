<div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-950/5 dark:border-white/10">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">وضعیت WebSocket</h3>
        @if ($error === null && $health !== null)
            @php
                $overallDot = match ($health['status']) {
                    'active' => 'bg-green-500',
                    'stale'  => 'bg-yellow-500',
                    'down'   => 'bg-red-500',
                    default  => 'bg-gray-400',
                };
            @endphp
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-block w-2 h-2 rounded-full {{ $overallDot }}"></span>
                {{ $health['label'] }}
            </span>
        @endif
    </div>

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
