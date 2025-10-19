{{-- محاسبه‌گر پیشرفته استراتژی گرید --}}
<div class="grid-calculator-advanced" x-data="gridCalculatorApp()" wire:poll.30s>
    {{-- Header Section --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-6">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
                {{-- Title & Status --}}
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-gradient-to-br from-indigo-500 to-purple-600 text-white rounded-xl shadow-lg">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">محاسبه‌گر استراتژی گرید پیشرفته</h1>
                        <div class="flex items-center gap-4 mt-2">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 {{ $isCalculated ? 'bg-green-500' : 'bg-gray-400' }} rounded-full animate-pulse"></div>
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $isCalculated ? 'محاسبه شده' : 'آماده محاسبه' }}
                                </span>
                            </div>
                            @if($lastCalculationTime)
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    آخرین محاسبه: {{ $lastCalculationTime }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Quick Actions --}}
                <div class="flex flex-wrap items-center gap-2">
                    <button 
                        wire:click="refreshRealTimeData"
                        class="px-3 py-2 text-sm bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-lg transition-colors"
                        title="بروزرسانی داده‌های بازار"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </button>

                    <button 
                        wire:click="toggleAdvancedOptions"
                        class="px-3 py-2 text-sm {{ $showAdvancedOptions ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700' }} hover:bg-purple-200 rounded-lg transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4"></path>
                        </svg>
                        پیشرفته
                    </button>

                    @if($isCalculated)
                        <button 
                            wire:click="exportResults"
                            class="px-3 py-2 text-sm bg-green-100 hover:bg-green-200 text-green-700 rounded-lg transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            صادرات
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Real-time Market Info --}}
        @if($realTimePrice && $marketTrend)
            <div class="p-4 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center">
                        <div class="text-sm text-gray-600 dark:text-gray-400">قیمت فعلی BTC</div>
                        <div class="text-xl font-bold text-blue-900 dark:text-blue-100">
                            {{ number_format($realTimePrice, 0) }} ریال
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-sm text-gray-600 dark:text-gray-400">ترند بازار</div>
                        <div class="text-lg font-semibold">
                            {{ $this->getMarketTrendDisplay() }}
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-sm text-gray-600 dark:text-gray-400">نوسان‌پذیری</div>
                        <div class="text-lg font-semibold">
                            {{ $this->getVolatilityDisplay() }}
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-sm text-gray-600 dark:text-gray-400">توصیه</div>
                        <div class="text-sm text-blue-600 dark:text-blue-400 font-medium">
                            {{ Str::limit($marketTrend['recommendation'] ?? 'بررسی...', 30) }}
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Main Content Grid --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        {{-- Left Column: Configuration Form --}}
        <div class="xl:col-span-1 space-y-6">
            {{-- Strategy Selection --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    انتخاب استراتژی
                </h3>
                
                <div class="grid grid-cols-2 gap-3">
                    @php
                        $strategies = [
                            'conservative' => ['name' => 'محافظه‌کار', 'icon' => '🛡️', 'color' => 'green'],
                            'balanced' => ['name' => 'متعادل', 'icon' => '⚖️', 'color' => 'blue'],
                            'aggressive' => ['name' => 'تهاجمی', 'icon' => '🚀', 'color' => 'red'],
                            'adaptive' => ['name' => 'تطبیقی', 'icon' => '🧠', 'color' => 'purple']
                        ];
                    @endphp
                    
                    @foreach($strategies as $key => $strategy)
                        <button 
                            wire:click="applyStrategyPreset('{{ $key }}')"
                            class="p-3 rounded-lg border-2 transition-all duration-200 text-center {{ $strategy_type === $key ? 'border-' . $strategy['color'] . '-500 bg-' . $strategy['color'] . '-50 dark:bg-' . $strategy['color'] . '-900/20' : 'border-gray-200 dark:border-gray-600 hover:border-gray-300' }}"
                        >
                            <div class="text-2xl mb-1">{{ $strategy['icon'] }}</div>
                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $strategy['name'] }}</div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Basic Parameters --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                    </svg>
                    تنظیمات پایه
                </h3>

                <div class="space-y-4">
                    {{-- Total Capital --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            سرمایه کل (ریال)
                        </label>
                        <input 
                            type="number" 
                            wire:model.live.debounce.500ms="total_capital"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:text-white"
                            min="10000000"
                            step="1000000"
                        >
                        <div class="text-xs text-gray-500 mt-1">حداقل: 10 میلیون ریال</div>
                    </div>

                    {{-- Active Capital Percent --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            درصد سرمایه فعال: {{ $active_capital_percent }}%
                        </label>
                        <input 
                            type="range" 
                            wire:model.live="active_capital_percent"
                            min="10" 
                            max="80" 
                            step="5"
                            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700"
                        >
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>10%</span>
                            <span class="font-medium text-indigo-600">{{ number_format($this->getActiveCapital(), 0) }} ریال</span>
                            <span>80%</span>
                        </div>
                    </div>

                    {{-- Grid Spacing --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            فاصله گرید: {{ $grid_spacing }}%
                        </label>
                        <input 
                            type="range" 
                            wire:model.live="grid_spacing"
                            min="0.5" 
                            max="5" 
                            step="0.1"
                            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700"
                        >
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>0.5%</span>
                            <span class="font-medium text-purple-600">{{ $this->getSpacingDescription() }}</span>
                            <span>5%</span>
                        </div>
                    </div>

                    {{-- Grid Levels --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            تعداد سطوح: {{ $grid_levels }}
                        </label>
                        <input 
                            type="range" 
                            wire:model.live="grid_levels"
                            min="4" 
                            max="20" 
                            step="2"
                            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700"
                        >
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>4 سطح</span>
                            <span class="font-medium text-blue-600">{{ $this->getOrderSize() > 0 ? number_format($this->getOrderSize(), 0) . ' ریال' : 'محاسبه نشده' }}</span>
                            <span>20 سطح</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Advanced Options --}}
            @if($showAdvancedOptions)
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4"></path>
                        </svg>
                        تنظیمات پیشرفته
                    </h3>

                    <div class="space-y-4">
                        {{-- Grid Distribution --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                نوع توزیع سطوح
                            </label>
                            <select 
                                wire:model.live="grid_distribution"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="logarithmic">لگاریتمی (پیشنهادی)</option>
                                <option value="linear">خطی</option>
                                <option value="fibonacci">فیبوناچی</option>
                                <option value="adaptive">تطبیقی</option>
                            </select>
                        </div>

                        {{-- Risk Management --}}
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    استاپ لاس (%)
                                </label>
                                <input 
                                    type="number" 
                                    wire:model.live="stop_loss_percent"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 dark:bg-gray-700 dark:text-white"
                                    min="1" 
                                    max="50" 
                                    step="0.5"
                                >
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    تیک پروفیت (%)
                                </label>
                                <input 
                                    type="number" 
                                    wire:model.live="take_profit_percent"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white"
                                    min="0" 
                                    max="100" 
                                    step="1"
                                >
                            </div>
                        </div>

                        {{-- Dynamic Spacing Toggle --}}
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                فاصله‌گذاری دینامیک
                            </label>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    wire:model.live="use_dynamic_spacing"
                                    class="sr-only peer"
                                >
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                            </label>
                        </div>

                        {{-- Simulation Settings --}}
                        <div class="border-t border-gray-200 dark:border-gray-600 pt-4">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">تنظیمات شبیه‌سازی</h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">
                                        روزهای شبیه‌سازی
                                    </label>
                                    <input 
                                        type="number" 
                                        wire:model.live="simulation_days"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded focus:ring-1 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
                                        min="7" 
                                        max="365" 
                                        value="30"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">
                                        شرایط بازار
                                    </label>
                                    <select 
                                        wire:model.live="market_condition"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded focus:ring-1 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option value="real">داده‌های واقعی</option>
                                        <option value="bull">صعودی</option>
                                        <option value="bear">نزولی</option>
                                        <option value="sideways">خنثی</option>
                                        <option value="volatile">پرنوسان</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Action Buttons --}}
            <div class="space-y-3">
                <button 
                    wire:click="calculateGrid"
                    class="w-full px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold rounded-xl hover:from-indigo-700 hover:to-purple-700 focus:ring-4 focus:ring-indigo-300 transition-all duration-200 transform hover:scale-105 shadow-lg"
                    wire:loading.attr="disabled"
                >
                    <div wire:loading.remove wire:target="calculateGrid" class="flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        محاسبه گرید
                    </div>
                    <div wire:loading wire:target="calculateGrid" class="flex items-center justify-center gap-2">
                        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        در حال محاسبه...
                    </div>
                </button>

                @if($isCalculated)
                    <div class="grid grid-cols-2 gap-3">
                        <button 
                            wire:click="runHistoricalSimulation"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            شبیه‌سازی
                        </button>
                        <button 
                            wire:click="optimizeParameters"
                            class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors flex items-center justify-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            بهینه‌سازی
                        </button>
                    </div>
                @endif

                <button 
                    wire:click="resetForm"
                    class="w-full px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors flex items-center justify-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    بازنشانی
                </button>
            </div>
        </div>

        {{-- Right Column: Results & Visualizations --}}
        <div class="xl:col-span-2 space-y-6">
            @if($isCalculated)
                {{-- Stats Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    {{-- Active Capital Card --}}
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-xl p-4 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-blue-100 text-sm">سرمایه فعال</p>
                                <p class="text-2xl font-bold">{{ number_format($this->getActiveCapital(), 0) }}</p>
                                <p class="text-blue-100 text-xs">ریال</p>
                            </div>
                            <div class="p-3 bg-white/20 rounded-lg">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    {{-- Grid Levels Card --}}
                    <div class="bg-gradient-to-br from-green-500 to-green-600 text-white rounded-xl p-4 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-green-100 text-sm">تعداد سطوح</p>
                                <p class="text-2xl font-bold">{{ $grid_levels }}</p>
                                <p class="text-green-100 text-xs">سطح گرید</p>
                            </div>
                            <div class="p-3 bg-white/20 rounded-lg">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    {{-- Expected Profit Card --}}
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl p-4 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-purple-100 text-sm">سود روزانه</p>
                                <p class="text-2xl font-bold">{{ $this->getEstimatedDailyProfit() > 0 ? number_format($this->getEstimatedDailyProfit(), 0) : '-' }}</p>
                                <p class="text-purple-100 text-xs">ریال</p>
                            </div>
                            <div class="p-3 bg-white/20 rounded-lg">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    {{-- Risk Level Card --}}
                    <div class="bg-gradient-to-br from-amber-500 to-amber-600 text-white rounded-xl p-4 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-amber-100 text-sm">سطح ریسک</p>
                                <p class="text-2xl font-bold">{{ $this->getRiskLevel() }}</p>
                                <p class="text-amber-100 text-xs">{{ $this->getEfficiencyLevel() }}</p>
                            </div>
                            <div class="p-3 bg-white/20 rounded-lg">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Price Range Overview --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        بازه قیمتی گرید
                    </h3>
                    
                    @php
                        $priceRange = $this->getPriceRange();
                    @endphp
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="text-center p-4 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                            <div class="text-sm text-red-600 dark:text-red-400 mb-1">حداقل قیمت</div>
                            <div class="text-xl font-bold text-red-700 dark:text-red-300">
                                {{ number_format($priceRange['min'], 0) }}
                            </div>
                            <div class="text-xs text-red-500 dark:text-red-400">ریال</div>
                        </div>
                        
                        <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                            <div class="text-sm text-blue-600 dark:text-blue-400 mb-1">قیمت مرکز</div>
                            <div class="text-xl font-bold text-blue-700 dark:text-blue-300">
                                {{ number_format($current_price, 0) }}
                            </div>
                            <div class="text-xs text-blue-500 dark:text-blue-400">ریال</div>
                        </div>
                        
                        <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                            <div class="text-sm text-green-600 dark:text-green-400 mb-1">حداکثر قیمت</div>
                            <div class="text-xl font-bold text-green-700 dark:text-green-300">
                                {{ number_format($priceRange['max'], 0) }}
                            </div>
                            <div class="text-xs text-green-500 dark:text-green-400">ریال</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">محدوده کلی:</span>
                            <span class="font-medium text-gray-900 dark:text-white">
                                {{ number_format($priceRange['spread'], 0) }} ریال ({{ number_format($priceRange['spread_percent'] ?? 0, 1) }}%)
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Interactive Chart --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        نمودار سطوح گرید
                    </h3>
                    
                    <div class="relative h-64 md:h-80">
                        <canvas id="gridChart" class="w-full h-full"></canvas>
                    </div>
                    
                    <div class="mt-4 flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-red-500 rounded"></div>
                            <span class="text-gray-600 dark:text-gray-400">سطوح خرید</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-green-500 rounded"></div>
                            <span class="text-gray-600 dark:text-gray-400">سطوح فروش</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-blue-500 rounded"></div>
                            <span class="text-gray-600 dark:text-gray-400">قیمت فعلی</span>
                        </div>
                    </div>
                </div>

                {{-- Grid Levels Table --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                            </svg>
                            جدول سطوح گرید
                        </h3>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">سطح</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">نوع</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">قیمت (ریال)</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">اندازه سفارش</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">فاصله از مرکز</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">احتمال اجرا</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @if($gridLevels)
                                    @foreach($gridLevels as $level)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                                {{ $level['level'] ?? $loop->iteration }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                @if($level['type'] === 'buy')
                                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                        🔻 خرید
                                                    </span>
                                                @else
                                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                        🔺 فروش
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white font-mono">
                                                {{ number_format($level['price'], 0) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                                {{ number_format($this->getOrderSize(), 0) }} ریال
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                                {{ number_format(abs($level['distance_percent'] ?? 0), 2) }}%
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                @php
                                                    $probability = $level['execution_probability'] ?? 0.5;
                                                    $probColor = $probability >= 0.8 ? 'green' : ($probability >= 0.6 ? 'yellow' : 'red');
                                                @endphp
                                                <div class="flex items-center">
                                                    <div class="w-full bg-gray-200 rounded-full h-2 mr-2">
                                                        <div class="bg-{{ $probColor }}-600 h-2 rounded-full" style="width: {{ $probability * 100 }}%"></div>
                                                    </div>
                                                    <span class="text-xs text-gray-600 dark:text-gray-400">{{ round($probability * 100) }}%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Optimization Suggestions --}}
                @if($optimizationSuggestions && count($optimizationSuggestions) > 0)
                    <div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 rounded-xl border border-amber-200 dark:border-amber-800 p-6">
                        <h3 class="text-lg font-semibold text-amber-800 dark:text-amber-200 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                            پیشنهادات بهینه‌سازی
                        </h3>
                        
                        <div class="space-y-3">
                            @foreach($optimizationSuggestions as $suggestion)
                                <div class="flex items-start gap-3 p-4 bg-white dark:bg-gray-800 rounded-lg border border-amber-200 dark:border-amber-700">
                                    <div class="flex-shrink-0 mt-0.5">
                                        @if($suggestion['type'] === 'warning')
                                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                        @elseif($suggestion['type'] === 'danger')
                                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        @else
                                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        @endif
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900 dark:text-white">{{ $suggestion['title'] }}</h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ $suggestion['message'] }}</p>
                                        @if(isset($suggestion['suggestion']))
                                            <p class="text-sm text-amber-700 dark:text-amber-300 mt-2 font-medium">💡 {{ $suggestion['suggestion'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Performance Metrics --}}
                @if($performanceMetrics)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            متریک‌های عملکرد
                        </h3>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                    {{ $performanceMetrics['sharpe_ratio'] ?? 0 }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">نسبت شارپ</div>
                            </div>
                            
                            <div class="text-center p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                                    {{ $performanceMetrics['win_rate'] ?? 0 }}%
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">نرخ برد</div>
                            </div>
                            
                            <div class="text-center p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                                    {{ $performanceMetrics['profit_factor'] ?? 0 }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">ضریب سود</div>
                            </div>
                            
                            <div class="text-center p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                    {{ $performanceMetrics['max_drawdown'] ?? 0 }}%
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">حداکثر افت</div>
                            </div>
                        </div>
                    </div>
                @endif
            @else
                {{-- Empty State --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <div class="mx-auto w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-12 h-12 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">آماده محاسبه</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-4">پارامترهای مورد نظر را تنظیم کرده و دکمه محاسبه را بزنید</p>
                    <div class="flex justify-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded">Ctrl + Enter</span>
                        <span>برای محاسبه سریع</span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Keyboard Shortcuts Modal --}}
    <div x-show="showShortcuts" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-6 m-4 max-w-md w-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">میانبرهای کیبورد</h3>
                <button @click="showShortcuts = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">محاسبه گرید</span>
                    <kbd class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-xs">Ctrl + Enter</kbd>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">بازنشانی فرم</span>
                    <kbd class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-xs">Ctrl + R</kbd>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">صادرات نتایج</span>
                    <kbd class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-xs">Ctrl + E</kbd>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">نمایش راهنما</span>
                    <kbd class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-xs">?</kbd>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- JavaScript for Chart and Interactions --}}
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function gridCalculatorApp() {
    return {
        showShortcuts: false,
        chartInstance: null,
        
        init() {
            this.initKeyboardShortcuts();
            this.initChart();
            
            // Listen for Livewire updates
            this.$wire.on('chartUpdated', () => {
                this.updateChart();
            });
        },
        
        initKeyboardShortcuts() {
            document.addEventListener('keydown', (e) => {
                // Ctrl + Enter: Calculate Grid
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    this.$wire.calculateGrid();
                }
                
                // Ctrl + R: Reset Form
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    this.$wire.resetForm();
                }
                
                // Ctrl + E: Export Results
                if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                    e.preventDefault();
                    if (this.$wire.isCalculated) {
                        this.$wire.exportResults();
                    }
                }
                
                // ?: Show Help
                if (e.key === '?' && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    this.showShortcuts = true;
                }
                
                // Escape: Close modals
                if (e.key === 'Escape') {
                    this.showShortcuts = false;
                }
            });
        },
        
        initChart() {
            const canvas = document.getElementById('gridChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            
            this.chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'قیمت BTC',
                        data: [],
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: false
                    }, {
                        label: 'سطوح خرید',
                        data: [],
                        borderColor: 'rgb(239, 68, 68)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderWidth: 1,
                        borderDash: [5, 5],
                        pointBackgroundColor: 'rgb(239, 68, 68)',
                        pointBorderColor: 'rgb(239, 68, 68)',
                        pointRadius: 4,
                        fill: false
                    }, {
                        label: 'سطوح فروش',
                        data: [],
                        borderColor: 'rgb(34, 197, 94)',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        borderWidth: 1,
                        borderDash: [5, 5],
                        pointBackgroundColor: 'rgb(34, 197, 94)',
                        pointBorderColor: 'rgb(34, 197, 94)',
                        pointRadius: 4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                font: {
                                    family: 'Vazirmatn',
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(255, 255, 255, 0.2)',
                            borderWidth: 1,
                            titleFont: {
                                family: 'Vazirmatn'
                            },
                            bodyFont: {
                                family: 'Vazirmatn'
                            },
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + 
                                           new Intl.NumberFormat('fa-IR').format(context.parsed.y) + ' ریال';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'سطوح گرید',
                                font: {
                                    family: 'Vazirmatn',
                                    size: 14
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'قیمت (ریال)',
                                font: {
                                    family: 'Vazirmatn',
                                    size: 14
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return new Intl.NumberFormat('fa-IR', {
                                        notation: 'compact',
                                        compactDisplay: 'short'
                                    }).format(value);
                                },
                                font: {
                                    family: 'Vazirmatn'
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: 750,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        },
        
        updateChart() {
            if (!this.chartInstance) return;
            
            // Get grid levels from Livewire component
            const gridLevels = @this.gridLevels;
            const currentPrice = @this.current_price;
            
            if (!gridLevels || gridLevels.length === 0) return;
            
            // Prepare data
            const buyLevels = gridLevels.filter(level => level.type === 'buy');
            const sellLevels = gridLevels.filter(level => level.type === 'sell');
            
            // Create labels and data points
            const allPrices = [...buyLevels, ...sellLevels].map(level => level.price).sort((a, b) => a - b);
            const labels = allPrices.map((price, index) => `سطح ${index + 1}`);
            
            // Current price line data
            const currentPriceData = new Array(allPrices.length).fill(currentPrice);
            
            // Buy levels data
            const buyData = allPrices.map(price => {
                const buyLevel = buyLevels.find(level => level.price === price);
                return buyLevel ? price : null;
            });
            
            // Sell levels data
            const sellData = allPrices.map(price => {
                const sellLevel = sellLevels.find(level => level.price === price);
                return sellLevel ? price : null;
            });
            
            // Update chart data
            this.chartInstance.data.labels = labels;
            this.chartInstance.data.datasets[0].data = currentPriceData;
            this.chartInstance.data.datasets[1].data = buyData;
            this.chartInstance.data.datasets[2].data = sellData;
            
            // Update chart
            this.chartInstance.update('active');
        },
        
        formatNumber(num) {
            return new Intl.NumberFormat('fa-IR').format(num);
        },
        
        formatPercent(num) {
            return new Intl.NumberFormat('fa-IR', {
                style: 'percent',
                minimumFractionDigits: 1,
                maximumFractionDigits: 2
            }).format(num / 100);
        }
    }
}

// Initialize chart when grid levels are updated
document.addEventListener('livewire:updated', function () {
    // Find the chart canvas
    const canvas = document.getElementById('gridChart');
    if (canvas && window.gridCalculatorApp) {
        // Update chart with new data
        setTimeout(() => {
            const app = Alpine.$data(canvas.closest('[x-data]'));
            if (app && app.updateChart) {
                app.updateChart();
            }
        }, 100);
    }
});

// Smooth scrolling for internal links
document.addEventListener('click', function(e) {
    if (e.target.matches('a[href^="#"]')) {
        e.preventDefault();
        const target = document.querySelector(e.target.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }
});

// Auto-resize charts on window resize
window.addEventListener('resize', function() {
    if (window.Chart && window.Chart.instances) {
        Object.values(window.Chart.instances).forEach(chart => {
            chart.resize();
        });
    }
});

// Add loading animation for buttons
document.addEventListener('click', function(e) {
    if (e.target.matches('button[wire\\:click]')) {
        const button = e.target;
        const originalContent = button.innerHTML;
        
        // Add loading state
        button.classList.add('opacity-75', 'cursor-wait');
        
        // Remove loading state after 2 seconds (fallback)
        setTimeout(() => {
            button.classList.remove('opacity-75', 'cursor-wait');
        }, 2000);
    }
});

// Toast notifications enhancement
window.addEventListener('notification', event => {
    const notification = event.detail;
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transform transition-all duration-300 translate-x-full`;
    
    // Set colors based on type
    switch (notification.type) {
        case 'success':
            toast.className += ' bg-green-500 text-white';
            break;
        case 'error':
            toast.className += ' bg-red-500 text-white';
            break;
        case 'warning':
            toast.className += ' bg-yellow-500 text-black';
            break;
        default:
            toast.className += ' bg-blue-500 text-white';
    }
    
    toast.innerHTML = `
        <div class="flex items-center justify-between">
            <div>
                <div class="font-semibold">${notification.title}</div>
                ${notification.message ? `<div class="text-sm opacity-90">${notification.message}</div>` : ''}
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.classList.remove('translate-x-full');
    }, 100);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
});

// Enhanced form validation
document.addEventListener('input', function(e) {
    if (e.target.matches('input[type="number"]')) {
        const input = e.target;
        const value = parseFloat(input.value);
        const min = parseFloat(input.getAttribute('min'));
        const max = parseFloat(input.getAttribute('max'));
        
        // Remove existing validation classes
        input.classList.remove('border-red-500', 'border-green-500');
        
        // Add validation classes
        if (isNaN(value) || (min && value < min) || (max && value > max)) {
            input.classList.add('border-red-500');
        } else {
            input.classList.add('border-green-500');
        }
    }
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Add hover effects for better UX
    const buttons = document.querySelectorAll('button');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-1px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});
</script>
@endpush

@push('styles')
<style>
/* Custom styles for better RTL support */
[dir="rtl"] .grid-calculator-advanced {
    direction: rtl;
}

/* Chart container styles */
#gridChart {
    font-family: 'Vazirmatn', sans-serif !important;
}

/* Custom range slider styles */
input[type="range"] {
    background: transparent;
    cursor: pointer;
}

input[type="range"]::-webkit-slider-track {
    background: #e5e7eb;
    height: 8px;
    border-radius: 4px;
}

input[type="range"]::-webkit-slider-thumb {
    appearance: none;
    background: #6366f1;
    height: 20px;
    width: 20px;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    transition: all 0.2s ease;
}

input[type="range"]::-webkit-slider-thumb:hover {
    background: #4f46e5;
    transform: scale(1.1);
}

/* Dark mode range slider */
.dark input[type="range"]::-webkit-slider-track {
    background: #374151;
}

/* Loading animation */
@keyframes pulse-glow {
    0%, 100% {
        box-shadow: 0 0 5px rgba(99, 102, 241, 0.5);
    }
    50% {
        box-shadow: 0 0 20px rgba(99, 102, 241, 0.8);
    }
}

.animate-pulse-glow {
    animation: pulse-glow 2s infinite;
}

/* Strategy button hover effects */
.strategy-button {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.strategy-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

/* Table hover effects */
.grid-table-row {
    transition: all 0.2s ease;
}

.grid-table-row:hover {
    background-color: rgba(99, 102, 241, 0.05);
    transform: translateX(-2px);
}

/* Notification animations */
@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOutRight {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

.notification-enter {
    animation: slideInRight 0.3s ease-out;
}

.notification-exit {
    animation: slideOutRight 0.3s ease-in;
}

/* Progress bar animations */
.progress-bar {
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% {
        background-position: -200% 0;
    }
    100% {
        background-position: 200% 0;
    }
}

/* Card hover effects */
.stat-card {
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
}

/* Modal backdrop blur */
.modal-backdrop {
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

/* Custom scrollbar */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Dark mode scrollbar */
.dark .custom-scrollbar::-webkit-scrollbar-track {
    background: #1e293b;
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background: #475569;
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* Responsive improvements */
@media (max-width: 640px) {
    .grid-calculator-advanced {
        padding: 1rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    #gridChart {
        height: 200px !important;
    }
}

/* Print styles */
@media print {
    .no-print {
        display: none !important;
    }
    
    .print-only {
        display: block !important;
    }
    
    .bg-gradient-to-r,
    .bg-gradient-to-br {
        background: #f8fafc !important;
        color: #1e293b !important;
    }
}

/* High contrast mode */
@media (prefers-contrast: high) {
    .stat-card {
        border: 2px solid #000;
    }
    
    input[type="range"]::-webkit-slider-thumb {
        border: 2px solid #000;
    }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
@endpush