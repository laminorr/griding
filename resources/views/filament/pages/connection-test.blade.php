{{--
    Connection Test — Phase P4 (part 2): restyled onto the compact terminal
    system. The old gradient hero cards and green/red/blue/orange buttons are
    gone; status reads as dense metric-cards, the quick tests use the unified
    accent + neutral .at-btn scale. Livewire actions/methods are unchanged.
--}}
<x-filament-panels::page>
    <div class="at-stack">

        {{-- Connection status --}}
        <div class="panel-section">
            <div class="panel-section__head">
                <span class="panel-section__title">وضعیت اتصال API نوبیتکس</span>
                @php
                    $tone = $this->connectionStatus === 'success' ? 'pos'
                        : ($this->connectionStatus === 'failed' ? 'neg' : 'muted');
                @endphp
                <span class="at-badge {{ $tone }}"><span class="at-dot {{ $tone }}"></span>{{ $this->getConnectionStatusText() }}</span>
            </div>
            <div class="panel-section__body">
                <div class="metric-grid">
                    <div class="metric-card is-row">
                        <span class="metric-label">وضعیت</span>
                        <span class="metric-value {{ $tone === 'pos' ? 'pos' : ($tone === 'neg' ? 'neg' : '') }}">{{ $this->getConnectionStatusText() }}</span>
                    </div>
                    <div class="metric-card is-row">
                        <span class="metric-label">زمان پاسخ</span>
                        <span class="metric-value" style="direction: ltr;">{{ $responseTime ? $responseTime . 'ms' : '—' }}</span>
                    </div>
                    <div class="metric-card is-row">
                        <span class="metric-label">آخرین بررسی</span>
                        <span class="metric-value">{{ $lastChecked ? \Carbon\Carbon::parse($lastChecked)->diffForHumans() : 'هنوز بررسی نشده' }}</span>
                    </div>
                    <div class="metric-card is-row">
                        <span class="metric-label">حالت</span>
                        <span class="metric-value">{{ $simulationMode ? 'شبیه‌سازی' : 'مستقیم' }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- API information --}}
        <div class="panel-section">
            <div class="panel-section__head">
                <span class="panel-section__title">اطلاعات API</span>
            </div>
            <div class="panel-section__body at-stack-sm">
                <div class="at-kv">
                    <span class="at-kv__k">آدرس API</span>
                    <span class="at-kv__v at-mono" style="direction: ltr;">{{ $apiEndpoint }}</span>
                </div>
                <div class="at-kv">
                    <span class="at-kv__k">قیمت بیت‌کوین</span>
                    <span class="at-kv__v at-num {{ $btcPrice ? 'pos' : '' }}">{{ $btcPrice ?: 'نامشخص' }}</span>
                </div>

                @if($accountBalance)
                    <div class="at-kv">
                        <span class="at-kv__k">موجودی BTC</span>
                        <span class="at-kv__v at-num">{{ $accountBalance['btc'] }}</span>
                    </div>
                    <div class="at-kv">
                        <span class="at-kv__k">موجودی ریال (IRR)</span>
                        <span class="at-kv__v at-num">{{ $accountBalance['irt'] }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Quick tests --}}
        <div class="panel-section">
            <div class="panel-section__head">
                <span class="panel-section__title">تست‌های سریع</span>
            </div>
            <div class="panel-section__body">
                <div class="at-row" style="flex-wrap: wrap;">
                    <button type="button" wire:click="testPriceEndpoint" class="at-btn at-btn--accent">
                        تست قیمت
                    </button>
                    <button type="button" wire:click="testBalanceEndpoint" class="at-btn">
                        تست موجودی
                    </button>
                    <button type="button" wire:click="testOrderbookEndpoint" class="at-btn">
                        تست اردربوک
                    </button>
                    <button type="button" wire:click="clearConnectionCache" class="at-btn">
                        پاک کردن کش
                    </button>
                </div>
            </div>
        </div>

        {{-- Loading overlay --}}
        @if($isLoading)
            <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; z-index: 50;">
                <div class="panel-section" style="padding: var(--at-gap-lg);">
                    <div class="at-row">
                        <span class="at-dot pos"></span>
                        <span style="color: var(--at-text); font-size: var(--at-fs-body);">در حال تست اتصال…</span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        // Auto refresh cached data every 30 seconds
        setInterval(function () {
            if (!@this.isLoading) {
                @this.loadCachedData();
            }
        }, 30000);

        // Keyboard shortcut: Ctrl/Cmd + T → run connection test
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 't') {
                e.preventDefault();
                @this.performConnectionTest();
            }
        });
    </script>
</x-filament-panels::page>
