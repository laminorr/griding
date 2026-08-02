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
            transition: all 0.2s ease-out;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .section-card {
            transition: all 0.2s ease-out;
        }
        .section-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .metric-strip {
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(156, 163, 175, 0.3) transparent;
        }
        .metric-strip::-webkit-scrollbar {
            height: 4px;
        }
        .metric-strip::-webkit-scrollbar-track {
            background: transparent;
        }
        .metric-strip::-webkit-scrollbar-thumb {
            background-color: rgba(156, 163, 175, 0.3);
            border-radius: 2px;
        }
        .metric-strip::-webkit-scrollbar-thumb:hover {
            background-color: rgba(156, 163, 175, 0.5);
        }
        .timeline-connector {
            position: absolute;
            left: 11px;
            top: 28px;
            bottom: -16px;
            width: 2px;
            background: rgba(229, 231, 235, 1);
        }
        .timeline-connector-dark {
            background: rgba(55, 65, 81, 1);
        }
        .api-details-content {
            max-height: 400px;
            overflow-y: auto;
        }

        /* --- P3 structural repair -----------------------------------------
           The admin panel serves only Filament's precompiled stylesheet (the
           project's Tailwind theme build is disabled), so the w-* / h-* sizing
           utilities these icons use are absent from it. The filament icon
           component renders each heroicon as a raw SVG whose class carries
           those utilities — with them missing the SVG has no width/height and
           renders unbounded (the "giant clock" empty state, and the smaller
           icons that overlap their rows). Pin the sizes here, in this always
           served inline style block, mirroring how Bot Monitoring keeps its
           structure in inline CSS. */
        .bot-intel .intel-icon     { flex: none; display: inline-block; }
        .bot-intel .intel-icon-xs  { width: 0.875rem; height: 0.875rem; }
        .bot-intel .intel-icon-sm  { width: 1.25rem;  height: 1.25rem; }
        .bot-intel .intel-icon-md  { width: 2rem;     height: 2rem; }
        .bot-intel .intel-icon-lg  { width: 3rem;     height: 3rem; }
        .bot-intel .intel-icon-xl  { width: 4rem;     height: 4rem; }
        .bot-intel .intel-badge    { flex: none; display: inline-flex; align-items: center; justify-content: center; }
        .bot-intel .intel-badge-sm { width: 1.5rem; height: 1.5rem; }
        .bot-intel .intel-badge-lg { width: 4rem;   height: 4rem; }
        /* Card padding/radius fallbacks so sections read as cards even when
           the panel bundle lacks p-5 / rounded-2xl. */
        .bot-intel .intel-card     { padding: 1.25rem; border-radius: 1rem; }
    </style>

    {{-- Main Container - Centered and Constrained --}}
    <div class="bot-intel max-w-6xl mx-auto w-full space-y-6">

        @if($selectedBot)
            {{-- A. HEADER SECTION - Floating Card --}}
            <div class="intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    {{-- Left: Title & Subtitle --}}
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            هوش ربات
                        </h1>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            نمای لحظه‌ای از سلامت و رفتار ربات گرید شما
                        </p>
                    </div>

                    {{-- Right: Bot Selector --}}
                    <div class="flex items-center gap-3">
                        @if($availableBots->isNotEmpty())
                            <select
                                wire:model.live="selectedBotId"
                                class="block rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm py-1.5"
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
                                    {{ $selectedBot->is_active ? 'فعال' : 'متوقف' }}
                                </span>
                            @endif
                        @else
                            <p class="text-sm text-gray-500">هیچ رباتی پیکربندی نشده</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- B. SNAPSHOT METRICS - Floating Card with Compact Metric Strip --}}
            <div class="intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                <div class="metric-strip flex gap-4 pb-2 -mx-1 px-1">
                    @foreach($metrics as $key => $metric)
                        <div class="metric-card min-w-[180px] max-w-[220px] bg-gray-50 dark:bg-gray-900/50 border border-gray-100 dark:border-gray-700 rounded-xl px-4 py-3 flex flex-col justify-between">
                            {{-- Top: Icon + Label --}}
                            <div class="flex items-start justify-between mb-2">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                    {{ $metric['label'] }}
                                </p>
                                <x-filament::icon
                                    :icon="$metric['icon']"
                                    class="intel-icon intel-icon-sm w-5 h-5 text-{{ $metric['color'] }}-500"
                                />
                            </div>
                            {{-- Middle: Value --}}
                            <p class="text-xl font-semibold text-gray-900 dark:text-white leading-tight">
                                {{ $metric['value'] }}
                            </p>
                            {{-- Bottom: Caption --}}
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $metric['caption'] }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- C. GRID MAP - Floating Card --}}
            <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                <div class="mb-4">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white">نقشه گرید</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">توزیع سفارش‌های فعال در سطوح گرید</p>
                </div>

                @if($gridMap['has_data'] ?? false)
                    <div class="space-y-3">
                        {{-- Grid summary --}}
                        <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400 pb-3 border-b border-gray-200 dark:border-gray-700">
                            <div>
                                <span class="font-medium">بالا:</span>
                                <span class="ml-1">{{ $gridMap['top_price'] }} IRT</span>
                            </div>
                            <div>
                                <span class="font-medium">سطوح:</span>
                                <span class="ml-1">{{ $gridMap['total_levels'] }}</span>
                            </div>
                            <div>
                                <span class="font-medium">پایین:</span>
                                <span class="ml-1">{{ $gridMap['bottom_price'] }} IRT</span>
                            </div>
                        </div>

                        {{-- Grid levels --}}
                        <div class="space-y-2 max-h-80 overflow-y-auto">
                            @foreach($gridMap['levels'] as $level)
                                <div class="flex items-center justify-between gap-3 p-2.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors">
                                    <div class="flex items-center gap-3 flex-1">
                                        <span class="text-xs font-medium text-gray-400 dark:text-gray-500 w-8">L{{ $level['index'] }}</span>
                                        <div>
                                            <div class="flex items-baseline gap-1.5">
                                                <span class="font-mono text-sm font-medium text-gray-900 dark:text-white">
                                                    {{ $level['price'] }}
                                                </span>
                                                <span class="text-xs text-gray-500">IRT</span>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $level['amount'] }}
                                            </div>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                                        {{ $level['side'] === 'buy' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200' }}">
                                        {{ $level['side'] === 'buy' ? 'خرید' : 'فروش' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-filament::icon
                            icon="heroicon-o-chart-bar-square"
                            class="intel-icon intel-icon-lg w-12 h-12 mx-auto text-gray-400"
                        />
                        <p class="mt-2 text-sm text-gray-500">{{ $gridMap['message'] ?? 'داده‌ای موجود نیست' }}</p>
                    </div>
                @endif
            </div>

            {{-- D. OPEN ORDERS & COMPLETED PAIRS - Side by Side --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Open Orders --}}
                <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-white">سفارش‌های باز</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">سفارش‌های فعال اخیر</p>
                    </div>

                    @if($openOrders->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($openOrders as $order)
                                <div class="flex items-center justify-between p-2.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors">
                                    <div class="flex items-center gap-2.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            {{ $order['side'] === 'buy' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200' }}">
                                            {{ $order['type'] }}
                                        </span>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $order['price'] }} IRT</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $order['amount'] }}</div>
                                        </div>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $order['time_ago'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <p class="text-sm text-gray-500">سفارش بازی وجود ندارد</p>
                        </div>
                    @endif
                </div>

                {{-- Completed Pairs --}}
                <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-white">معاملات تکمیل‌شده</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">چرخه‌های تکمیل‌شده اخیر</p>
                    </div>

                    @if($completedPairs->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($completedPairs as $pair)
                                <div class="p-2.5 rounded-lg bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors">
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
                                    <div class="mt-0.5 text-xs text-gray-500">{{ $pair['completed_at'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <p class="text-sm text-gray-500">هنوز معامله‌ای تکمیل نشده</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- E. RISK & DRIFT INDICATORS - Three Column Grid --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Capital Concentration --}}
                <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white">تمرکز سرمایه</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">توزیع بین انواع سفارش</p>
                    </div>

                    <div class="space-y-4">
                        {{-- Buy Orders --}}
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">سفارش‌های خرید</span>
                                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $capitalConcentration['buy']['percent'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-1">
                                <div class="bg-blue-500 h-2 rounded-full transition-all" style="width: {{ $capitalConcentration['buy']['percent'] }}%"></div>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $capitalConcentration['buy']['count'] }} سفارش • {{ $capitalConcentration['buy']['capital'] }} IRT</p>
                        </div>

                        {{-- Sell Orders --}}
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">سفارش‌های فروش</span>
                                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">{{ $capitalConcentration['sell']['percent'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-1">
                                <div class="bg-amber-500 h-2 rounded-full transition-all" style="width: {{ $capitalConcentration['sell']['percent'] }}%"></div>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $capitalConcentration['sell']['count'] }} سفارش • {{ $capitalConcentration['sell']['capital'] }} IRT</p>
                        </div>

                        {{-- Free Capital --}}
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">سرمایه آزاد</span>
                                <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">{{ $capitalConcentration['free']['percent'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-1">
                                <div class="bg-gray-400 h-2 rounded-full transition-all" style="width: {{ $capitalConcentration['free']['percent'] }}%"></div>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $capitalConcentration['free']['capital'] }} IRT قابل استفاده</p>
                        </div>
                    </div>
                </div>

                {{-- Grid Drift --}}
                <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white">انحراف گرید</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">شاخص ناحیه معاملاتی</p>
                    </div>

                    <div class="text-center py-3">
                        <div class="intel-badge intel-badge-lg inline-flex items-center justify-center w-16 h-16 rounded-full bg-{{ $gridDrift['color'] }}-100 dark:bg-{{ $gridDrift['color'] }}-900/30 mb-3">
                            <span class="text-xl font-bold text-{{ $gridDrift['color'] }}-600 dark:text-{{ $gridDrift['color'] }}-400">
                                {{ round($gridDrift['position']) }}%
                            </span>
                        </div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $gridDrift['status'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $gridDrift['description'] }}</p>
                    </div>

                    <div class="mt-4">
                        {{-- Labels Above Bar --}}
                        <div class="flex justify-between mb-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span>پایین</span>
                            <span>بالا</span>
                        </div>
                        {{-- Bar (no text inside) --}}
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 relative overflow-hidden">
                            <div class="absolute top-0 left-0 right-0 h-3 bg-gradient-to-r from-blue-500 via-green-500 to-amber-500 rounded-full"></div>
                            <div class="absolute top-1/2 -translate-y-1/2 w-1 h-5 bg-gray-900 dark:bg-white rounded-full shadow-sm transition-all" style="left: {{ $gridDrift['position'] }}%"></div>
                        </div>
                    </div>
                </div>

                {{-- Stability Snapshot --}}
                <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white">پایداری</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">پایش خطا (۲۴ ساعت)</p>
                    </div>

                    <div class="text-center py-3">
                        <div class="intel-badge intel-badge-lg inline-flex items-center justify-center w-16 h-16 rounded-full bg-{{ $systemHealth['stability']['color'] }}-100 dark:bg-{{ $systemHealth['stability']['color'] }}-900/30 mb-3">
                            <x-filament::icon
                                :icon="$systemHealth['stability']['errors_24h'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                                class="intel-icon intel-icon-md w-8 h-8 text-{{ $systemHealth['stability']['color'] }}-600 dark:text-{{ $systemHealth['stability']['color'] }}-400"
                            />
                        </div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $systemHealth['stability']['value'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ $systemHealth['stability']['errors_24h'] }} خطا در ۲۴ ساعت گذشته
                        </p>
                    </div>

                    @if($systemHealth['stability']['errors_24h'] > 0)
                        <div class="mt-3 p-2.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                            <p class="text-xs text-amber-800 dark:text-amber-300">برای جزئیات، گزارش فعالیت‌ها را بررسی کنید</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- F. SYSTEM HEALTH - Floating Card --}}
            <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                <div class="mb-4">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white">سلامت سیستم</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">وضعیت زیرساخت و اتصال</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($systemHealth as $key => $health)
                        @if($key !== 'stability')
                            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-900/50 border border-gray-100 dark:border-gray-700">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex-shrink-0">
                                        <div class="w-2 h-2 rounded-full bg-{{ $health['color'] }}-500"></div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs uppercase tracking-wide font-medium text-gray-500 dark:text-gray-400">{{ $health['label'] }}</p>
                                        <p class="mt-0.5 text-sm font-medium text-gray-900 dark:text-white truncate">{{ $health['value'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- G. ACTIVITY TIMELINE - Floating Card --}}
            <div class="section-card intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm">
                <div class="mb-4">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white">خط زمانی فعالیت</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">فعالیت‌ها و رویدادهای اخیر ربات</p>
                </div>

                @if($activityLogs->isNotEmpty())
                    <div class="space-y-4 max-h-[500px] overflow-y-auto">
                        @foreach($activityLogs as $index => $log)
                            <div class="relative flex gap-3">
                                {{-- Icon with connector line --}}
                                <div class="relative flex-shrink-0">
                                    <div class="intel-badge intel-badge-sm w-6 h-6 rounded-full bg-{{ $log['color'] }}-100 dark:bg-{{ $log['color'] }}-900/30 flex items-center justify-center">
                                        <x-filament::icon
                                            :icon="$log['icon']"
                                            class="intel-icon intel-icon-xs w-3.5 h-3.5 text-{{ $log['color'] }}-600 dark:text-{{ $log['color'] }}-400"
                                        />
                                    </div>
                                    @if(!$loop->last)
                                        <div class="timeline-connector dark:timeline-connector-dark"></div>
                                    @endif
                                </div>

                                {{-- Content --}}
                                <div class="flex-1 min-w-0 pb-4">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm text-gray-900 dark:text-white">{{ $log['message'] }}</p>
                                        <span class="flex-shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $log['time_ago'] }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span>{{ $log['action_type'] }}</span>
                                        @if($log['execution_time'])
                                            <span>•</span>
                                            <span>{{ $log['execution_time'] }}ms</span>
                                        @endif
                                    </div>

                                    {{-- API Details Button --}}
                                    @if($log['has_api_data'])
                                        <div x-data="{ open: false }" class="mt-2">
                                            <button
                                                type="button"
                                                @click="open = !open"
                                                class="text-xs text-primary-600 dark:text-primary-400 hover:underline"
                                            >
                                                <span x-show="!open">نمایش جزئیات API</span>
                                                <span x-show="open" style="display: none;">پنهان کردن جزئیات API</span>
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
                                                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">درخواست:</p>
                                                            <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($log['api_request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                        </div>
                                                    @endif
                                                    @if($log['api_response'])
                                                        <div>
                                                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">پاسخ:</p>
                                                            <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($log['api_response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-filament::icon
                            icon="heroicon-o-clock"
                            class="intel-icon intel-icon-lg mx-auto text-gray-400"
                        />
                        <p class="mt-2 text-sm text-gray-500">هنوز گزارشی از فعالیت وجود ندارد</p>
                    </div>
                @endif
            </div>

        @else
            {{-- No bot selected state --}}
            <div class="intel-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-12">
                <div class="text-center">
                    <x-filament::icon
                        icon="heroicon-o-cpu-chip"
                        class="intel-icon intel-icon-xl mx-auto text-gray-400"
                    />
                    <p class="mt-4 text-lg font-medium text-gray-900 dark:text-white">رباتی انتخاب نشده</p>
                    <p class="mt-1 text-sm text-gray-500">برای مشاهده داشبورد، یک ربات پیکربندی کنید</p>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
