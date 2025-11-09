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

                        <!-- Activity Timeline -->
                        <div class="glass-card rounded-xl p-6">
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center gap-2">
                                    <span class="text-2xl">📋</span>
                                    <h3 class="text-lg font-bold text-white">گزارش فعالیت‌ها</h3>
                                </div>
                                <div class="text-xs text-gray-500">آخرین 100 گزارش</div>
                            </div>

                            <div x-show="!bot.activity_logs || bot.activity_logs.length === 0" class="text-center py-12">
                                <div class="text-6xl mb-4">📝</div>
                                <div class="text-gray-400 mb-2">هنوز فعالیتی ثبت نشده</div>
                                <div class="text-xs text-gray-600">لاگ‌ها پس از اجرای اولین بررسی نمایش داده می‌شوند</div>
                            </div>

                            <div x-show="bot.activity_logs && bot.activity_logs.length > 0" class="space-y-2 max-h-96 overflow-y-auto custom-scrollbar">
                                <template x-for="log in bot.activity_logs" :key="log.id">
                                    <div class="flex items-start gap-3 p-3 glass-card rounded-lg hover:bg-gray-800/50 transition-colors" x-data="{expanded: false}">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-lg"
                                            :class="{
                                                'bg-blue-500/20': log.action_type.includes('CHECK'),
                                                'bg-green-500/20': log.level === 'SUCCESS',
                                                'bg-yellow-500/20': log.action_type.includes('API'),
                                                'bg-red-500/20': log.level === 'ERROR',
                                                'bg-purple-500/20': log.action_type.includes('PRICE')
                                            }">
                                            <span x-text="{
                                                'CHECK_TRADES_START': '🔍',
                                                'CHECK_TRADES_END': '✅',
                                                'API_CALL': '📡',
                                                'ORDER_PLACED': '📝',
                                                'ORDER_FILLED': '🎯',
                                                'PRICE_CHECK': '📊',
                                                'GRID_ADJUST': '🔧',
                                                'ERROR': '❌'
                                            }[log.action_type] || '📌'"></span>
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="flex-1">
                                                    <div class="text-sm text-white break-words" x-text="log.message"></div>

                                                    <template x-if="log.action_type === 'API_CALL' && log.execution_time">
                                                        <div class="text-xs text-gray-400 mt-1">
                                                            <span>زمان پاسخ: </span>
                                                            <span class="text-green-400 en-font" x-text="log.execution_time + 'ms'"></span>
                                                        </div>
                                                    </template>

                                                    <template x-if="log.action_type === 'PRICE_CHECK' && log.details">
                                                        <div class="text-xs text-gray-400 mt-1">
                                                            <span x-show="log.details.current_price">
                                                                قیمت فعلی: <span class="text-yellow-400 en-font" x-text="formatPrice(log.details.current_price)"></span>
                                                            </span>
                                                            <span x-show="log.details.waiting_for" class="mr-2 text-blue-400" x-text="log.details.waiting_for"></span>
                                                        </div>
                                                    </template>

                                                    <template x-if="log.details && log.details.profit">
                                                        <div class="text-xs text-green-400 mt-1 en-font">
                                                            <span>سود: </span>
                                                            <span x-text="formatPrice(log.details.profit) + ' تومان'"></span>
                                                        </div>
                                                    </template>

                                                    <div x-show="log.api_response">
                                                        <button @click="expanded = !expanded"
                                                            class="text-xs text-blue-400 hover:text-blue-300 mt-1 flex items-center gap-1">
                                                            <span x-text="expanded ? '▼' : '▶'"></span>
                                                            <span>جزئیات API</span>
                                                        </button>
                                                        <div x-show="expanded" x-collapse class="mt-2 p-2 bg-gray-900/50 rounded text-xs overflow-auto">
                                                            <pre class="text-gray-400 en-font text-xs" x-text="JSON.stringify(log.api_response, null, 2)"></pre>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="text-xs text-gray-500 en-font flex-shrink-0"
                                                    x-text="formatTimeAgo(log.created_at)"></div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="mt-4 flex items-center justify-between text-xs text-gray-500">
                                <span>به‌روزرسانی خودکار هر 30 ثانیه</span>
                                <div class="flex items-center gap-1">
                                    <div class="w-1 h-1 bg-green-500 rounded-full animate-ping"></div>
                                    <span>زنده</span>
                                </div>
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
    </script>
    @endpush
</x-filament-panels::page>
