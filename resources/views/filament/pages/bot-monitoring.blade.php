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

        /* KPI Card Structure */
        .kpi-card {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            transition: transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3), 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .kpi-main {
            margin: 0.25rem 0;
        }

        .kpi-value {
            font-size: 1.375rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .kpi-sub-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: auto;
        }

        .kpi-subtext {
            font-size: 0.6875rem;
            line-height: 1.3;
            color: rgb(107, 114, 128);
            flex-shrink: 0;
        }

        .kpi-sparkline {
            height: 24px;
            width: 60px;
            flex-shrink: 0;
            overflow: hidden;
            opacity: 0.25;
        }

        /* Filter Chips - Refined */
        .filter-chip {
            height: 36px;
            padding: 0 1rem;
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            font-weight: 600;
            transition: transform 150ms ease, border-color 150ms ease, background-color 150ms ease;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .filter-chip:hover {
            transform: translateY(-1px);
        }

        /* Cycle Card Hover */
        .cycle-card-header {
            transition: background-color 150ms ease, transform 150ms ease;
        }

        .cycle-card-header:hover {
            background-color: rgba(31, 41, 55, 0.3);
            transform: translateY(-1px);
        }

        /* KPI Strip Container */
        .kpi-strip {
            background: rgba(10, 15, 25, 0.5);
            border-radius: 1.25rem;
            padding: 1.25rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 1.5rem;
        }

        .kpi-strip-label {
            font-size: 0.6875rem;
            color: rgb(156, 163, 175);
            margin-bottom: 1rem;
            font-weight: 500;
            letter-spacing: 0.025em;
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

                            <!-- Debug Info Section (Collapsible) -->
                            <div class="mt-6" x-data="{ debugOpen: false }">
                                <button @click="debugOpen = !debugOpen" class="w-full glass-card rounded-lg p-4 hover:bg-gray-800/40 transition-all text-left">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-yellow-400">🔍</span>
                                            <span class="text-sm font-semibold text-yellow-400">اطلاعات Debug (برای بررسی)</span>
                                        </div>
                                        <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ 'rotate-180': debugOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </button>

                                <div x-show="debugOpen" x-collapse class="mt-3 glass-card rounded-lg p-5">
                                    <div class="grid grid-cols-2 gap-4 text-sm">
                                        <div class="bg-gray-800/50 rounded-lg p-3">
                                            <div class="text-xs text-gray-400 mb-1">تعداد کل Orders</div>
                                            <div class="text-xl font-bold text-white" x-text="bot.debug.total_orders"></div>
                                        </div>
                                        <div class="bg-gray-800/50 rounded-lg p-3">
                                            <div class="text-xs text-gray-400 mb-1">Orders با status=active/placed</div>
                                            <div class="text-xl font-bold text-white" x-text="bot.debug.total_with_status_active"></div>
                                        </div>
                                        <div class="bg-gray-800/50 rounded-lg p-3">
                                            <div class="text-xs text-gray-400 mb-1">Orders که fill نشده (filled_at=null)</div>
                                            <div class="text-xl font-bold text-white" x-text="bot.debug.total_not_executed"></div>
                                        </div>
                                        <div class="bg-gray-800/50 rounded-lg p-3">
                                            <div class="text-xs text-gray-400 mb-1">Orders که pair نشده (paired_order_id=null)</div>
                                            <div class="text-xl font-bold text-white" x-text="bot.debug.total_not_paired"></div>
                                        </div>
                                        <div class="bg-gray-800/50 rounded-lg p-3">
                                            <div class="text-xs text-gray-400 mb-1">Orders که fill شده (status=filled)</div>
                                            <div class="text-xl font-bold text-white" x-text="bot.debug.total_filled"></div>
                                        </div>
                                        <div class="bg-green-900/30 border border-green-500/30 rounded-lg p-3">
                                            <div class="text-xs text-green-400 mb-1">کل Completed Trades</div>
                                            <div class="text-xl font-bold text-green-400" x-text="bot.debug.completed_trades_total"></div>
                                        </div>
                                        <div class="bg-green-900/30 border border-green-500/30 rounded-lg p-3">
                                            <div class="text-xs text-green-400 mb-1">Completed Trades (24h)</div>
                                            <div class="text-xl font-bold text-green-400" x-text="bot.debug.completed_trades_24h_actual"></div>
                                        </div>
                                        <div class="bg-green-900/30 border border-green-500/30 rounded-lg p-3">
                                            <div class="text-xs text-green-400 mb-1">سود کل (تومان)</div>
                                            <div class="text-lg font-bold text-green-400 en-font" x-text="(bot.debug.profit_total / 1000).toFixed(0) + 'K'"></div>
                                        </div>
                                        <div class="bg-blue-900/30 border border-blue-500/30 rounded-lg p-3 col-span-2">
                                            <div class="text-xs text-blue-400 mb-1">سود 24 ساعت (واقعی - تومان)</div>
                                            <div class="text-2xl font-bold text-blue-400 en-font" x-text="(bot.debug.profit_24h_actual / 1000).toFixed(0) + 'K'"></div>
                                        </div>
                                    </div>

                                    <div class="mt-4 p-3 bg-yellow-900/20 border border-yellow-500/30 rounded-lg">
                                        <div class="flex items-start gap-2">
                                            <span class="text-yellow-400">💡</span>
                                            <div class="text-xs text-yellow-200">
                                                <strong>توضیحات:</strong><br>
                                                • سفارشات فعال = Orders با status=active/placed که filled_at=null و paired_order_id=null<br>
                                                • معاملات تکمیل شده از جدول completed_trades خوانده می‌شود<br>
                                                • اگر اعداد بالا با اعداد نمایش داده شده در کارت‌ها تفاوت دارند، ممکن است مشکلی در محاسبات باشد
                                            </div>
                                        </div>
                                    </div>
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

                        <!-- Activity Log - Apple-level Redesign -->
                        <div class="glass-card rounded-2xl p-6 shadow-2xl border-white/5" x-data="activityLog()">
                            <!-- Header Section -->
                            <div class="mb-6">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500/10 to-purple-500/10 rounded-xl flex items-center justify-center border border-white/5">
                                            <span class="text-2xl">📊</span>
                                        </div>
                                        <div>
                                            <h3 class="text-xl font-bold text-white mb-0.5">گزارش فعالیت‌ها</h3>
                                            <p class="text-xs text-gray-400">۱۰۰ رویداد آخر · به‌روزرسانی خودکار هر ۳۰ ثانیه</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-500/10 border border-green-500/20">
                                        <div class="w-1.5 h-1.5 bg-green-400 rounded-full pulse-slow shadow-lg shadow-green-500/50"></div>
                                        <span class="text-xs font-medium text-green-400">زنده</span>
                                    </div>
                                </div>
                            </div>

                            <!-- KPI Strip - 4 Premium Cards with Clear Structure -->
                            <div x-show="bot.activity_summary && bot.activity_summary.last_cycle_status" class="kpi-strip">
                                <div class="kpi-strip-label">گزارش فعالیت‌ها · ۱۰۰ رویداد آخر · به‌روزرسانی خودکار هر ۳۰ ثانیه</div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                                    <!-- Card 1: Last Cycle Status -->
                                    <div class="kpi-card group relative overflow-hidden rounded-xl bg-gradient-to-br from-gray-800/60 to-gray-900/60 p-4 border border-white/5 hover:border-white/10">
                                        <div class="kpi-header">
                                            <span class="text-xs font-medium text-gray-400">وضعیت آخرین چرخه</span>
                                            <div class="px-2 py-0.5 rounded-md text-xs font-bold"
                                                :class="{
                                                    'bg-green-500/20 text-green-400': bot.activity_summary.last_cycle_status === 'success',
                                                    'bg-yellow-500/20 text-yellow-400': bot.activity_summary.last_cycle_status === 'warning',
                                                    'bg-red-500/20 text-red-400': bot.activity_summary.last_cycle_status === 'error',
                                                    'bg-blue-500/20 text-blue-400': bot.activity_summary.last_cycle_status === 'in_progress'
                                                }"
                                                x-text="{
                                                    'success': '✓ موفق',
                                                    'warning': '⚠ هشدار',
                                                    'error': '✗ خطا',
                                                    'in_progress': '⟳ در حال اجرا'
                                                }[bot.activity_summary.last_cycle_status]"></div>
                                        </div>

                                        <div class="kpi-main">
                                            <div class="kpi-value"
                                                :class="{
                                                    'text-green-400': bot.activity_summary.last_cycle_status === 'success',
                                                    'text-yellow-400': bot.activity_summary.last_cycle_status === 'warning',
                                                    'text-red-400': bot.activity_summary.last_cycle_status === 'error',
                                                    'text-blue-400': bot.activity_summary.last_cycle_status === 'in_progress'
                                                }"
                                                x-text="{
                                                    'success': 'موفق',
                                                    'warning': 'هشدار',
                                                    'error': 'ناموفق',
                                                    'in_progress': 'در حال اجرا'
                                                }[bot.activity_summary.last_cycle_status] || '-'"></div>
                                        </div>

                                        <div class="kpi-sub-row">
                                            <span class="kpi-subtext" x-show="bot.activity_summary.last_cycle_time" x-text="formatTimeAgo(bot.activity_summary.last_cycle_time)"></span>
                                        </div>
                                    </div>

                                    <!-- Card 2: Average Cycle Duration -->
                                    <div class="kpi-card group relative overflow-hidden rounded-xl bg-gradient-to-br from-gray-800/60 to-gray-900/60 p-4 border border-white/5 hover:border-white/10">
                                        <div class="kpi-header">
                                            <span class="text-xs font-medium text-gray-400">میانگین زمان چرخه</span>
                                            <div class="w-6 h-6 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                                <span class="text-sm">⚡</span>
                                            </div>
                                        </div>

                                        <div class="kpi-main">
                                            <div class="kpi-value text-white en-font" x-text="formatCycleDuration(bot.activity_summary.avg_cycle_duration)"></div>
                                        </div>

                                        <div class="kpi-sub-row">
                                            <span class="kpi-subtext">میانگین ۲۴ ساعت گذشته</span>
                                            <div class="kpi-sparkline">
                                                <svg class="w-full h-full" viewBox="0 0 100 50" preserveAspectRatio="none">
                                                    <path d="M0,40 L20,35 L40,38 L60,30 L80,32 L100,28" fill="none" stroke="currentColor" stroke-width="2" class="text-blue-400"/>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Card 3: Average API Response Time -->
                                    <div class="kpi-card group relative overflow-hidden rounded-xl bg-gradient-to-br from-gray-800/60 to-gray-900/60 p-4 border border-white/5 hover:border-white/10">
                                        <div class="kpi-header">
                                            <span class="text-xs font-medium text-gray-400">میانگین پاسخ نوبیتکس</span>
                                            <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                                                :class="bot.activity_summary.avg_api_latency > 1000 ? 'bg-yellow-500/20' : 'bg-green-500/20'">
                                                <span class="text-sm">📡</span>
                                            </div>
                                        </div>

                                        <div class="kpi-main">
                                            <div class="kpi-value en-font"
                                                :class="bot.activity_summary.avg_api_latency > 1000 ? 'text-yellow-400' : 'text-green-400'"
                                                x-text="bot.activity_summary.avg_api_latency.toFixed(0) + 'ms'"></div>
                                        </div>

                                        <div class="kpi-sub-row">
                                            <span class="kpi-subtext">براساس فراخوانی‌های API</span>
                                            <div class="kpi-sparkline">
                                                <svg class="w-full h-full" viewBox="0 0 100 50" preserveAspectRatio="none">
                                                    <path d="M0,35 L20,32 L40,36 L60,28 L80,30 L100,25" fill="none" stroke="currentColor" stroke-width="2"
                                                        :class="bot.activity_summary.avg_api_latency > 1000 ? 'text-yellow-400' : 'text-green-400'"/>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Card 4: Cycles in Last 24h -->
                                    <div class="kpi-card group relative overflow-hidden rounded-xl bg-gradient-to-br from-gray-800/60 to-gray-900/60 p-4 border border-white/5 hover:border-white/10">
                                        <div class="kpi-header">
                                            <span class="text-xs font-medium text-gray-400">چرخه‌ها (۲۴ ساعت)</span>
                                            <div class="w-6 h-6 bg-purple-500/20 rounded-lg flex items-center justify-center">
                                                <span class="text-sm">🔄</span>
                                            </div>
                                        </div>

                                        <div class="kpi-main">
                                            <div class="kpi-value text-white" x-text="bot.activity_summary.cycles_count_24h"></div>
                                        </div>

                                        <div class="kpi-sub-row">
                                            <span class="kpi-subtext">تعداد اجراهای CheckTradesJob</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Filter Bar - Refined Chips -->
                            <div x-show="bot.activity_cycles && bot.activity_cycles.length > 0" class="mb-6">
                                <div class="flex items-center gap-2 overflow-x-auto pb-1">
                                    <button @click="activeFilter = 'all'"
                                        class="filter-chip"
                                        :class="activeFilter === 'all'
                                            ? 'bg-blue-500/20 text-blue-300 border-2 border-blue-500/40'
                                            : 'bg-gray-800/40 text-gray-400 border border-gray-700/40 hover:bg-gray-700/60 hover:border-gray-600/50'">
                                        همه لاگ‌ها
                                        <span class="text-xs opacity-60" x-text="'(' + bot.activity_cycles.length + ')'"></span>
                                    </button>
                                    <button @click="activeFilter = 'errors'"
                                        class="filter-chip"
                                        :class="activeFilter === 'errors'
                                            ? 'bg-red-500/20 text-red-300 border-2 border-red-500/40'
                                            : 'bg-gray-800/40 text-gray-400 border border-gray-700/40 hover:bg-gray-700/60 hover:border-gray-600/50'">
                                        فقط خطاها
                                        <span class="text-xs opacity-60" x-text="'(' + getErrorCyclesCount(bot.activity_cycles) + ')'"></span>
                                    </button>
                                    <button @click="activeFilter = 'api'"
                                        class="filter-chip"
                                        :class="activeFilter === 'api'
                                            ? 'bg-yellow-500/20 text-yellow-300 border-2 border-yellow-500/40'
                                            : 'bg-gray-800/40 text-gray-400 border border-gray-700/40 hover:bg-gray-700/60 hover:border-gray-600/50'">
                                        فراخوانی‌های API
                                        <span class="text-xs opacity-60" x-text="'(' + getApiCallsCount(bot.activity_cycles) + ')'"></span>
                                    </button>
                                    <button @click="activeFilter = 'cycles'"
                                        class="filter-chip"
                                        :class="activeFilter === 'cycles'
                                            ? 'bg-purple-500/20 text-purple-300 border-2 border-purple-500/40'
                                            : 'bg-gray-800/40 text-gray-400 border border-gray-700/40 hover:bg-gray-700/60 hover:border-gray-600/50'">
                                        چرخه‌ها
                                        <span class="text-xs opacity-60" x-text="'(' + bot.activity_cycles.filter(c => c.status !== 'ungrouped').length + ')'"></span>
                                    </button>
                                </div>
                            </div>

                            <!-- Empty State -->
                            <div x-show="!bot.activity_cycles || bot.activity_cycles.length === 0" class="text-center py-20">
                                <div class="w-28 h-28 mx-auto mb-8 bg-gradient-to-br from-gray-800/40 to-gray-900/40 rounded-3xl flex items-center justify-center border border-white/5 shadow-inner">
                                    <span class="text-6xl opacity-40">📝</span>
                                </div>
                                <div class="text-xl text-gray-300 mb-3 font-bold">هنوز فعالیتی ثبت نشده</div>
                                <div class="text-sm text-gray-500 max-w-md mx-auto">لاگ‌ها پس از اجرای اولین بررسی نمایش داده می‌شوند</div>
                            </div>

                            <!-- Cycles List - Polished Timeline -->
                            <div x-show="bot.activity_cycles && bot.activity_cycles.length > 0"
                                class="space-y-3 max-h-[700px] overflow-y-auto custom-scrollbar pr-2">
                                <template x-for="cycle in getFilteredCycles(bot.activity_cycles)" :key="cycle.id">
                                    <div class="group relative bg-gradient-to-br from-gray-800/30 to-gray-900/30 rounded-xl border border-white/5 overflow-hidden backdrop-blur-sm hover:border-white/10 transition-all duration-300 hover:shadow-lg"
                                        x-data="{ expanded: false }">

                                        <!-- Cycle Header (Clickable) -->
                                        <div @click="expanded = !expanded" class="cycle-card-header p-4 cursor-pointer">
                                            <div class="flex items-center justify-between gap-6">
                                                <!-- Left: Status & Info -->
                                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                                    <!-- Status Icon - Compact -->
                                                    <div class="relative w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                                                        :class="{
                                                            'bg-green-500/15': cycle.status === 'success',
                                                            'bg-yellow-500/15': cycle.status === 'warning',
                                                            'bg-red-500/15': cycle.status === 'error',
                                                            'bg-blue-500/15': cycle.status === 'in_progress',
                                                            'bg-gray-500/15': cycle.status === 'ungrouped'
                                                        }">
                                                        <span class="text-lg" x-text="{
                                                            'success': '✓',
                                                            'warning': '⚠',
                                                            'error': '✗',
                                                            'in_progress': '⟳',
                                                            'ungrouped': '•'
                                                        }[cycle.status]"></span>
                                                    </div>

                                                    <!-- Cycle Info - Compact -->
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="text-sm font-bold"
                                                                :class="{
                                                                    'text-green-400': cycle.status === 'success',
                                                                    'text-yellow-400': cycle.status === 'warning',
                                                                    'text-red-400': cycle.status === 'error',
                                                                    'text-blue-400': cycle.status === 'in_progress',
                                                                    'text-gray-400': cycle.status === 'ungrouped'
                                                                }"
                                                                x-text="{
                                                                    'success': 'چرخه بررسی ربات',
                                                                    'warning': 'چرخه با هشدار',
                                                                    'error': 'چرخه با خطا',
                                                                    'in_progress': 'چرخه در حال اجرا',
                                                                    'ungrouped': 'لاگ‌های متفرقه'
                                                                }[cycle.status]"></span>
                                                        </div>
                                                        <!-- Summary Pills Row - Compact -->
                                                        <div class="flex items-center gap-2 flex-wrap text-xs text-gray-400">
                                                            <span x-text="formatTimeAgo(cycle.started_at_iso)"></span>
                                                            <span x-show="cycle.duration_ms" class="text-gray-600">•</span>
                                                            <span x-show="cycle.duration_ms" class="px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-400 en-font" x-text="formatCycleDuration(cycle.duration_ms)"></span>
                                                            <span x-show="cycle.summary.orders_active > 0" class="text-gray-600">•</span>
                                                            <span x-show="cycle.summary.orders_active > 0" class="px-1.5 py-0.5 rounded bg-cyan-500/10 text-cyan-400" x-text="cycle.summary.orders_active + ' سفارش'"></span>
                                                            <span x-show="cycle.summary.api_calls > 0" class="text-gray-600">•</span>
                                                            <span x-show="cycle.summary.api_calls > 0" class="px-1.5 py-0.5 rounded bg-yellow-500/10 text-yellow-400" x-text="cycle.summary.api_calls + ' API'"></span>
                                                            <span x-show="cycle.summary.errors > 0" class="text-gray-600">•</span>
                                                            <span x-show="cycle.summary.errors > 0" class="px-1.5 py-0.5 rounded bg-red-500/10 text-red-400" x-text="cycle.summary.errors + ' خطا'"></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Right: Status Badge + Expand Icon -->
                                                <div class="flex items-center gap-3 flex-shrink-0">
                                                    <!-- Status Pill - Compact -->
                                                    <div class="px-3 py-1 rounded-lg border text-xs font-semibold whitespace-nowrap"
                                                        :class="{
                                                            'bg-green-500/15 border-green-500/30 text-green-400': cycle.status === 'success',
                                                            'bg-yellow-500/15 border-yellow-500/30 text-yellow-400': cycle.status === 'warning',
                                                            'bg-red-500/15 border-red-500/30 text-red-400': cycle.status === 'error',
                                                            'bg-blue-500/15 border-blue-500/30 text-blue-400': cycle.status === 'in_progress',
                                                            'bg-gray-500/15 border-gray-500/30 text-gray-400': cycle.status === 'ungrouped'
                                                        }"
                                                        x-text="{
                                                            'success': 'موفق',
                                                            'warning': 'هشدار',
                                                            'error': 'ناموفق',
                                                            'in_progress': 'در حال اجرا',
                                                            'ungrouped': 'متفرقه'
                                                        }[cycle.status]"></div>
                                                    <!-- Expand Icon - Compact -->
                                                    <div class="w-8 h-8 rounded-lg bg-gray-700/30 flex items-center justify-center group-hover:bg-gray-700/50 transition-all">
                                                        <svg class="w-4 h-4 text-gray-400 transition-transform duration-300"
                                                            :class="{ 'rotate-180': expanded }"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Cycle Timeline (Expandable) -->
                                        <div x-show="expanded"
                                            x-collapse
                                            class="px-5 pb-4 pt-2 border-t border-white/5 bg-black/10">

                                            <!-- Timeline -->
                                            <div class="space-y-3 mt-3">
                                                <template x-for="(event, idx) in cycle.events" :key="event.id">
                                                    <div class="flex gap-4" x-data="{ showApiDetails: false }">
                                                        <!-- Timeline Line -->
                                                        <div class="flex flex-col items-center flex-shrink-0">
                                                            <!-- Icon -->
                                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm shadow backdrop-blur-sm"
                                                                :class="{
                                                                    'bg-blue-500/20 shadow-blue-500/10': event.type.includes('CHECK'),
                                                                    'bg-green-500/20 shadow-green-500/10': event.level === 'SUCCESS',
                                                                    'bg-yellow-500/20 shadow-yellow-500/10': event.type === 'API_CALL',
                                                                    'bg-red-500/20 shadow-red-500/10': event.level === 'ERROR',
                                                                    'bg-purple-500/20 shadow-purple-500/10': event.type.includes('PRICE') || event.type === 'WAITING',
                                                                    'bg-cyan-500/20 shadow-cyan-500/10': event.type.includes('ORDER'),
                                                                    'bg-pink-500/20 shadow-pink-500/10': event.type.includes('TRADE')
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
                                                                class="w-0.5 h-8 bg-gradient-to-b from-gray-600/50 to-gray-700/20 mt-1.5 rounded-full"></div>
                                                        </div>

                                                        <!-- Event Content -->
                                                        <div class="flex-1 min-w-0 pb-2">
                                                            <div class="flex items-start justify-between gap-4">
                                                                <div class="flex-1 min-w-0">
                                                                    <!-- Type Badge -->
                                                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg mb-2 text-xs font-medium"
                                                                        :class="{
                                                                            'bg-blue-500/10 text-blue-400 border border-blue-500/20': event.type.includes('CHECK'),
                                                                            'bg-yellow-500/10 text-yellow-400 border border-yellow-500/20': event.type === 'API_CALL',
                                                                            'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20': event.type.includes('ORDER'),
                                                                            'bg-red-500/10 text-red-400 border border-red-500/20': event.level === 'ERROR',
                                                                            'bg-gray-500/10 text-gray-400 border border-gray-500/20': !['API_CALL', 'ERROR'].includes(event.type) && !event.type.includes('CHECK') && !event.type.includes('ORDER')
                                                                        }">
                                                                        <span x-text="{
                                                                            'API_CALL': 'API',
                                                                            'ORDERS_RECEIVED': 'ORDERS',
                                                                            'CHECK_TRADES_START': 'CYCLE',
                                                                            'CHECK_TRADES_END': 'CYCLE',
                                                                            'ERROR': 'ERROR'
                                                                        }[event.type] || 'EVENT'"></span>
                                                                    </div>

                                                                    <!-- Message -->
                                                                    <div class="text-sm text-gray-200 leading-relaxed mb-2 font-medium" x-text="event.message"></div>

                                                                    <!-- API Call Badge -->
                                                                    <div x-show="event.type === 'API_CALL' && event.execution_time"
                                                                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-yellow-500/10 border border-yellow-500/20 mt-2">
                                                                        <span class="text-xs text-gray-400">زمان پاسخ:</span>
                                                                        <span class="text-xs font-bold text-yellow-400 en-font" x-text="event.execution_time + 'ms'"></span>
                                                                        <span class="text-gray-600">•</span>
                                                                        <button @click="showApiDetails = !showApiDetails"
                                                                            class="text-xs text-yellow-400 hover:text-yellow-300 underline font-medium transition-colors">
                                                                            جزئیات API
                                                                        </button>
                                                                    </div>

                                                                    <!-- API Details (Collapsible) -->
                                                                    <div x-show="showApiDetails"
                                                                        x-collapse
                                                                        class="mt-4 bg-black/40 rounded-xl p-5 border border-gray-700/50 backdrop-blur-sm">
                                                                        <div class="space-y-4">
                                                                            <!-- Request -->
                                                                            <div x-show="event.api_request">
                                                                                <div class="flex items-center gap-2 mb-3">
                                                                                    <div class="w-1 h-4 bg-blue-500 rounded-full"></div>
                                                                                    <div class="text-xs font-bold text-gray-300">درخواست (Request)</div>
                                                                                </div>
                                                                                <pre class="text-xs text-gray-400 en-font overflow-x-auto custom-scrollbar p-3 bg-gray-900/50 rounded-lg border border-gray-700/30 leading-relaxed" x-text="JSON.stringify(event.api_request, null, 2)"></pre>
                                                                            </div>
                                                                            <!-- Response -->
                                                                            <div x-show="event.api_response">
                                                                                <div class="flex items-center gap-2 mb-3">
                                                                                    <div class="w-1 h-4 bg-green-500 rounded-full"></div>
                                                                                    <div class="text-xs font-bold text-gray-300">پاسخ (Response)</div>
                                                                                </div>
                                                                                <pre class="text-xs text-gray-400 en-font overflow-x-auto custom-scrollbar p-3 bg-gray-900/50 rounded-lg border border-gray-700/30 max-h-80 leading-relaxed" x-text="JSON.stringify(event.api_response, null, 2)"></pre>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Profit Badge -->
                                                                    <div x-show="event.details && event.details.profit"
                                                                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-500/10 border border-green-500/20 mt-2">
                                                                        <span class="text-xs text-gray-400">سود:</span>
                                                                        <span class="text-xs font-bold text-green-400 en-font" x-text="formatPrice(event.details.profit) + ' تومان'"></span>
                                                                    </div>
                                                                </div>

                                                                <!-- Timestamp -->
                                                                <div class="flex-shrink-0 px-2 py-1 rounded bg-gray-700/30">
                                                                    <div class="text-xs text-gray-400 en-font font-mono"
                                                                        x-text="new Date(event.time_iso).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></div>
                                                                </div>
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
                        return cycles.filter(cycle => cycle.summary.api_calls > 0);
                    } else if (this.activeFilter === 'cycles') {
                        return cycles.filter(cycle => cycle.status !== 'ungrouped');
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
                    if (!dateString) return '';

                    const date = new Date(dateString);
                    const now = new Date();
                    const seconds = Math.floor((now - date) / 1000);

                    if (seconds < 10) return 'چند لحظه پیش';
                    if (seconds < 60) return seconds + ' ثانیه پیش';
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
