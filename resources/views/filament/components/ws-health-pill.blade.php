@php
    $dot     = 'bg-gray-400';
    $label   = 'WS نامشخص';
    $tooltip = '';

    if ($status !== null) {
        $dot = match ($status['status'] ?? '') {
            'active' => 'bg-green-500',
            'stale'  => 'bg-yellow-500',
            'down'   => 'bg-red-500',
            default  => 'bg-gray-400',
        };
        $label   = 'WS ' . ($status['label'] ?? 'نامشخص');
        $age     = $status['newest_age_seconds'] ?? null;
        $tooltip = $age !== null ? 'آخرین به‌روزرسانی: ' . $age . ' ثانیه پیش' : '';
    }
@endphp

<a href="/admin"
   class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs text-gray-400 hover:text-white hover:bg-white/10 transition-colors duration-150 select-none"
   @if ($tooltip) title="{{ $tooltip }}" @endif>
    <span class="inline-block w-2 h-2 rounded-full shrink-0 {{ $dot }}"></span>
    <span>{{ $label }}</span>
</a>
