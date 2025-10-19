<div class="grid-levels-table-container" wire:poll.30s>
    {{-- Header Section with Controls --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        {{-- Title & Summary --}}
        <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
                <div class="p-2 bg-gradient-to-br from-indigo-500 to-purple-600 text-white rounded-lg shadow-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">سطوح گرید محاسبه شده</h3>
                    <div class="flex items-center gap-4 text-sm text-gray-600">
                        <span>📊 {{ $summary['total_levels'] }} سطح</span>
                        <span>⚖️ نسبت {{ $summary['buy_sell_ratio'] }}</span>
                        <span>📏 پوشش {{ $summary['price_coverage'] }}</span>
                        <span>📐 فاصله میانگین {{ $summary['avg_spacing'] }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Control Buttons --}}
        <div class="flex items-center gap-2">
            {{-- Filter Buttons --}}
            <div class="flex items-center bg-gray-100 rounded-lg p-1">
                <button 
                    wire:click="setFilter('all')"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-all duration-200 {{ $filterType === 'all' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
                >
                    همه ({{ $statistics['total_levels'] }})
                </button>
                <button 
                    wire:click="setFilter('buy')"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-all duration-200 {{ $filterType === 'buy' ? 'bg-green-100 text-green-800 shadow-sm' : 'text-gray-600 hover:text-green-700' }}"
                >
                    🟢 خرید ({{ $statistics['buy_levels'] }})
                </button>
                <button 
                    wire:click="setFilter('sell')"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-all duration-200 {{ $filterType === 'sell' ? 'bg-red-100 text-red-800 shadow-sm' : 'text-gray-600 hover:text-red-700' }}"
                >
                    🔴 فروش ({{ $statistics['sell_levels'] }})
                </button>
            </div>

            {{-- Details Toggle --}}
            <button 
                wire:click="toggleDetails"
                class="p-2 bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-900 rounded-lg transition-all duration-200"
                title="{{ $showDetails ? 'مخفی کردن جزئیات' : 'نمایش جزئیات' }}"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $showDetails ? 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21' : 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z' }}"></path>
                </svg>
            </button>
        </div>
    </div>

    {{-- Main Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        {{-- Table Header --}}
        <div class="bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
            <div class="grid {{ $showDetails ? 'grid-cols-8' : 'grid-cols-5' }} gap-4 px-6 py-4 text-sm font-semibold text-gray-700">
                {{-- Level Number --}}
                <button 
                    wire:click="sortBy('level')"
                    class="flex items-center gap-2 hover:text-gray-900 transition-colors"
                >
                    <span>سطح</span>
                    @if($sortBy === 'level')
                        <svg class="w-4 h-4 {{ $sortDirection === 'asc' ? 'rotate-0' : 'rotate-180' }} transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                        </svg>
                    @endif
                </button>

                {{-- Type --}}
                <button 
                    wire:click="sortBy('type')"
                    class="flex items-center gap-2 hover:text-gray-900 transition-colors"
                >
                    <span>نوع</span>
                    @if($sortBy === 'type')
                        <svg class="w-4 h-4 {{ $sortDirection === 'asc' ? 'rotate-0' : 'rotate-180' }} transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                        </svg>
                    @endif
                </button>

                {{-- Price --}}
                <button 
                    wire:click="sortBy('price')"
                    class="flex items-center gap-2 hover:text-gray-900 transition-colors"
                >
                    <span>قیمت</span>
                    @if($sortBy === 'price')
                        <svg class="w-4 h-4 {{ $sortDirection === 'asc' ? 'rotate-0' : 'rotate-180' }} transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                        </svg>
                    @endif
                </button>

                {{-- Amount --}}
                <span>مقدار</span>

                {{-- Distance from Center --}}
                <span>فاصله از مرکز</span>

                @if($showDetails)
                    {{-- Execution Probability --}}
                    <span>احتمال اجرا</span>

                    {{-- Value --}}
                    <span>ارزش</span>

                    {{-- Priority --}}
                    <span>اولویت</span>
                @endif
            </div>
        </div>

        {{-- Table Body --}}
        <div class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
            @forelse($levels as $level)
                @php
                    $distancePercent = $this->getPriceDistancePercent($level['price']);
                    $typeClass = $this->getTypeClass($level['type']);
                    $distanceClass = $this->getPriceDistanceClass($distancePercent);
                @endphp
                
                <div class="grid {{ $showDetails ? 'grid-cols-8' : 'grid-cols-5' }} gap-4 px-6 py-4 hover:bg-gray-50 transition-colors duration-200 group" 
                     wire:key="level-{{ $level['level'] ?? $loop->index }}">
                    
                    {{-- Level Number --}}
                    <div class="flex items-center">
                        <span class="inline-flex items-center justify-center w-8 h-8 bg-gray-100 group-hover:bg-gray-200 text-gray-700 text-sm font-semibold rounded-full transition-colors">
                            {{ $level['level'] ?? $loop->iteration }}
                        </span>
                    </div>

                    {{-- Type --}}
                    <div class="flex items-center">
                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-sm font-medium border {{ $typeClass }} transition-all duration-200">
                            <span>{{ $this->getTypeIcon($level['type']) }}</span>
                            <span>{{ $this->getTypeLabel($level['type']) }}</span>
                        </span>
                    </div>

                    {{-- Price --}}
                    <div class="flex flex-col">
                        <span class="font-semibold text-gray-900 group-hover:text-indigo-600 transition-colors">
                            {{ $this->formatPrice($level['price']) }}
                        </span>
                        @if($centerPrice > 0)
                            <span class="text-xs {{ $distanceClass }} font-medium">
                                {{ $distancePercent > 0 ? '+' : '' }}{{ number_format($distancePercent, 1) }}%
                            </span>
                        @endif
                    </div>

                    {{-- Amount --}}
                    <div class="flex items-center">
                        <span class="text-gray-700 font-medium">
                            {{ $this->formatAmount($level['amount'] ?? 0) }}
                        </span>
                    </div>

                    {{-- Distance from Center --}}
                    <div class="flex items-center">
                        <div class="flex items-center gap-2">
                            <div class="w-12 bg-gray-200 rounded-full h-2">
                                <div class="h-2 rounded-full {{ $level['type'] === 'buy' ? 'bg-green-500' : 'bg-red-500' }}" 
                                     style="width: {{ min(100, abs($distancePercent) * 10) }}%"></div>
                            </div>
                            <span class="text-sm {{ $distanceClass }} font-medium">
                                {{ abs($distancePercent) }}%
                            </span>
                        </div>
                    </div>

                    @if($showDetails)
                        {{-- Execution Probability --}}
                        <div class="flex items-center">
                            @php
                                $probability = ($level['execution_probability'] ?? 0.5) * 100;
                                $probabilityColor = $probability >= 80 ? 'text-green-600' : ($probability >= 60 ? 'text-yellow-600' : 'text-red-600');
                            @endphp
                            <div class="flex items-center gap-2">
                                <div class="w-12 bg-gray-200 rounded-full h-2">
                                    <div class="h-2 rounded-full bg-blue-500" style="width: {{ $probability }}%"></div>
                                </div>
                                <span class="text-sm {{ $probabilityColor }} font-medium">
                                    {{ round($probability) }}%
                                </span>
                            </div>
                        </div>

                        {{-- Value --}}
                        <div class="flex items-center">
                            <span class="text-gray-700 font-medium">
                                {{ number_format(($level['amount'] ?? 0) * $level['price'], 0) }} ریال
                            </span>
                        </div>

                        {{-- Priority --}}
                        <div class="flex items-center">
                            @php
                                $priority = $level['priority'] ?? 5;
                                $priorityColor = $priority >= 8 ? 'text-red-600' : ($priority >= 6 ? 'text-yellow-600' : 'text-green-600');
                            @endphp
                            <span class="inline-flex items-center gap-1 {{ $priorityColor }} text-sm font-medium">
                                @for($i = 1; $i <= 5; $i++)
                                    <svg class="w-3 h-3 {{ $i <= ($priority / 2) ? 'text-current' : 'text-gray-300' }}" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                @endfor
                            </span>
                        </div>
                    @endif
                </div>
            @empty
                {{-- Empty State --}}
                <div class="px-6 py-12 text-center">
                    <div class="mx-auto w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ سطحی یافت نشد</h3>
                    <p class="text-gray-500">
                        @if($filterType !== 'all')
                            فیلتر "{{ $filterType === 'buy' ? 'خرید' : 'فروش' }}" حذف کنید یا تنظیمات گرید را تغییر دهید.
                        @else
                            ابتدا گرید را محاسبه کنید تا سطوح نمایش داده شوند.
                        @endif
                    </p>
                    @if($filterType !== 'all')
                        <button 
                            wire:click="setFilter('all')"
                            class="mt-4 inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors"
                        >
                            نمایش همه سطوح
                        </button>
                    @endif
                </div>
            @endforelse
        </div>

        {{-- Footer Statistics --}}
        @if($levels->isNotEmpty())
            <div class="bg-gray-50 border-t border-gray-200 px-6 py-4">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div class="text-center">
                        <div class="text-gray-500">کل سطوح</div>
                        <div class="font-semibold text-gray-900">{{ $statistics['total_levels'] }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-gray-500">بازه قیمتی</div>
                        <div class="font-semibold text-gray-900">{{ number_format($statistics['price_range']['max'] - $statistics['price_range']['min'], 0) }} ریال</div>
                    </div>
                    <div class="text-center">
                        <div class="text-gray-500">پوشش کل</div>
                        <div class="font-semibold text-gray-900">{{ $statistics['total_coverage'] }}%</div>
                    </div>
                    <div class="text-center">
                        <div class="text-gray-500">فاصله میانگین</div>
                        <div class="font-semibold text-gray-900">{{ $statistics['avg_spacing'] }}%</div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Loading State --}}
    <div wire:loading.delay class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center rounded-xl">
        <div class="flex items-center gap-3 bg-white px-6 py-3 rounded-lg shadow-lg border">
            <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-indigo-600"></div>
            <span class="text-gray-700 font-medium">در حال بروزرسانی...</span>
        </div>
    </div>
</div>

{{-- Styles --}}
<style>
.grid-levels-table-container {
    position: relative;
}

.grid-levels-table-container .group:hover {
    transform: translateX(-2px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

[dir="rtl"] .grid-levels-table-container .group:hover {
    transform: translateX(2px);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .grid-levels-table-container .grid {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .grid-levels-table-container .grid > div {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f3f4f6;
    }
    
    .grid-levels-table-container .grid > div:before {
        content: attr(data-label);
        font-weight: 600;
        color: #6b7280;
        flex-shrink: 0;
        width: 100px;
    }
}

/* Animation for new rows */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.grid-levels-table-container .group {
    animation: fadeInUp 0.3s ease-out;
}

/* Smooth scrollbar */
.grid-levels-table-container .overflow-y-auto::-webkit-scrollbar {
    width: 6px;
}

.grid-levels-table-container .overflow-y-auto::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 3px;
}

.grid-levels-table-container .overflow-y-auto::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 3px;
}

.grid-levels-table-container .overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}
</style>