<x-filament-panels::page>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=JetBrains+Mono:wght@400;700&display=swap');

        * {
            font-family: 'Inter', sans-serif;
        }

        .terminal-font {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
        }

        .flat-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .flat-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        @keyframes countUp {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .pulse-dot {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .chart-dot {
            transition: all 0.3s ease;
        }

        .chart-dot:hover {
            transform: scale(1.5);
        }

        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
        }
    </style>

    <div x-data="botMonitoring()" x-init="init()" class="min-h-screen space-y-8">

        <!-- Header -->
        <div class="flat-card rounded-2xl p-6 animate-fade-in-up" style="animation-delay: 0.1s">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-3 h-3 bg-green-500 rounded-full pulse-dot absolute"></div>
                        <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Grid Trading Monitor</h1>
                        <p class="text-sm text-gray-400 terminal-font">Real-time bot performance tracking</p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">System Time</div>
                    <div class="terminal-font text-sm text-gray-300" x-text="new Date().toLocaleString('fa-IR')"></div>
                </div>
            </div>
        </div>

        <!-- Loading -->
        <div x-show="loading" class="flex items-center justify-center py-20">
            <div class="relative">
                <div class="w-16 h-16 border-4 border-blue-500/20 border-t-blue-500 rounded-full animate-spin"></div>
            </div>
        </div>

        <!-- Bots -->
        <div x-show="!loading" class="space-y-8">
            <template x-for="(bot, botIndex) in bots" :key="bot.id">
                <div class="space-y-6" :style="'animation-delay: ' + ((botIndex + 2) * 0.1) + 's'" class="animate-fade-in-up">

                    <!-- Bot Header -->
                    <div class="flat-card rounded-2xl p-8">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-3xl font-bold text-white mb-2" x-text="bot.name"></h2>
                                <div class="flex items-center gap-4 text-sm text-gray-400">
                                    <span class="terminal-font" x-text="bot.symbol"></span>
                                    <span>•</span>
                                    <span x-text="bot.grid_levels + ' Levels'"></span>
                                    <span>•</span>
                                    <span x-text="(bot.grid_spacing * 100).toFixed(1) + '% Spacing'"></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full"
                                    :class="bot.status === 'active' ? 'bg-green-500/10 text-green-400' : 'bg-gray-500/10 text-gray-400'">
                                    <div class="w-2 h-2 rounded-full"
                                        :class="bot.status === 'active' ? 'bg-green-400' : 'bg-gray-400'"></div>
                                    <span class="text-sm font-semibold" x-text="bot.status === 'active' ? 'Active' : 'Inactive'"></span>
                                </div>
                                <div class="text-xs text-gray-500 mt-2" x-text="'Last check: ' + (bot.last_check_at || 'Never')"></div>
                            </div>
                        </div>

                        <!-- Stats Grid -->
                        <div class="grid grid-cols-4 gap-6">

                            <!-- Capital -->
                            <div class="flat-card rounded-xl p-6 group">
                                <div class="text-xs text-gray-400 mb-2">CAPITAL</div>
                                <div class="text-3xl font-bold text-white mb-1 terminal-font"
                                    x-text="(bot.capital / 10000000).toFixed(1) + 'M'"></div>
                                <div class="text-xs text-gray-500">تومان</div>
                            </div>

                            <!-- Active Orders -->
                            <div class="flat-card rounded-xl p-6 group">
                                <div class="text-xs text-gray-400 mb-2">ACTIVE ORDERS</div>
                                <div class="text-3xl font-bold text-white mb-1"
                                    x-text="bot.active_orders.length"></div>
                                <div class="text-xs text-gray-500">Waiting</div>
                            </div>

                            <!-- Trades 24h -->
                            <div class="flat-card rounded-xl p-6 group">
                                <div class="text-xs text-gray-400 mb-2">TRADES (24H)</div>
                                <div class="text-3xl font-bold text-white mb-1"
                                    x-text="bot.completed_trades_24h"></div>
                                <div class="text-xs text-gray-500">Completed</div>
                            </div>

                            <!-- Profit 24h -->
                            <div class="flat-card rounded-xl p-6 group">
                                <div class="text-xs text-gray-400 mb-2">PROFIT (24H)</div>
                                <div class="flex items-baseline gap-2">
                                    <div class="text-3xl font-bold text-white terminal-font"
                                        x-text="(bot.profit_24h / 1000).toFixed(0) + 'K'"></div>
                                    <div class="text-sm font-semibold"
                                        :class="bot.profit_change_24h >= 0 ? 'text-green-400' : 'text-red-400'"
                                        x-text="(bot.profit_change_24h >= 0 ? '↑' : '↓') + Math.abs(bot.profit_change_24h).toFixed(1) + '%'"></div>
                                </div>
                                <div class="text-xs text-gray-500">vs Previous 24h</div>
                            </div>

                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="grid grid-cols-2 gap-6">

                        <!-- Profit Timeline Chart -->
                        <div class="flat-card rounded-2xl p-6">
                            <h3 class="text-lg font-semibold text-white mb-6">Profit Timeline (30 Days)</h3>
                            <div class="h-64 flex items-end justify-between gap-1">
                                <template x-for="(day, index) in bot.daily_profits" :key="index">
                                    <div class="flex-1 flex flex-col items-center justify-end group cursor-pointer">
                                        <div class="relative w-full">
                                            <div
                                                class="w-full rounded-t transition-all duration-300"
                                                :class="day.profit > 0 ? 'bg-green-500/30 group-hover:bg-green-500/50' : 'bg-gray-700/30'"
                                                :style="'height: ' + Math.max((day.profit / getMaxProfit(bot.daily_profits)) * 200, 4) + 'px'">
                                            </div>
                                            <div
                                                class="chart-dot absolute -top-2 left-1/2 transform -translate-x-1/2 w-2 h-2 rounded-full"
                                                :class="day.profit > 0 ? 'bg-green-400' : 'bg-gray-600'">
                                            </div>
                                        </div>
                                        <div class="text-xs text-gray-600 mt-2 opacity-0 group-hover:opacity-100 transition-opacity"
                                            x-text="new Date(day.date).getDate()"></div>
                                    </div>
                                </template>
                            </div>
                            <div class="mt-4 flex items-center justify-between text-xs text-gray-500">
                                <span>30 days ago</span>
                                <span>Today</span>
                            </div>
                        </div>

                        <!-- Fill Time Distribution -->
                        <div class="flat-card rounded-2xl p-6">
                            <h3 class="text-lg font-semibold text-white mb-6">Order Fill Distribution (24h)</h3>
                            <div class="h-64 flex items-end justify-between gap-1">
                                <template x-for="hour in bot.fill_distribution" :key="hour.hour">
                                    <div class="flex-1 flex flex-col items-center justify-end group cursor-pointer">
                                        <div
                                            class="w-full rounded-t transition-all duration-300"
                                            :class="hour.count > 0 ? 'bg-blue-500/30 group-hover:bg-blue-500/50' : 'bg-gray-700/20'"
                                            :style="'height: ' + Math.max((hour.count / getMaxFills(bot.fill_distribution)) * 200, 4) + 'px'">
                                        </div>
                                        <div class="text-xs text-gray-600 mt-2"
                                            x-show="hour.hour % 4 === 0"
                                            x-text="hour.hour + 'h'"></div>
                                    </div>
                                </template>
                            </div>
                            <div class="mt-4 flex items-center justify-between text-xs text-gray-500">
                                <span>Midnight</span>
                                <span>Noon</span>
                                <span>Midnight</span>
                            </div>
                        </div>

                    </div>

                    <!-- Performance Metrics -->
                    <div class="grid grid-cols-3 gap-6">

                        <!-- Avg Cycle Duration -->
                        <div class="flat-card rounded-xl p-6">
                            <div class="text-sm text-gray-400 mb-3">Avg Cycle Duration</div>
                            <div class="flex items-baseline gap-2">
                                <div class="text-4xl font-bold text-white terminal-font"
                                    x-text="formatDuration(bot.avg_cycle_duration)"></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-2">Per complete trade</div>
                        </div>

                        <!-- Total Cycles -->
                        <div class="flat-card rounded-xl p-6">
                            <div class="text-sm text-gray-400 mb-3">Total Cycles</div>
                            <div class="text-4xl font-bold text-white" x-text="bot.total_cycles"></div>
                            <div class="text-xs text-gray-500 mt-2">All time</div>
                        </div>

                        <!-- Success Rate -->
                        <div class="flat-card rounded-xl p-6">
                            <div class="text-sm text-gray-400 mb-3">Success Rate</div>
                            <div class="flex items-baseline gap-2">
                                <div class="text-4xl font-bold text-green-400"
                                    x-text="bot.total_cycles > 0 ? '100' : '0'"></div>
                                <div class="text-xl text-green-400">%</div>
                            </div>
                            <div class="text-xs text-gray-500 mt-2">Grid strategy</div>
                        </div>

                    </div>

                    <!-- Order Book -->
                    <div class="flat-card rounded-2xl p-6">
                        <h3 class="text-lg font-semibold text-white mb-6">Order Book</h3>
                        <div class="space-y-2">
                            <template x-for="order in getSortedOrders(bot.active_orders)" :key="order.id">
                                <div class="flat-card rounded-lg p-4 group hover:scale-[1.01] transition-transform">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-4 flex-1">
                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center"
                                                :class="order.type === 'buy' ? 'bg-green-500/20' : 'bg-red-500/20'">
                                                <span x-text="order.type === 'buy' ? '🟢' : '🔴'"></span>
                                            </div>
                                            <div class="flex-1">
                                                <div class="terminal-font text-xl font-bold text-white"
                                                    x-text="(order.price / 10000000).toFixed(0) + 'M'"></div>
                                                <div class="text-xs text-gray-500"
                                                    x-text="order.amount + ' BTC'"></div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <div class="text-xs px-3 py-1 rounded-full"
                                                :class="order.paired_order_id ? 'bg-blue-500/20 text-blue-300' : 'bg-gray-700 text-gray-400'"
                                                x-text="order.paired_order_id ? 'Paired' : 'Waiting'"></div>
                                            <div class="text-sm font-semibold"
                                                :class="order.type === 'buy' ? 'text-green-400' : 'text-red-400'"
                                                x-text="order.type.toUpperCase()"></div>
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

                getMaxProfit(dailyProfits) {
                    return Math.max(...dailyProfits.map(d => d.profit), 1);
                },

                getMaxFills(distribution) {
                    return Math.max(...distribution.map(d => d.count), 1);
                },

                formatDuration(minutes) {
                    if (minutes < 60) return Math.round(minutes) + 'm';
                    if (minutes < 1440) return (minutes / 60).toFixed(1) + 'h';
                    return (minutes / 1440).toFixed(1) + 'd';
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
