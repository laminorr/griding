<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Connection Status Card --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <x-heroicon-o-wifi class="w-6 h-6" />
                    وضعیت اتصال API نوبیتکس
                </h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {{-- Connection Status --}}
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4 {{ $this->connectionStatus === 'success' ? 'bg-green-100 text-green-600' : ($this->connectionStatus === 'failed' ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600') }}">
                            <x-dynamic-component :component="$this->getConnectionStatusIcon()" class="w-8 h-8" />
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">وضعیت</h3>
                        <p class="text-2xl font-bold {{ $this->connectionStatus === 'success' ? 'text-green-600' : ($this->connectionStatus === 'failed' ? 'text-red-600' : 'text-gray-600') }}">
                            {{ $this->getConnectionStatusText() }}
                        </p>
                    </div>
                    
                    {{-- Response Time --}}
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4 bg-blue-100 text-blue-600">
                            <x-heroicon-o-clock class="w-8 h-8" />
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">زمان پاسخ</h3>
                        <p class="text-2xl font-bold text-blue-600">
                            {{ $responseTime ? $responseTime . 'ms' : '--' }}
                        </p>
                    </div>
                    
                    {{-- Last Checked --}}
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4 bg-purple-100 text-purple-600">
                            <x-heroicon-o-calendar class="w-8 h-8" />
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">آخرین بررسی</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $lastChecked ? \Carbon\Carbon::parse($lastChecked)->diffForHumans() : 'هنوز بررسی نشده' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- API Information Card --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <x-heroicon-o-server class="w-6 h-6" />
                    اطلاعات API
                </h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- API Endpoint --}}
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">آدرس API</h3>
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 font-mono text-sm">
                            {{ $apiEndpoint }}
                        </div>
                    </div>
                    
                    {{-- BTC Price --}}
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">قیمت بیت‌کوین</h3>
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-lg font-bold {{ $btcPrice ? 'text-green-600' : 'text-gray-500' }}">
                            {{ $btcPrice ?: 'نامشخص' }}
                        </div>
                    </div>
                </div>
                
                {{-- Account Balance --}}
                @if($accountBalance)
                <div class="mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">موجودی حساب</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4 border border-orange-200 dark:border-orange-800">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-circle-stack class="w-5 h-5 text-orange-600" />
                                <span class="font-semibold text-gray-900 dark:text-white">بیت‌کوین (BTC)</span>
                            </div>
                            <p class="text-xl font-bold text-orange-600 mt-1">{{ $accountBalance['btc'] }}</p>
                        </div>
                        
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-banknotes class="w-5 h-5 text-green-600" />
                                <span class="font-semibold text-gray-900 dark:text-white">ریال (IRR)</span>
                            </div>
                            <p class="text-xl font-bold text-green-600 mt-1">{{ $accountBalance['irt'] }}</p>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Quick Tests Card --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-purple-500 to-pink-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <x-heroicon-o-beaker class="w-6 h-6" />
                    تست‌های سریع
                </h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Quick Test Buttons --}}
                    <button 
                        wire:click="testPriceEndpoint" 
                        class="flex items-center justify-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 font-semibold py-3 px-4 rounded-lg border border-blue-200 transition-colors duration-200"
                    >
                        <x-heroicon-o-currency-dollar class="w-5 h-5" />
                        تست قیمت
                    </button>
                    
                    <button 
                        wire:click="testBalanceEndpoint" 
                        class="flex items-center justify-center gap-2 bg-yellow-50 hover:bg-yellow-100 text-yellow-700 font-semibold py-3 px-4 rounded-lg border border-yellow-200 transition-colors duration-200"
                    >
                        <x-heroicon-o-wallet class="w-5 h-5" />
                        تست موجودی
                    </button>
                    
                    <button 
                        wire:click="clearConnectionCache" 
                        class="flex items-center justify-center gap-2 bg-red-50 hover:bg-red-100 text-red-700 font-semibold py-3 px-4 rounded-lg border border-red-200 transition-colors duration-200"
                    >
                        <x-heroicon-o-trash class="w-5 h-5" />
                        پاک کردن کش
                    </button>
                </div>
            </div>
        </div>

        {{-- Loading Overlay --}}
        @if($isLoading)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 shadow-xl">
                <div class="flex items-center gap-3">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-emerald-600"></div>
                    <span class="text-lg font-semibold text-gray-900 dark:text-white">در حال تست اتصال...</span>
                </div>
            </div>
        </div>
        @endif
    </div>

    <style>
        /* Additional animations */
        @keyframes pulse-green {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
        }
        
        @keyframes pulse-red {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        }
        
        .connection-success {
            animation: pulse-green 2s infinite;
        }
        
        .connection-failed {
            animation: pulse-red 2s infinite;
        }
    </style>

    <script>
        // Auto refresh every 30 seconds
        setInterval(function() {
            if (!@this.isLoading) {
                @this.loadCachedData();
            }
        }, 30000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 't') {
                e.preventDefault();
                @this.performConnectionTest();
            }
        });
    </script>
</x-filament-panels::page>