@php
    $dotColor  = '#9ca3af'; // gray fallback
    $isActive  = false;
    $label     = 'WS نامشخص';
    $tooltip   = '';

    if ($status !== null) {
        $dotColor = match ($status['status'] ?? '') {
            'active' => '#22c55e',
            'stale'  => '#eab308',
            'down'   => '#ef4444',
            default  => '#9ca3af',
        };
        $isActive = ($status['status'] ?? '') === 'active';
        $label    = 'WS ' . ($status['label'] ?? 'نامشخص');
        $age      = $status['newest_age_seconds'] ?? null;
        $tooltip  = $age !== null ? 'آخرین به‌روزرسانی: ' . $age . ' ثانیه پیش' : '';
    }

    $dotStyle = implode(';', [
        'display:inline-block',
        'width:8px',
        'height:8px',
        'border-radius:9999px',
        'background-color:' . $dotColor,
        'margin-inline-end:6px',
        'flex-shrink:0',
        $isActive ? 'box-shadow:0 0 4px ' . $dotColor : '',
    ]);
@endphp

@if ($isActive)
<style>
    @keyframes ws-dot-pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%       { opacity: .65; transform: scale(.85); }
    }
    .ws-dot-active { animation: ws-dot-pulse 2s ease-in-out infinite; }
</style>
@endif

<a href="/admin"
   class="inline-flex items-center px-2 py-1 rounded-full text-xs text-gray-400 hover:text-white hover:bg-white/10 transition-colors duration-150 select-none"
   @if ($tooltip) title="{{ $tooltip }}" @endif>
    <span style="{{ $dotStyle }}" @if ($isActive) class="ws-dot-active" @endif></span>
    <span>{{ $label }}</span>
</a>
