{{-- کارت‌های آماری گرید --}}
<div class="grid-stats-cards" wire:poll.30s>
    {{-- Header Section --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <div class="p-2 bg-gradient-to-br from-emerald-500 to-teal-600 text-white rounded-lg shadow-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">آمار گرید محاسبه شده</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">خلاصه تنظیمات و نتایج محاسبه</p>
            </div>
        </div>
        
        {{-- Quick Actions --}}
        <div class="flex items-center gap-2">
            <button 
                wire:click="refreshStats"
                class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                title="بروزرسانی آمار"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
            </button>
            
            <button 
                wire:click="toggleCompactView"
                class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                title="{{ $compactView ? 'نمایش کامل' : 'نمایش فشرده' }}"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $compactView ? 'M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4' : 'M15 12a3 3 0 11-6 0 3 3 0 016 0z' }}"></path>
                </svg>
            </button>
        </div>
    </div>

    {{-- Main Stats Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        {{-- سرمایه فعال --}}
        <div class="stats-card bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 hover:shadow-lg transition-all duration-300 group">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="p-1.5 bg-blue-100 dark:bg-blue-900 rounded-lg">
                            <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <span class="text-sm font-medium text-blue-700 dark:text-blue-300">سرمایه فعال</span>
                    </div>
                    
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-blue-900 dark:text-blue-100 group-hover:scale-105 transition-transform">
                            {{ $this->formatCurrency($activeCapital) }}
                        </div>
                        @if(!$compactView)
                            <div class="flex items-center gap-2 text-xs">
                                <span class="text-blue-600 dark:text-blue-400">
                                    {{ $activeCapitalPercent }}% از {{ $this->formatCurrency($totalCapital) }}
                                </span>
                                <div class="flex-1 bg-blue-200 dark:bg-blue-800 rounded-full h-1.5">
                                    <div class="bg-blue-500 h-1.5 rounded-full transition-all duration-500" 
                                         style="width: {{ min(100, $activeCapitalPercent) }}%"></div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                
                @if(!$compactView)
                    <div class="text-blue-400 dark:text-blue-500">
                        <svg class="w-8 h-8 opacity-50 group-hover:opacity-70 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                    </div>
                @endif
            </div>
        </div>

        {{-- تعداد سطوح --}}
        <div class="stats-card bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border border-green-200 dark:border-green-800 rounded-xl p-4 hover:shadow-lg transition-all duration-300 group">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="p-1.5 bg-green-100 dark:bg-green-900 rounded-lg">
                            <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                            </svg>
                        </div>
                        <span class="text-sm font-medium text-green-700 dark:text-green-300">سطوح گرید</span>
                    </div>
                    
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-green-900 dark:text-green-100 group-hover:scale-105 transition-transform">
                            {{ $totalLevels }}
                        </div>
                        @if(!$compactView)
                            <div class="flex items-center gap-3 text-xs">
                                <span class="flex items-center gap-1 text-green-600 dark:text-green-400">
                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                    {{ $buyLevels }} خرید
                                </span>
                                <span class="flex items-center gap-1 text-red-600 dark:text-red-400">
                                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                    {{ $sellLevels }} فروش
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
                
                @if(!$compactView)
                    <div class="text-green-400 dark:text-green-500">
                        <svg class="w-8 h-8 opacity-50 group-hover:opacity-70 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                @endif
            </div>
        </div>

        {{-- بازه قیمتی --}}
        <div class="stats-card bg-gradient-to-br from-purple-50 to-violet-50 dark:from-purple-900/20 dark:to-violet-900/20 border border-purple-200 dark:border-purple-800 rounded-xl p-4 hover:shadow-lg transition-all duration-300 group">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="p-1.5 bg-purple-100 dark:bg-purple-900 rounded-lg">
                            <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                        </div>
                        <span class="text-sm font-medium text-purple-700 dark:text-purple-300">بازه قیمتی</span>
                    </div>
                    
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-purple-900 dark:text-purple-100 group-hover:scale-105 transition-transform">
                            {{ $this->formatPercent($priceRangePercent) }}
                        </div>
                        @if(!$compactView)
                            <div class="text-xs text-purple-600 dark:text-purple-400 space-y-0.5">
                                <div class="flex justify-between">
                                    <span>حداقل:</span>
                                    <span class="font-medium">{{ $this->formatPrice($minPrice) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>حداکثر:</span>
                                    <span class="font-medium">{{ $this->formatPrice($maxPrice) }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                
                @if(!$compactView)
                    <div class="text-purple-400 dark:text-purple-500">
                        <svg class="w-8 h-8 opacity-50 group-hover:opacity-70 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                        </svg>
                    </div>
                @endif
            </div>
        </div>

        {{-- سطح ریسک --}}
        <div class="stats-card bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 hover:shadow-lg transition-all duration-300 group">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="p-1.5 bg-amber-100 dark:bg-amber-900 rounded-lg">
                            <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <span class="text-sm font-medium text-amber-700 dark:text-amber-300">سطح ریسک</span>
                    </div>
                    
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold {{ $this->getRiskScoreColor() }} group-hover:scale-105 transition-transform">
                                {{ $riskScore }}/100
                            </span>
                            <span class="text-sm font-medium {{ $this->getRiskScoreColor() }}">
                                {{ $this->getRiskLevel() }}
                            </span>
                        </div>
                        @if(!$compactView)
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                    <div class="{{ $this->getRiskScoreBarColor() }} h-2 rounded-full transition-all duration-500" 
                                         style="width: {{ $riskScore }}%"></div>
                                </div>
                                <span class="text-xs {{ $this->getRiskScoreColor() }}">
                                    {{ $this->getRiskEmoji() }}
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
                
                @if(!$compactView)
                    <div class="text-amber-400 dark:text-amber-500">
                        <svg class="w-8 h-8 opacity-50 group-hover:opacity-70 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Secondary Stats Row --}}
    @if(!$compactView && $calculationResults)
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        {{-- سود مورد انتظار --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-emerald-100 dark:bg-emerald-900 rounded-lg">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">سود روزانه متوقع</div>
                    <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">
                        {{ $this->formatCurrency($expectedDailyProfit) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $this->formatPercent($expectedDailyProfitPercent) }} بازدهی
                    </div>
                </div>
            </div>
        </div>

        {{-- اندازه سفارش --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">اندازه هر سفارش</div>
                    <div class="text-lg font-bold text-blue-600 dark:text-blue-400">
                        {{ $this->formatCurrency($orderSizeIRT) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $this->formatAmount($orderSizeBTC) }} BTC
                    </div>
                </div>
            </div>
        </div>

        {{-- فاصله گرید --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-indigo-100 dark:bg-indigo-900 rounded-lg">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">فاصله گرید</div>
                    <div class="text-lg font-bold text-indigo-600 dark:text-indigo-400">
                        {{ $this->formatPercent($gridSpacing) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $this->getSpacingDescription() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Performance Metrics --}}
    @if(!$compactView && $performanceMetrics)
    <div class="bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-700 rounded-xl p-6 border border-gray-200 dark:border-gray-600">
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 bg-gradient-to-br from-indigo-500 to-purple-600 text-white rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
            <div>
                <h4 class="text-lg font-bold text-gray-900 dark:text-white">متریک‌های عملکرد</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400">تحلیل تخصصی استراتژی</p>
            </div>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            {{-- Sharpe Ratio --}}
            <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">Sharpe Ratio</div>
                <div class="text-xl font-bold text-gray-900 dark:text-white">{{ $performanceMetrics['sharpe_ratio'] ?? 'N/A' }}</div>
                <div class="text-xs {{ $this->getSharpeRatioColor() }}">{{ $this->getSharpeRatioLabel() }}</div>
            </div>

            {{-- Win Rate --}}
            <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">نرخ برد</div>
                <div class="text-xl font-bold text-gray-900 dark:text-white">{{ $this->formatPercent($performanceMetrics['win_rate'] ?? 0) }}</div>
                <div class="text-xs {{ $this->getWinRateColor() }}">{{ $this->getWinRateLabel() }}</div>
            </div>

            {{-- Max Drawdown --}}
            <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">حداکثر افت</div>
                <div class="text-xl font-bold text-gray-900 dark:text-white">{{ $this->formatPercent($performanceMetrics['max_drawdown'] ?? 0) }}</div>
                <div class="text-xs {{ $this->getDrawdownColor() }}">{{ $this->getDrawdownLabel() }}</div>
            </div>

            {{-- Recovery Time --}}
            <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">زمان بازیابی</div>
                <div class="text-xl font-bold text-gray-900 dark:text-white">{{ $performanceMetrics['recovery_time'] ?? 'N/A' }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">تخمینی</div>
            </div>
        </div>
    </div>
    @endif

    {{-- Loading State --}}
    <div wire:loading.delay class="absolute inset-0 bg-white/80 dark:bg-gray-800/80 flex items-center justify-center rounded-xl backdrop-blur-sm">
        <div class="flex items-center gap-3 bg-white dark:bg-gray-800 px-6 py-3 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700">
            <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-indigo-600"></div>
            <span class="text-gray-700 dark:text-gray-300 font-medium">در حال بروزرسانی آمار...</span>
        </div>
    </div>

    {{-- Empty State --}}
    @if(!$hasCalculatedData)
    <div class="text-center py-12">
        <div class="mx-auto w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">آمار آماده نیست</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">ابتدا گرید را محاسبه کنید تا آمار نمایش داده شود</p>
        <button 
            wire:click="$dispatch('calculate-grid')"
            class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
        >
            محاسبه گرید
        </button>
    @endif
</div>

{{-- Styles --}}
<style>
.grid-stats-cards {
    position: relative;
}

.stats-card {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}

.stats-card .group:hover svg {
    transform: scale(1.1);
}

/* Animation for counters */
@keyframes countUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.stats-card .text-2xl {
    animation: countUp 0.6s ease-out;
}

/* Progress bars animation */
.stats-card .rounded-full > div {
    transition: width 1s ease-out;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .grid-stats-cards .grid {
        grid-template-columns: 1fr;
    }
    
    .stats-card {
        padding: 1rem;
    }
    
    .stats-card .text-2xl {
        font-size: 1.5rem;
    }
}

/* Dark mode enhancements */
.dark .stats-card {
    backdrop-filter: blur(10px);
}

.dark .stats-card:hover {
    box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
}

/* Gradient borders for cards */
.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border-radius: 0.75rem;
    padding: 1px;
    background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
    mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    mask-composite: exclude;
    pointer-events: none;
}

/* Pulse animation for loading state */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.grid-stats-cards [wire\\:loading] .stats-card {
    animation: pulse 2s ease-in-out infinite;
}

/* Number formatting improvements */
.stats-card .text-2xl {
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.025em;
}

/* RTL improvements */
[dir="rtl"] .stats-card:hover {
    transform: translateY(-2px) translateX(2px);
}

[dir="ltr"] .stats-card:hover {
    transform: translateY(-2px) translateX(-2px);
}

/* Print styles */
@media print {
    .grid-stats-cards {
        break-inside: avoid;
    }
    
    .stats-card {
        box-shadow: none;
        border: 1px solid #e5e7eb;
    }
    
    .stats-card:hover {
        transform: none;
    }
}

/* Accessibility improvements */
.stats-card:focus-within {
    outline: 2px solid #6366f1;
    outline-offset: 2px;
}

/* High contrast mode */
@media (prefers-contrast: high) {
    .stats-card {
        border-width: 2px;
    }
    
    .stats-card .text-2xl {
        font-weight: 900;
    }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .stats-card,
    .stats-card:hover,
    .stats-card .group:hover svg,
    .stats-card .text-2xl {
        animation: none;
        transition: none;
        transform: none;
    }
}

/* Loading skeleton */
.stats-card.loading {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}

.dark .stats-card.loading {
    background: linear-gradient(90deg, #374151 25%, #4b5563 50%, #374151 75%);
    background-size: 200% 100%;
}
</style>