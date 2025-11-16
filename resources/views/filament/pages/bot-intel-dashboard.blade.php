<x-filament-panels::page>
    @php
        $availableBots = $this->getAvailableBots();
        $metrics = $this->getSnapshotMetrics();
        $gridMap = $this->getGridMapData();
        $openOrders = $this->getOpenOrders();
        $completedPairs = $this->getCompletedPairs();
        $capitalConcentration = $this->getCapitalConcentration();
        $gridDrift = $this->getGridDrift();
        $systemHealth = $this->getSystemHealth();
        $activityLogs = $this->getActivityLogs();
    @endphp

    {{-- Custom Styles --}}
    <style>
        .metric-card {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .slider-container {
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(156, 163, 175, 0.3) transparent;
        }
        .slider-container::-webkit-scrollbar {
            height: 6px;
        }
        .slider-container::-webkit-scrollbar-track {
            background: transparent;
        }
        .slider-container::-webkit-scrollbar-thumb {
            background-color: rgba(156, 163, 175, 0.3);
            border-radius: 3px;
        }
        .slider-container::-webkit-scrollbar-thumb:hover {
            background-color: rgba(156, 163, 175, 0.5);
        }
        .section-divider {
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(156, 163, 175, 0.2), transparent);
        }
        .grid-level-badge {
            transition: all 0.15s ease;
        }
        .grid-level-badge:hover {
            transform: scale(1.05);
        }
        .timeline-item {
            position: relative;
            padding-left: 2rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.4375rem;
            top: 2.5rem;
            bottom: -1rem;
            width: 2px;
            background: rgba(156, 163, 175, 0.2);
        }
        .timeline-item:last-child::before {
            display: none;
        }
        .api-details-content {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>

    <div class="space-y-6">
        {{-- A. Header & Bot Selector --}}
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            {{-- Left: Title & Subtitle --}}
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">
                    Bot Intelligence
                </h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Deep, real-time insight into your grid bot's health and behavior
                </p>
            </div>

            {{-- Right: Bot Selector --}}
            <div class="flex items-center gap-3">
                @if($availableBots->isNotEmpty())
                    <select
                        wire:model.live="selectedBotId"
                        class="block rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                    >
                        @foreach($availableBots as $bot)
                            <option value="{{ $bot['id'] }}">
                                {{ $bot['name'] }} ({{ $bot['symbol'] }})
                            </option>
                        @endforeach
                    </select>

                    @if($selectedBot)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                            {{ $selectedBot->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $selectedBot->is_active ? 'Active' : 'Paused' }}
                        </span>
                    @endif
                @else
                    <p class="text-sm text-gray-500">No bots configured</p>
                @endif
            </div>
        </div>

        {{-- Divider --}}
        <div class="section-divider"></div>

        @if($selectedBot)
            {{-- B. Global Bot Snapshot (Top Metrics Strip) --}}
            <div>
                <div class="slider-container pb-2">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 min-w-max lg:min-w-0">
                        @foreach($metrics as $key => $metric)
                            <div class="metric-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700 min-w-[200px] lg:min-w-0">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            {{ $metric['label'] }}
                                        </p>
                                        <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                                            {{ $metric['value'] }}
                                        </p>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $metric['caption'] }}
                                        </p>
                                    </div>
                                    <div class="ml-3">
                                        <x-filament::icon
                                            :icon="$metric['icon']"
                                            class="w-8 h-8 text-{{ $metric['color'] }}-500"
                                        />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- C. Grid Map & Order Distribution --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Grid Map</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Active order distribution across grid levels</p>
                </div>

                @if($gridMap['has_data'] ?? false)
                    <div class="space-y-3">
                        {{-- Grid summary --}}
                        <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400 pb-3 border-b border-gray-200 dark:border-gray-700">
                            <div>
                                <span class="font-medium">Top:</span>
                                <span class="ml-1">{{ $gridMap['top_price'] }} IRT</span>
                            </div>
                            <div>
                                <span class="font-medium">Levels:</span>
                                <span class="ml-1">{{ $gridMap['total_levels'] }}</span>
                            </div>
                            <div>
                                <span class="font-medium">Bottom:</span>
                                <span class="ml-1">{{ $gridMap['bottom_price'] }} IRT</span>
                            </div>
                        </div>

                        {{-- Grid levels --}}
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            @foreach($gridMap['levels'] as $level)
                                <div class="flex items-center gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors">
                                    <div class="flex-shrink-0 w-12 text-center">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">L{{ $level['index'] }}</span>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-sm font-medium text-gray-900 dark:text-white">
                                                {{ $level['price'] }}
                                            </span>
                                            <span class="text-xs text-gray-500">IRT</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                            Amount: {{ $level['amount'] }}
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <span class="grid-level-badge inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                                            {{ $level['side'] === 'buy' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' }}">
                                            {{ strtoupper($level['side']) }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-filament::icon
                            icon="heroicon-o-chart-bar-square"
                            class="w-12 h-12 mx-auto text-gray-400"
                        />
                        <p class="mt-2 text-sm text-gray-500">{{ $gridMap['message'] ?? 'No data available' }}</p>
                    </div>
                @endif
            </div>

            {{-- D. Open Orders & Completed Pairs (Side by Side) --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Open Orders --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Open Orders</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Recent active orders</p>
                    </div>

                    @if($openOrders->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($openOrders as $order)
                                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium
                                            {{ $order['side'] === 'buy' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' }}">
                                            {{ $order['type'] }}
                                        </span>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $order['price'] }} IRT</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $order['amount'] }}</div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $order['time_ago'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <p class="text-sm text-gray-500">No open orders</p>
                        </div>
                    @endif
                </div>

                {{-- Completed Pairs --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Completed Trades</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Recent completed cycles</p>
                    </div>

                    @if($completedPairs->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($completedPairs as $pair)
                                <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">#{{ $pair['id'] }}</span>
                                        <span class="text-xs {{ $pair['is_profitable'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }} font-medium">
                                            {{ $pair['profit_formatted'] }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <div class="text-gray-600 dark:text-gray-400">
                                            <span>{{ $pair['buy_price'] }}</span>
                                            <span class="mx-1">→</span>
                                            <span>{{ $pair['sell_price'] }}</span>
                                        </div>
                                        <div class="text-gray-500">{{ $pair['duration'] }}</div>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $pair['completed_at'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <p class="text-sm text-gray-500">No completed trades yet</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- E. Risk & Drift Indicators --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Capital Concentration --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="mb-4">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Capital Concentration</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Distribution across order types</p>
                    </div>

                    <div class="space-y-3">
                        {{-- Buy Orders --}}
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Buy Orders</span>
                                <span class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $capitalConcentration['buy']['percent'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="bg-blue-500 h-2 rounded-full transition-all" style="width: {{ $capitalConcentration['buy']['percent'] }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $capitalConcentration['buy']['count'] }} orders • {{ $capitalConcentration['buy']['capital'] }} IRT</p>
                        </div>

                        {{-- Sell Orders --}}
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Sell Orders</span>
                                <span class="text-sm font-semibold text-amber-600 dark:text-amber-400">{{ $capitalConcentration['sell']['percent'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="bg-amber-500 h-2 rounded-full transition-all" style="width: {{ $capitalConcentration['sell']['percent'] }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $capitalConcentration['sell']['count'] }} orders • {{ $capitalConcentration['sell']['capital'] }} IRT</p>
                        </div>

                        {{-- Free Capital --}}
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Free Capital</span>
                                <span class="text-sm font-semibold text-gray-600 dark:text-gray-400">{{ $capitalConcentration['free']['percent'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="bg-gray-400 h-2 rounded-full transition-all" style="width: {{ $capitalConcentration['free']['percent'] }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $capitalConcentration['free']['capital'] }} IRT available</p>
                        </div>
                    </div>
                </div>

                {{-- Grid Drift --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="mb-4">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Grid Drift</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Trading zone indicator</p>
                    </div>

                    <div class="text-center py-4">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-{{ $gridDrift['color'] }}-100 dark:bg-{{ $gridDrift['color'] }}-900/30">
                            <span class="text-2xl font-bold text-{{ $gridDrift['color'] }}-600 dark:text-{{ $gridDrift['color'] }}-400">
                                {{ round($gridDrift['position']) }}%
                            </span>
                        </div>
                        <p class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ $gridDrift['status'] }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $gridDrift['description'] }}</p>
                    </div>

                    <div class="mt-4">
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 relative">
                            <div class="absolute top-0 left-0 right-0 h-2 bg-gradient-to-r from-blue-500 via-green-500 to-amber-500 rounded-full"></div>
                            <div class="absolute top-1/2 -translate-y-1/2 w-1 h-4 bg-gray-900 dark:bg-white rounded-full transition-all" style="left: {{ $gridDrift['position'] }}%"></div>
                        </div>
                        <div class="flex justify-between mt-1 text-xs text-gray-500">
                            <span>Bottom</span>
                            <span>Top</span>
                        </div>
                    </div>
                </div>

                {{-- Stability Snapshot --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="mb-4">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Stability</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Error monitoring (24h)</p>
                    </div>

                    <div class="text-center py-4">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-{{ $systemHealth['stability']['color'] }}-100 dark:bg-{{ $systemHealth['stability']['color'] }}-900/30">
                            <x-filament::icon
                                :icon="$systemHealth['stability']['errors_24h'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                                class="w-10 h-10 text-{{ $systemHealth['stability']['color'] }}-600 dark:text-{{ $systemHealth['stability']['color'] }}-400"
                            />
                        </div>
                        <p class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ $systemHealth['stability']['value'] }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $systemHealth['stability']['errors_24h'] }} {{ $systemHealth['stability']['errors_24h'] === 1 ? 'error' : 'errors' }} in last 24h
                        </p>
                    </div>

                    @if($systemHealth['stability']['errors_24h'] > 0)
                        <div class="mt-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                            <p class="text-xs text-amber-800 dark:text-amber-300">Review activity logs for details</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- F. System Health & Job Status --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">System Health</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Infrastructure and connectivity status</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($systemHealth as $key => $health)
                        @if($key !== 'stability')
                            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-3">
                                    <div class="flex-shrink-0">
                                        <div class="w-2 h-2 rounded-full bg-{{ $health['color'] }}-500"></div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $health['label'] }}</p>
                                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white truncate">{{ $health['value'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- G. Activity Timeline --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Activity Timeline</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Recent bot activity and events</p>
                </div>

                @if($activityLogs->isNotEmpty())
                    <div class="space-y-4 max-h-[600px] overflow-y-auto">
                        @foreach($activityLogs as $log)
                            <div class="timeline-item">
                                <div class="flex items-start gap-3">
                                    {{-- Icon --}}
                                    <div class="flex-shrink-0 w-7 h-7 rounded-full bg-{{ $log['color'] }}-100 dark:bg-{{ $log['color'] }}-900/30 flex items-center justify-center">
                                        <x-filament::icon
                                            :icon="$log['icon']"
                                            class="w-4 h-4 text-{{ $log['color'] }}-600 dark:text-{{ $log['color'] }}-400"
                                        />
                                    </div>

                                    {{-- Content --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-sm text-gray-900 dark:text-white">{{ $log['message'] }}</p>
                                            <span class="flex-shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $log['time_ago'] }}</span>
                                        </div>
                                        <div class="mt-1 flex items-center gap-2">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $log['action_type'] }}</span>
                                            @if($log['execution_time'])
                                                <span class="text-xs text-gray-400">•</span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $log['execution_time'] }}ms</span>
                                            @endif
                                        </div>

                                        {{-- API Details Button --}}
                                        @if($log['has_api_data'])
                                            <div x-data="{ open: false }">
                                                <button
                                                    type="button"
                                                    @click="open = !open"
                                                    class="mt-2 text-xs text-primary-600 dark:text-primary-400 hover:underline"
                                                >
                                                    <span x-show="!open">View API Details</span>
                                                    <span x-show="open" style="display: none;">Hide API Details</span>
                                                </button>

                                                {{-- API Details (Collapsible) --}}
                                                <div
                                                    x-show="open"
                                                    x-collapse
                                                    style="display: none;"
                                                    class="mt-2 p-3 rounded-lg bg-gray-100 dark:bg-gray-900 border border-gray-200 dark:border-gray-700"
                                                >
                                                <div class="api-details-content">
                                                    @if($log['api_request'])
                                                        <div class="mb-3">
                                                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Request:</p>
                                                            <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($log['api_request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                        </div>
                                                    @endif
                                                    @if($log['api_response'])
                                                        <div>
                                                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Response:</p>
                                                            <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($log['api_response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                        </div>
                                                    @endif
                                                </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-filament::icon
                            icon="heroicon-o-clock"
                            class="w-12 h-12 mx-auto text-gray-400"
                        />
                        <p class="mt-2 text-sm text-gray-500">No activity logs yet</p>
                    </div>
                @endif
            </div>

        @else
            {{-- No bot selected state --}}
            <div class="text-center py-12">
                <x-filament::icon
                    icon="heroicon-o-cpu-chip"
                    class="w-16 h-16 mx-auto text-gray-400"
                />
                <p class="mt-4 text-lg font-medium text-gray-900 dark:text-white">No Bot Selected</p>
                <p class="mt-1 text-sm text-gray-500">Please create a bot configuration to view the dashboard</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
