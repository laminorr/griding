<x-filament-panels::page>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap');

        .terminal-font {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
        }

        .glow-green {
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.3), 0 0 40px rgba(34, 197, 94, 0.1);
        }

        .glow-red {
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.3), 0 0 40px rgba(239, 68, 68, 0.1);
        }

        .glass {
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .grid-bg {
            background-image:
                linear-gradient(rgba(59, 130, 246, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59, 130, 246, 0.1) 1px, transparent 1px);
            background-size: 50px 50px;
        }

        @keyframes scan {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(100%); }
        }

        .scan-line {
            animation: scan 3s linear infinite;
        }

        @keyframes pulse-glow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .pulse-glow {
            animation: pulse-glow 2s ease-in-out infinite;
        }
    </style>

    <div x-data="botMonitoring()" x-init="init()" class="min-h-screen">

        <!-- Terminal Header -->
        <div class="mb-6 glass rounded-lg p-4 border-l-4 border-cyan-500">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex gap-2">
                        <div class="w-3 h-3 bg-green-500 rounded-full pulse-glow"></div>
                        <div class="w-3 h-3 bg-yellow-500 rounded-full pulse-glow" style="animation-delay: 0.3s"></div>
                        <div class="w-3 h-3 bg-red-500 rounded-full pulse-glow" style="animation-delay: 0.6s"></div>
                    </div>
                    <span class="terminal-font text-green-400 text-sm">GRID_BOT_MONITOR_v1.0.0</span>
                </div>
                <div class="terminal-font text-xs text-gray-500">
                    <span x-text="new Date().toLocaleString('fa-IR')"></span>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div x-show="loading" class="flex items-center justify-center py-20">
            <div class="relative">
                <div class="w-20 h-20 border-4 border-cyan-500/30 border-t-cyan-500 rounded-full animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="terminal-font text-cyan-500 text-xs">LOADING</span>
                </div>
            </div>
        </div>

        <!-- Bots Grid -->
        <div x-show="!loading" class="space-y-8">
            <template x-for="bot in bots" :key="bot.id">
                <div class="relative overflow-hidden rounded-2xl">

                    <!-- Grid Background -->
                    <div class="absolute inset-0 grid-bg opacity-30"></div>

                    <!-- Scan Line Effect -->
                    <div class="absolute inset-0 pointer-events-none overflow-hidden">
                        <div class="scan-line absolute inset-x-0 h-px bg-gradient-to-r from-transparent via-cyan-500 to-transparent opacity-50"></div>
                    </div>

                    <!-- Main Content -->
                    <div class="relative glass p-8">

                        <!-- Bot Header -->
                        <div class="flex items-start justify-between mb-8 pb-6 border-b border-gray-700">
                            <div>
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="relative">
                                        <div class="w-3 h-3 bg-green-500 rounded-full animate-ping absolute"></div>
                                        <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                    </div>
                                    <h2 class="text-3xl font-bold text-white terminal-font" x-text="bot.name"></h2>
                                </div>
                                <div class="flex items-center gap-4 text-sm">
                                    <span class="terminal-font text-gray-400" x-text="'PAIR: ' + bot.symbol"></span>
                                    <span class="terminal-font text-cyan-400" x-text="'LEVELS: ' + bot.grid_levels"></span>
                                    <span class="terminal-font text-purple-400" x-text="'SPACING: ' + (bot.grid_spacing * 100) + '%'"></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="terminal-font text-xs text-gray-500 mb-1">LAST SYNC</div>
                                <div class="terminal-font text-green-400 text-sm" x-text="bot.last_check_at || 'INITIALIZING...'"></div>
                            </div>
                        </div>

                        <!-- Stats Dashboard -->
                        <div class="grid grid-cols-4 gap-4 mb-8">

                            <!-- Capital -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-br from-blue-500/20 to-cyan-500/20 rounded-xl blur group-hover:blur-xl transition-all"></div>
                                <div class="relative glass rounded-xl p-6 border border-blue-500/30 hover:border-blue-500/50 transition-all">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="terminal-font text-xs text-blue-400">CAPITAL</span>
                                        <span class="text-2xl">💎</span>
                                    </div>
                                    <div class="terminal-font text-3xl font-bold text-white mb-1"
                                        x-text="(bot.capital / 10000000).toFixed(1) + 'M'"></div>
                                    <div class="terminal-font text-xs text-gray-500">تومان</div>
                                </div>
                            </div>

                            <!-- Active Orders -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-br from-green-500/20 to-emerald-500/20 rounded-xl blur group-hover:blur-xl transition-all"></div>
                                <div class="relative glass rounded-xl p-6 border border-green-500/30 hover:border-green-500/50 transition-all">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="terminal-font text-xs text-green-400">ACTIVE</span>
                                        <span class="text-2xl">📊</span>
                                    </div>
                                    <div class="terminal-font text-3xl font-bold text-white mb-1"
                                        x-text="bot.active_orders.length"></div>
                                    <div class="terminal-font text-xs text-gray-500">ORDERS</div>
                                </div>
                            </div>

                            <!-- Completed -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-br from-purple-500/20 to-pink-500/20 rounded-xl blur group-hover:blur-xl transition-all"></div>
                                <div class="relative glass rounded-xl p-6 border border-purple-500/30 hover:border-purple-500/50 transition-all">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="terminal-font text-xs text-purple-400">TRADES 24H</span>
                                        <span class="text-2xl">✓</span>
                                    </div>
                                    <div class="terminal-font text-3xl font-bold text-white mb-1"
                                        x-text="bot.completed_trades_24h"></div>
                                    <div class="terminal-font text-xs text-gray-500">COMPLETED</div>
                                </div>
                            </div>

                            <!-- Profit -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-br from-yellow-500/20 to-orange-500/20 rounded-xl blur group-hover:blur-xl transition-all"></div>
                                <div class="relative glass rounded-xl p-6 border border-yellow-500/30 hover:border-yellow-500/50 transition-all">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="terminal-font text-xs text-yellow-400">PROFIT 24H</span>
                                        <span class="text-2xl">💰</span>
                                    </div>
                                    <div class="terminal-font text-3xl font-bold text-white mb-1"
                                        x-text="(bot.profit_24h / 1000).toFixed(0) + 'K'"></div>
                                    <div class="terminal-font text-xs text-gray-500">تومان</div>
                                </div>
                            </div>
                        </div>

                        <!-- Grid Visualization - Trading View Style -->
                        <div class="glass rounded-xl p-6 border border-gray-700 mb-6">
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="terminal-font text-lg text-white flex items-center gap-2">
                                    <span class="text-cyan-400">▓</span>
                                    ORDER BOOK
                                </h3>
                                <div class="flex gap-2">
                                    <span class="terminal-font text-xs px-2 py-1 rounded bg-red-500/20 text-red-400 border border-red-500/30">
                                        SELL
                                    </span>
                                    <span class="terminal-font text-xs px-2 py-1 rounded bg-green-500/20 text-green-400 border border-green-500/30">
                                        BUY
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <template x-for="(order, index) in getSortedOrders(bot.active_orders)" :key="order.id">
                                    <div class="group">
                                        <div
                                            class="relative flex items-center gap-4 p-4 rounded-lg transition-all duration-200 hover:scale-[1.01]"
                                            :class="order.type === 'sell' ? 'bg-red-500/5 hover:bg-red-500/10 border-l-2 border-red-500' : 'bg-green-500/5 hover:bg-green-500/10 border-l-2 border-green-500'"
                                        >
                                            <!-- Price Bar Background -->
                                            <div class="absolute inset-0 rounded-lg overflow-hidden">
                                                <div
                                                    class="h-full transition-all duration-500"
                                                    :class="order.type === 'sell' ? 'bg-red-500/10' : 'bg-green-500/10'"
                                                    :style="'width: ' + (order.paired_order_id ? '100%' : '60%')">
                                                </div>
                                            </div>

                                            <!-- Content -->
                                            <div class="relative flex-1 flex items-center gap-4">
                                                <div class="terminal-font text-2xl font-bold text-white flex-1"
                                                    x-text="(order.price / 10000000).toFixed(0) + 'M'"></div>

                                                <div class="terminal-font text-sm text-gray-400">
                                                    <span x-text="order.amount + ' BTC'"></span>
                                                </div>

                                                <div class="flex items-center gap-2">
                                                    <span class="text-xl" x-text="order.type === 'sell' ? '🔴' : '🟢'"></span>
                                                    <div
                                                        class="terminal-font text-xs px-3 py-1 rounded-full"
                                                        :class="order.paired_order_id ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/50' : 'bg-gray-700/50 text-gray-400 border border-gray-600'"
                                                    >
                                                        <span x-text="order.paired_order_id ? 'PAIRED' : 'WAITING'"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Waiting For - Next Action -->
                        <div x-show="bot.active_orders.some(o => !o.paired_order_id)"
                            class="glass rounded-xl p-6 border border-cyan-500/30">
                            <h3 class="terminal-font text-lg text-white mb-4 flex items-center gap-2">
                                <span class="text-cyan-400 animate-pulse">◉</span>
                                NEXT EXPECTED ACTION
                            </h3>
                            <div class="grid grid-cols-2 gap-4">
                                <template x-for="order in bot.active_orders.filter(o => !o.paired_order_id).slice(0, 2)" :key="order.id">
                                    <div class="glass rounded-lg p-4 border"
                                        :class="order.type === 'buy' ? 'border-green-500/30' : 'border-red-500/30'">
                                        <div class="terminal-font text-xs text-gray-400 mb-2">TRIGGER PRICE</div>
                                        <div class="terminal-font text-2xl font-bold text-white mb-2"
                                            x-text="(order.price / 10000000).toFixed(0) + 'M'"></div>
                                        <div class="terminal-font text-xs"
                                            :class="order.type === 'buy' ? 'text-green-400' : 'text-red-400'"
                                            x-text="order.type.toUpperCase() + ' ORDER'"></div>
                                    </div>
                                </template>
                            </div>
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
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
