<x-filament-panels::page>
    <div x-data="botMonitoring()" x-init="init()" class="space-y-6">

        <!-- Loading State -->
        <div x-show="loading" class="flex items-center justify-center py-12">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-500"></div>
        </div>

        <!-- Bots Container -->
        <div x-show="!loading" class="space-y-8">
            <template x-for="bot in bots" :key="bot.id">
                <div class="relative overflow-hidden bg-gradient-to-br from-gray-900 to-gray-800 rounded-2xl shadow-2xl border border-gray-700">

                    <!-- Animated Background -->
                    <div class="absolute inset-0 opacity-10">
                        <div class="absolute inset-0 bg-gradient-to-r from-green-500 via-blue-500 to-purple-500 animate-pulse"></div>
                    </div>

                    <div class="relative p-8">
                        <!-- Header with Live Pulse -->
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <div class="w-4 h-4 bg-green-500 rounded-full animate-ping absolute"></div>
                                    <div class="w-4 h-4 bg-green-500 rounded-full relative"></div>
                                </div>
                                <div>
                                    <h2 class="text-3xl font-bold text-white" x-text="bot.name"></h2>
                                    <p class="text-gray-400 font-mono" x-text="bot.symbol"></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm text-gray-400">Last Check</div>
                                <div class="text-white font-mono text-sm" x-text="bot.last_check_at || 'Never'"></div>
                            </div>
                        </div>

                        <!-- Stats Grid with Glow Effect -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                            <!-- Capital Card -->
                            <div class="bg-gradient-to-br from-blue-500/20 to-blue-600/10 backdrop-blur-sm rounded-xl p-6 border border-blue-500/30 hover:scale-105 transition-transform">
                                <div class="text-blue-400 text-sm mb-2 font-semibold">💰 CAPITAL</div>
                                <div class="text-3xl font-bold text-white" x-text="formatMoney(bot.capital)"></div>
                                <div class="text-xs text-gray-400 mt-1">Total Investment</div>
                            </div>

                            <!-- Active Orders -->
                            <div class="bg-gradient-to-br from-green-500/20 to-green-600/10 backdrop-blur-sm rounded-xl p-6 border border-green-500/30 hover:scale-105 transition-transform">
                                <div class="text-green-400 text-sm mb-2 font-semibold">📊 ACTIVE</div>
                                <div class="text-3xl font-bold text-white" x-text="bot.active_orders.length"></div>
                                <div class="text-xs text-gray-400 mt-1">Orders Waiting</div>
                            </div>

                            <!-- Completed Trades -->
                            <div class="bg-gradient-to-br from-purple-500/20 to-purple-600/10 backdrop-blur-sm rounded-xl p-6 border border-purple-500/30 hover:scale-105 transition-transform">
                                <div class="text-purple-400 text-sm mb-2 font-semibold">✅ TRADES</div>
                                <div class="text-3xl font-bold text-white" x-text="bot.completed_trades_24h"></div>
                                <div class="text-xs text-gray-400 mt-1">Last 24h</div>
                            </div>

                            <!-- Profit -->
                            <div class="bg-gradient-to-br from-yellow-500/20 to-yellow-600/10 backdrop-blur-sm rounded-xl p-6 border border-yellow-500/30 hover:scale-105 transition-transform">
                                <div class="text-yellow-400 text-sm mb-2 font-semibold">💎 PROFIT</div>
                                <div class="text-3xl font-bold text-white" x-text="formatMoney(bot.profit_24h)"></div>
                                <div class="text-xs text-gray-400 mt-1">Last 24h</div>
                            </div>
                        </div>

                        <!-- Grid Visualization -->
                        <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl p-6 mb-6 border border-gray-700">
                            <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                                <span class="text-2xl">📈</span>
                                Grid Levels Visualization
                            </h3>

                            <div class="space-y-3">
                                <template x-for="(order, index) in getSortedOrders(bot.active_orders)" :key="order.id">
                                    <div class="relative">
                                        <!-- Order Bar -->
                                        <div
                                            class="flex items-center gap-4 p-4 rounded-lg transition-all duration-300 hover:scale-[1.02]"
                                            :class="order.type === 'sell' ? 'bg-red-500/20 border-l-4 border-red-500' : 'bg-green-500/20 border-l-4 border-green-500'"
                                        >
                                            <!-- Left: Type Icon -->
                                            <div class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center text-2xl"
                                                :class="order.type === 'sell' ? 'bg-red-500/30' : 'bg-green-500/30'">
                                                <span x-text="order.type === 'sell' ? '🔴' : '🟢'"></span>
                                            </div>

                                            <!-- Middle: Price Info -->
                                            <div class="flex-1">
                                                <div class="flex items-baseline gap-2">
                                                    <span class="text-2xl font-bold font-mono text-white"
                                                        x-text="(order.price / 10000000).toFixed(0) + 'M'"></span>
                                                    <span class="text-sm text-gray-400">تومان</span>
                                                </div>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    Amount: <span x-text="order.amount"></span> BTC
                                                </div>
                                            </div>

                                            <!-- Right: Status Badge -->
                                            <div class="flex-shrink-0">
                                                <div
                                                    class="px-4 py-2 rounded-full text-sm font-semibold"
                                                    :class="order.paired_order_id ? 'bg-blue-500/30 text-blue-300 border border-blue-500' : 'bg-gray-600/30 text-gray-300 border border-gray-500'"
                                                >
                                                    <span x-text="order.paired_order_id ? '🔗 Paired' : '⏳ Waiting'"></span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Animated connecting line to next order -->
                                        <div x-show="index < bot.active_orders.length - 1"
                                            class="absolute left-6 top-full w-0.5 h-3 bg-gradient-to-b from-gray-600 to-transparent"></div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Waiting For Section -->
                        <div x-show="bot.active_orders.length > 0"
                            class="bg-gradient-to-r from-cyan-500/10 to-blue-500/10 backdrop-blur-sm rounded-xl p-6 border border-cyan-500/30">
                            <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                                <span class="animate-pulse">⏰</span>
                                Waiting For...
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <template x-for="order in bot.active_orders.filter(o => !o.paired_order_id).slice(0, 2)" :key="order.id">
                                    <div class="bg-gray-800/50 rounded-lg p-4">
                                        <div class="text-sm text-gray-400 mb-2">Next Expected</div>
                                        <div class="text-xl font-bold text-white font-mono"
                                            x-text="order.type.toUpperCase() + ' @ ' + (order.price / 10000000).toFixed(0) + 'M'"></div>
                                        <div class="text-xs text-gray-500 mt-2">
                                            <span x-text="order.type === 'buy' ? '📉 Price needs to drop' : '📈 Price needs to rise'"></span>
                                        </div>
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

                formatMoney(amount) {
                    if (amount >= 10000000) {
                        return (amount / 10000000).toFixed(1) + 'M تومان';
                    } else if (amount >= 1000) {
                        return (amount / 1000).toFixed(0) + 'K تومان';
                    }
                    return amount + ' تومان';
                },

                getSortedOrders(orders) {
                    return orders.sort((a, b) => b.price - a.price);
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
