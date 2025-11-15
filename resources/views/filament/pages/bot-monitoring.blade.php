<x-filament-panels::page>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Vazirmatn:wght@400;700&display=swap');

        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        .en-font {
            font-family: 'Inter', sans-serif;
        }

        .glass-card {
            background: rgba(17, 24, 39, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            background: rgba(17, 24, 39, 0.8);
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .pulse-slow {
            animation: pulse-slow 3s ease-in-out infinite;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.1);
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(59, 130, 246, 0.5);
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: rgba(59, 130, 246, 0.7);
        }
    </style>

    <div x-data="botMonitoring()" x-init="init()" class="min-h-screen">

        <!-- Main Container with proper margins -->
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            <!-- Header -->
            <div class="glass-card rounded-xl p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <div class="w-3 h-3 bg-green-500 rounded-full animate-ping absolute"></div>
                            <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold text-white">مانیتورینگ ربات گرید</h1>
                            <p class="text-sm text-gray-400">ردیابی لحظه‌ای عملکرد</p>
                        </div>
                    </div>
                    <div class="text-left en-font">
                        <div class="text-xs text-gray-500">زمان سیستم</div>
                        <div class="text-sm text-gray-300" x-text="new Date().toLocaleString('fa-IR')"></div>
                    </div>
                </div>
            </div>

            <!-- Loading -->
            <div x-show="loading" class="flex items-center justify-center py-20">
                <div class="w-12 h-12 border-4 border-blue-500/20 border-t-blue-500 rounded-full animate-spin"></div>
            </div>

            <!-- Bot Content -->
            <div x-show="!loading">
                <template x-for="bot in bots" :key="bot.id">
                    <div class="space-y-6">

                        <!-- Bot Info Card -->
                        <div class="glass-card rounded-xl p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <h2 class="text-2xl font-bold text-white mb-2" x-text="bot.name"></h2>
                                    <div class="flex items-center gap-3 text-sm text-gray-400">
                                        <span class="en-font" x-text="bot.symbol"></span>
                                        <span>•</span>
                                        <span x-text="bot.grid_levels + ' سطح'"></span>
                                        <span>•</span>
                                        <span x-text="'فاصله ' + (bot.grid_spacing * 100).toFixed(1) + '%'"></span>
                                    </div>
                                </div>
                                <div>
                                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-green-500/10">
                                        <div class="w-2 h-2 bg-green-400 rounded-full pulse-slow"></div>
                                        <span class="text-sm font-semibold text-green-400">فعال</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-2 text-left" x-text="'آخرین بررسی: ' + (bot.last_check_at || 'هرگز')"></div>
                                </div>
                            </div>

                            <!-- Quick Stats -->
                            <div class="grid grid-cols-4 gap-4">
                                <div class="glass-card rounded-lg p-4">
                                    <div class="text-xs text-gray-400 mb-2">سرمایه کل</div>
                                    <div class="text-2xl font-bold text-white en-font" x-text="(bot.capital / 10000000).toFixed(1) + 'M'"></div>
                                    <div class="text-xs text-gray-500">میلیون تومان</div>
                                </div>
                                <div class="glass-card rounded-lg p-4">
                                    <div class="text-xs text-gray-400 mb-2">سفارشات فعال</div>
                                    <div class="text-2xl font-bold text-white" x-text="bot.active_orders.length"></div>
                                    <div class="text-xs text-gray-500">در انتظار</div>
                                </div>
                                <div class="glass-card rounded-lg p-4">
                                    <div class="text-xs text-gray-400 mb-2">معاملات (24 ساعت)</div>
                                    <div class="text-2xl font-bold text-white" x-text="bot.completed_trades_24h"></div>
                                    <div class="text-xs text-gray-500">تکمیل شده</div>
                                </div>
                                <div class="glass-card rounded-lg p-4">
                                    <div class="text-xs text-gray-400 mb-2">سود (24 ساعت)</div>
                                    <div class="text-2xl font-bold text-green-400 en-font" x-text="(bot.profit_24h / 1000).toFixed(0) + 'K'"></div>
                                    <div class="text-xs text-gray-500">هزار تومان</div>
                                </div>
                            </div>
                        </div>

                        <!-- Active Orders Grid Visualization -->
                        <div class="glass-card rounded-xl p-6">
                            <h3 class="text-lg font-bold text-white mb-6">شبکه سفارشات فعال</h3>

                            <!-- Visual Grid with Current Price Indicator -->
                            <div class="space-y-3">
                                <template x-for="(order, index) in getSortedOrders(bot.active_orders)" :key="order.id">
                                    <div>
                                        <!-- Order Card -->
                                        <div class="glass-card rounded-lg p-5 hover:scale-[1.02] transition-transform">
                                            <div class="flex items-center justify-between">
                                                <!-- Left: Type & Price -->
                                                <div class="flex items-center gap-4">
                                                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl"
                                                        :class="order.type === 'buy' ? 'bg-green-500/20' : 'bg-red-500/20'">
                                                        <span x-text="order.type === 'buy' ? '🟢' : '🔴'"></span>
                                                    </div>
                                                    <div>
                                                        <div class="text-sm text-gray-400 mb-1" x-text="order.type === 'buy' ? 'خرید' : 'فروش'"></div>
                                                        <div class="text-2xl font-bold text-white en-font" x-text="(order.price / 10000000).toFixed(0) + ' میلیون'"></div>
                                                    </div>
                                                </div>

                                                <!-- Middle: Details -->
                                                <div class="text-center">
                                                    <div class="text-xs text-gray-500 mb-1">مقدار</div>
                                                    <div class="text-sm text-gray-300 en-font" x-text="order.amount + ' BTC'"></div>
                                                </div>

                                                <!-- Right: Status -->
                                                <div class="text-left">
                                                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg"
                                                        :class="order.paired_order_id ? 'bg-blue-500/20 text-blue-300' : 'bg-gray-700/50 text-gray-400'">
                                                        <span x-text="order.paired_order_id ? '🔗 جفت‌شده' : '⏳ در انتظار'"></span>
                                                    </div>
                                                    <div class="text-xs text-gray-600 mt-2 en-font" x-text="'#' + order.nobitex_order_id"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Connector Line -->
                                        <div x-show="index < bot.active_orders.length - 1"
                                            class="h-8 w-px bg-gradient-to-b from-gray-600 to-transparent mx-auto"></div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- What's Bot Waiting For? -->
                        <div class="glass-card rounded-xl p-6">
                            <div class="flex items-center gap-2 mb-6">
                                <span class="text-2xl pulse-slow">⏰</span>
                                <h3 class="text-lg font-bold text-white">ربات منتظر چیست؟</h3>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <template x-for="order in bot.active_orders.filter(o => !o.paired_order_id).slice(0, 2)" :key="'wait-' + order.id">
                                    <div class="glass-card rounded-lg p-5">
                                        <div class="flex items-center gap-3 mb-3">
                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center"
                                                :class="order.type === 'buy' ? 'bg-green-500/30' : 'bg-red-500/30'">
                                                <span x-text="order.type === 'buy' ? '↓' : '↑'"></span>
                                            </div>
                                            <div class="text-sm font-semibold"
                                                :class="order.type === 'buy' ? 'text-green-400' : 'text-red-400'"
                                                x-text="order.type === 'buy' ? 'کاهش قیمت' : 'افزایش قیمت'"></div>
                                        </div>
                                        <div class="text-2xl font-bold text-white en-font mb-1"
                                            x-text="(order.price / 10000000).toFixed(0) + ' میلیون'"></div>
                                        <div class="text-xs text-gray-500">
                                            قیمت هدف برای
                                            <span x-text="order.type === 'buy' ? 'خرید' : 'فروش'"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="mt-4 text-center text-sm text-gray-500">
                                سیستم هر 5 دقیقه یکبار بررسی می‌کند...
                            </div>
                        </div>

                        <!-- Performance Summary -->
                        <div class="glass-card rounded-xl p-6">
                            <h3 class="text-lg font-bold text-white mb-6">خلاصه عملکرد</h3>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="text-center glass-card rounded-lg p-4">
                                    <div class="text-3xl font-bold text-white" x-text="bot.total_cycles || 0"></div>
                                    <div class="text-sm text-gray-400 mt-2">چرخه کامل شده</div>
                                </div>
                                <div class="text-center glass-card rounded-lg p-4">
                                    <div class="text-3xl font-bold text-white en-font" x-text="formatDuration(bot.avg_cycle_duration || 0)"></div>
                                    <div class="text-sm text-gray-400 mt-2">میانگین مدت چرخه</div>
                                </div>
                                <div class="text-center glass-card rounded-lg p-4">
                                    <div class="text-3xl font-bold text-green-400 en-font" x-text="(bot.total_cycles > 0 ? '100' : '0') + '%'"></div>
                                    <div class="text-sm text-gray-400 mt-2">نرخ موفقیت</div>
                                </div>
                            </div>
                        </div>

                        <!-- Activity Log - Redesigned -->
                        <div class="glass-card rounded-2xl p-6" x-data="activityLog()">
                            <!-- Header -->
                            <div class="flex items-center justify-between mb-8">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500/20 to-purple-500/20 rounded-xl flex items-center justify-center">
                                        <span class="text-2xl">📋</span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-white">گزارش فعالیت‌ها</h3>
                                        <p class="text-xs text-gray-400">چرخه‌های بررسی ربات</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <div class="w-2 h-2 bg-green-500 rounded-full pulse-slow"></div>
                                    <span>به‌روزرسانی هر 30 ثانیه</span>
                                </div>
                            </div>

                            <!-- Summary Bar -->
                            <div x-show="bot.activity_summary && bot.activity_summary.last_cycle_status"
                                class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                                <!-- Last Cycle Status -->
                                <div class="bg-gradient-to-br from-gray-800/50 to-gray-900/50 rounded-xl p-4 border border-gray-700/30">
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-2 h-2 rounded-full"
                                            :class="{
                                                'bg-green-500': bot.activity_summary.last_cycle_status === 'success',
                                                'bg-yellow-500': bot.activity_summary.last_cycle_status === 'warning',
                                                'bg-red-500': bot.activity_summary.last_cycle_status === 'error',
                                                'bg-blue-500': bot.activity_summary.last_cycle_status === 'in_progress'
                                            }"></div>
                                        <span class="text-xs text-gray-400">وضعیت آخرین چرخه</span>
                                    </div>
                                    <div class="text-sm font-semibold"
                                        :class="{
                                            'text-green-400': bot.activity_summary.last_cycle_status === 'success',
                                            'text-yellow-400': bot.activity_summary.last_cycle_status === 'warning',
                                            'text-red-400': bot.activity_summary.last_cycle_status === 'error',
                                            'text-blue-400': bot.activity_summary.last_cycle_status === 'in_progress'
                                        }"
                                        x-text="{
                                            'success': '✓ موفق',
                                            'warning': '⚠ هشدار',
                                            'error': '✕ خطا',
                                            'in_progress': '⟳ در حال اجرا'
                                        }[bot.activity_summary.last_cycle_status] || '-'"></div>
                                </div>

                                <!-- Average Cycle Duration -->
                                <div class="bg-gradient-to-br from-gray-800/50 to-gray-900/50 rounded-xl p-4 border border-gray-700/30">
                                    <div class="text-xs text-gray-400 mb-2">میانگین زمان چرخه</div>
                                    <div class="text-sm font-semibold text-white en-font">
                                        <span x-text="formatCycleDuration(bot.activity_summary.avg_cycle_duration)"></span>
                                    </div>
                                </div>

                                <!-- Average API Latency -->
                                <div class="bg-gradient-to-br from-gray-800/50 to-gray-900/50 rounded-xl p-4 border border-gray-700/30">
                                    <div class="text-xs text-gray-400 mb-2">میانگین پاسخ نوبیتکس</div>
                                    <div class="text-sm font-semibold en-font"
                                        :class="bot.activity_summary.avg_api_latency > 1000 ? 'text-yellow-400' : 'text-green-400'">
                                        <span x-text="bot.activity_summary.avg_api_latency.toFixed(0) + 'ms'"></span>
                                    </div>
                                </div>

                                <!-- Cycles in 24h -->
                                <div class="bg-gradient-to-br from-gray-800/50 to-gray-900/50 rounded-xl p-4 border border-gray-700/30">
                                    <div class="text-xs text-gray-400 mb-2">چرخه‌ها (24 ساعت)</div>
                                    <div class="text-sm font-semibold text-white">
                                        <span x-text="bot.activity_summary.cycles_count_24h"></span>
                                        <span class="text-xs text-gray-500 mr-1">چرخه</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Filter Tabs -->
                            <div x-show="bot.activity_cycles && bot.activity_cycles.length > 0" class="flex items-center gap-2 mb-6 overflow-x-auto pb-2">
                                <button @click="activeFilter = 'all'"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap"
                                    :class="activeFilter === 'all' ? 'bg-blue-500/20 text-blue-400 border border-blue-500/30' : 'bg-gray-800/50 text-gray-400 border border-gray-700/30 hover:bg-gray-700/50'">
                                    همه‌ی لاگ‌ها
                                    <span class="text-xs opacity-70 mr-1" x-text="'(' + bot.activity_cycles.length + ')'"></span>
                                </button>
                                <button @click="activeFilter = 'errors'"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap"
                                    :class="activeFilter === 'errors' ? 'bg-red-500/20 text-red-400 border border-red-500/30' : 'bg-gray-800/50 text-gray-400 border border-gray-700/30 hover:bg-gray-700/50'">
                                    فقط خطاها
                                    <span class="text-xs opacity-70 mr-1" x-text="'(' + getErrorCyclesCount(bot.activity_cycles) + ')'"></span>
                                </button>
                                <button @click="activeFilter = 'api'"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap"
                                    :class="activeFilter === 'api' ? 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30' : 'bg-gray-800/50 text-gray-400 border border-gray-700/30 hover:bg-gray-700/50'">
                                    فراخوانی‌های API
                                    <span class="text-xs opacity-70 mr-1" x-text="'(' + getApiCallsCount(bot.activity_cycles) + ')'"></span>
                                </button>
                            </div>

                            <!-- Empty State -->
                            <div x-show="!bot.activity_cycles || bot.activity_cycles.length === 0" class="text-center py-16">
                                <div class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-gray-800/50 to-gray-900/50 rounded-2xl flex items-center justify-center">
                                    <span class="text-5xl">📝</span>
                                </div>
                                <div class="text-lg text-gray-400 mb-2 font-semibold">هنوز فعالیتی ثبت نشده</div>
                                <div class="text-sm text-gray-600">لاگ‌ها پس از اجرای اولین بررسی نمایش داده می‌شوند</div>
                            </div>

                            <!-- Cycles List -->
                            <div x-show="bot.activity_cycles && bot.activity_cycles.length > 0"
                                class="space-y-4 max-h-[600px] overflow-y-auto custom-scrollbar pr-2">
                                <template x-for="cycle in getFilteredCycles(bot.activity_cycles)" :key="cycle.id">
                                    <div class="bg-gradient-to-br from-gray-800/40 to-gray-900/40 rounded-xl border border-gray-700/30 overflow-hidden transition-all hover:border-gray-600/40"
                                        x-data="{ expanded: false }">

                                        <!-- Cycle Header (Clickable) -->
                                        <div @click="expanded = !expanded" class="p-5 cursor-pointer hover:bg-gray-800/30 transition-colors">
                                            <div class="flex items-center justify-between gap-4">
                                                <!-- Left: Status & Info -->
                                                <div class="flex items-center gap-4 flex-1 min-w-0">
                                                    <!-- Status Icon -->
                                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                                                        :class="{
                                                            'bg-green-500/20': cycle.status === 'success',
                                                            'bg-yellow-500/20': cycle.status === 'warning',
                                                            'bg-red-500/20': cycle.status === 'error',
                                                            'bg-blue-500/20': cycle.status === 'in_progress',
                                                            'bg-gray-500/20': cycle.status === 'ungrouped'
                                                        }">
                                                        <span class="text-xl" x-text="{
                                                            'success': '✓',
                                                            'warning': '⚠',
                                                            'error': '✕',
                                                            'in_progress': '⟳',
                                                            'ungrouped': '•'
                                                        }[cycle.status]"></span>
                                                    </div>

                                                    <!-- Cycle Info -->
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="text-sm font-semibold"
                                                                :class="{
                                                                    'text-green-400': cycle.status === 'success',
                                                                    'text-yellow-400': cycle.status === 'warning',
                                                                    'text-red-400': cycle.status === 'error',
                                                                    'text-blue-400': cycle.status === 'in_progress',
                                                                    'text-gray-400': cycle.status === 'ungrouped'
                                                                }"
                                                                x-text="{
                                                                    'success': 'چرخه موفق',
                                                                    'warning': 'چرخه با هشدار',
                                                                    'error': 'چرخه با خطا',
                                                                    'in_progress': 'چرخه در حال اجرا',
                                                                    'ungrouped': 'لاگ‌های متفرقه'
                                                                }[cycle.status]"></span>
                                                            <span class="text-xs text-gray-500">•</span>
                                                            <span class="text-xs text-gray-500" x-text="formatTimeAgo(cycle.started_at_iso)"></span>
                                                            <span x-show="cycle.duration_ms" class="text-xs text-gray-500">•</span>
                                                            <span x-show="cycle.duration_ms" class="text-xs text-gray-400 en-font" x-text="formatCycleDuration(cycle.duration_ms)"></span>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <span x-show="cycle.summary.orders_active > 0" x-text="cycle.summary.orders_active + ' سفارش فعال'"></span>
                                                            <span x-show="cycle.summary.orders_active > 0 && cycle.summary.errors > 0" class="mx-1">•</span>
                                                            <span x-show="cycle.summary.errors > 0" class="text-red-400" x-text="cycle.summary.errors + ' خطا'"></span>
                                                            <span x-show="(cycle.summary.orders_active > 0 || cycle.summary.errors > 0) && cycle.summary.api_calls > 0" class="mx-1">•</span>
                                                            <span x-show="cycle.summary.api_calls > 0" x-text="cycle.summary.api_calls + ' فراخوانی API'"></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Right: Expand Icon -->
                                                <div class="flex-shrink-0">
                                                    <svg class="w-5 h-5 text-gray-500 transition-transform"
                                                        :class="{ 'rotate-180': expanded }"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Cycle Timeline (Expandable) -->
                                        <div x-show="expanded"
                                            x-collapse
                                            class="px-5 pb-5 pt-2 border-t border-gray-700/30">

                                            <!-- Timeline -->
                                            <div class="space-y-3 mt-4">
                                                <template x-for="(event, idx) in cycle.events" :key="event.id">
                                                    <div class="flex gap-4" x-data="{ showApiDetails: false }">
                                                        <!-- Timeline Line -->
                                                        <div class="flex flex-col items-center flex-shrink-0">
                                                            <!-- Icon -->
                                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm"
                                                                :class="{
                                                                    'bg-blue-500/20': event.type.includes('CHECK'),
                                                                    'bg-green-500/20': event.level === 'SUCCESS',
                                                                    'bg-yellow-500/20': event.type === 'API_CALL',
                                                                    'bg-red-500/20': event.level === 'ERROR',
                                                                    'bg-purple-500/20': event.type.includes('PRICE') || event.type === 'WAITING',
                                                                    'bg-cyan-500/20': event.type.includes('ORDER'),
                                                                    'bg-pink-500/20': event.type.includes('TRADE')
                                                                }">
                                                                <span x-text="{
                                                                    'CHECK_TRADES_START': '🔍',
                                                                    'CHECK_TRADES_END': '✨',
                                                                    'API_CALL': '📡',
                                                                    'ORDERS_RECEIVED': '📌',
                                                                    'ORDER_PLACED': '📝',
                                                                    'ORDER_FILLED': '🎯',
                                                                    'ORDER_PAIRED': '🔗',
                                                                    'PRICE_CHECK': '📊',
                                                                    'WAITING': '⏳',
                                                                    'TRADE_COMPLETED': '💰',
                                                                    'ERROR': '❌'
                                                                }[event.type] || '📌'"></span>
                                                            </div>
                                                            <!-- Connecting Line -->
                                                            <div x-show="idx < cycle.events.length - 1"
                                                                class="w-px h-8 bg-gradient-to-b from-gray-600 to-transparent mt-1"></div>
                                                        </div>

                                                        <!-- Event Content -->
                                                        <div class="flex-1 min-w-0 pb-2">
                                                            <div class="flex items-start justify-between gap-2">
                                                                <div class="flex-1 min-w-0">
                                                                    <!-- Message -->
                                                                    <div class="text-sm text-white break-words mb-1" x-text="event.message"></div>

                                                                    <!-- API Call Badge -->
                                                                    <div x-show="event.type === 'API_CALL' && event.execution_time"
                                                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-yellow-500/10 border border-yellow-500/20 mt-1">
                                                                        <span class="text-xs text-yellow-400 en-font" x-text="event.execution_time + 'ms'"></span>
                                                                        <button @click="showApiDetails = !showApiDetails"
                                                                            class="text-xs text-yellow-400 hover:text-yellow-300 underline">
                                                                            جزئیات
                                                                        </button>
                                                                    </div>

                                                                    <!-- API Details (Collapsible) -->
                                                                    <div x-show="showApiDetails"
                                                                        x-collapse
                                                                        class="mt-3 bg-gray-900/80 rounded-lg p-4 border border-gray-700/50">
                                                                        <div class="space-y-3">
                                                                            <!-- Request -->
                                                                            <div x-show="event.api_request">
                                                                                <div class="text-xs text-gray-400 mb-2 font-semibold">درخواست (Request)</div>
                                                                                <pre class="text-xs text-gray-300 en-font overflow-x-auto custom-scrollbar p-2 bg-black/30 rounded" x-text="JSON.stringify(event.api_request, null, 2)"></pre>
                                                                            </div>
                                                                            <!-- Response -->
                                                                            <div x-show="event.api_response">
                                                                                <div class="text-xs text-gray-400 mb-2 font-semibold">پاسخ (Response)</div>
                                                                                <pre class="text-xs text-gray-300 en-font overflow-x-auto custom-scrollbar p-2 bg-black/30 rounded max-h-64" x-text="JSON.stringify(event.api_response, null, 2)"></pre>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Profit Badge -->
                                                                    <div x-show="event.details && event.details.profit"
                                                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-green-500/10 border border-green-500/20 mt-1">
                                                                        <span class="text-xs text-green-400">سود:</span>
                                                                        <span class="text-xs text-green-400 en-font" x-text="formatPrice(event.details.profit) + ' تومان'"></span>
                                                                    </div>
                                                                </div>

                                                                <!-- Timestamp -->
                                                                <div class="text-xs text-gray-600 en-font flex-shrink-0"
                                                                    x-text="new Date(event.time_iso).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                    </div>
                </template>
            </div>

        </div>
    </div>

    @push('scripts')
    <script>
        function botMonitoring() {
            return {
                bots: [],
                loading: true,

                init() {
                    this.fetchData();
                    setInterval(() => this.fetchData(), 30000);
                },

                async fetchData() {
                    try {
                        const data = @json($this->getBotData());
                        this.bots = data;
                        this.loading = false;
                    } catch (error) {
                        console.error('Error:', error);
                    }
                },

                getSortedOrders(orders) {
                    return orders.sort((a, b) => b.price - a.price);
                },

                formatDuration(minutes) {
                    if (!minutes || minutes === 0) return '0 دقیقه';
                    if (minutes < 60) return Math.round(minutes) + ' دقیقه';
                    if (minutes < 1440) return (minutes / 60).toFixed(1) + ' ساعت';
                    return (minutes / 1440).toFixed(1) + ' روز';
                },

                formatPrice(price) {
                    if (!price) return '0';
                    const priceInt = parseInt(price);

                    if (priceInt >= 10000000) {
                        return (priceInt / 10000000).toFixed(1) + 'M';
                    }

                    return priceInt.toLocaleString('en-US');
                },

                formatTimeAgo(dateString) {
                    const date = new Date(dateString);
                    const now = new Date();
                    const seconds = Math.floor((now - date) / 1000);

                    if (seconds < 60) return 'چند لحظه پیش';
                    if (seconds < 3600) return Math.floor(seconds / 60) + ' دقیقه پیش';
                    if (seconds < 86400) return Math.floor(seconds / 3600) + ' ساعت پیش';
                    if (seconds < 604800) return Math.floor(seconds / 86400) + ' روز پیش';

                    return date.toLocaleDateString('fa-IR', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            }
        }

        function activityLog() {
            return {
                activeFilter: 'all',

                getFilteredCycles(cycles) {
                    if (!cycles) return [];

                    if (this.activeFilter === 'errors') {
                        return cycles.filter(cycle => cycle.summary.errors > 0);
                    } else if (this.activeFilter === 'api') {
                        // Show only cycles with API calls, and only show the API events
                        return cycles.filter(cycle => cycle.summary.api_calls > 0);
                    }

                    return cycles;
                },

                getErrorCyclesCount(cycles) {
                    if (!cycles) return 0;
                    return cycles.filter(cycle => cycle.summary.errors > 0).length;
                },

                getApiCallsCount(cycles) {
                    if (!cycles) return 0;
                    return cycles.reduce((total, cycle) => total + cycle.summary.api_calls, 0);
                },

                formatCycleDuration(durationMs) {
                    if (!durationMs || durationMs === 0) return '0s';

                    const seconds = durationMs / 1000;

                    if (seconds < 1) {
                        return durationMs.toFixed(0) + 'ms';
                    } else if (seconds < 60) {
                        return seconds.toFixed(1) + 's';
                    } else if (seconds < 3600) {
                        return (seconds / 60).toFixed(1) + 'm';
                    }

                    return (seconds / 3600).toFixed(1) + 'h';
                },

                formatTimeAgo(dateString) {
                    const date = new Date(dateString);
                    const now = new Date();
                    const seconds = Math.floor((now - date) / 1000);

                    if (seconds < 60) return 'چند لحظه پیش';
                    if (seconds < 3600) return Math.floor(seconds / 60) + ' دقیقه پیش';
                    if (seconds < 86400) return Math.floor(seconds / 3600) + ' ساعت پیش';
                    if (seconds < 604800) return Math.floor(seconds / 86400) + ' روز پیش';

                    return date.toLocaleDateString('fa-IR', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                },

                formatPrice(price) {
                    if (!price) return '0';
                    const priceInt = parseInt(price);

                    if (priceInt >= 10000000) {
                        return (priceInt / 10000000).toFixed(1) + 'M';
                    } else if (priceInt >= 1000000) {
                        return (priceInt / 1000000).toFixed(1) + 'M';
                    } else if (priceInt >= 1000) {
                        return (priceInt / 1000).toFixed(1) + 'K';
                    }

                    return priceInt.toLocaleString('en-US');
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
