@php
    $emoji   = '⚪';
    $label   = 'WS نامشخص';
    $tooltip = '';

    if ($status !== null) {
        $emoji = match ($status['status'] ?? '') {
            'active' => '🟢',
            'stale'  => '🟡',
            'down'   => '🔴',
            default  => '⚪',
        };
        $label   = 'WS ' . ($status['label'] ?? 'نامشخص');
        $age     = $status['newest_age_seconds'] ?? null;
        $tooltip = $age !== null ? 'آخرین به‌روزرسانی: ' . $age . ' ثانیه پیش' : '';
    }
@endphp

<a href="/admin"
   class="inline-flex items-center px-2 py-1 rounded-full text-xs text-gray-400 hover:text-white hover:bg-white/10 transition-colors duration-150 select-none"
   @if ($tooltip) title="{{ $tooltip }}" @endif>
    <span style="margin-inline-end:4px">{{ $emoji }}</span>
    <span>{{ $label }}</span>
</a>
