<x-filament-panels::page>
    <div x-data="botMonitoring()" x-init="init()">
        <!-- Bot Cards Grid -->
        <div class="grid grid-cols-1 gap-6">
            <template x-for="bot in bots" :key="bot.id">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6">
                    <!-- Bot Header -->
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-xl font-bold" x-text="bot.name"></h3>
                            <p class="text-sm text-gray-500" x-text="bot.symbol"></p>
                        </div>
                        <div>
                            <span
                                class="px-3 py-1 rounded-full text-sm font-semibold"
                                :class="bot.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                x-text="bot.status === 'active' ? '🟢 فعال' : '🔴 غیرفعال'"
                            ></span>
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="grid grid-cols-4 gap-4 mb-6">
                        <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <div class="text-2xl font-bold" x-text="(bot.capital / 10000000).toFixed(0) + 'M'"></div>
                            <div class="text-sm text-gray-600">سرمایه</div>
                        </div>
                        <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <div class="text-2xl font-bold" x-text="bot.active_orders.length"></div>
                            <div class="text-sm text-gray-600">سفارشات فعال</div>
                        </div>
                        <div class="text-center p-4 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                            <div class="text-2xl font-bold" x-text="bot.completed_trades_24h"></div>
                            <div class="text-sm text-gray-600">معاملات 24h</div>
                        </div>
                        <div class="text-center p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                            <div class="text-2xl font-bold" x-text="(bot.profit_24h / 10000).toFixed(0) + 'K'"></div>
                            <div class="text-sm text-gray-600">سود 24h</div>
                        </div>
                    </div>

                    <!-- Active Orders Visualization -->
                    <div class="space-y-2">
                        <h4 class="font-semibold mb-3">سفارشات فعال:</h4>
                        <template x-for="order in bot.active_orders" :key="order.id">
                            <div
                                class="flex items-center justify-between p-3 rounded-lg"
                                :class="order.type === 'buy' ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'"
                            >
                                <div class="flex items-center gap-3">
                                    <span x-text="order.type === 'buy' ? '🟢 خرید' : '🔴 فروش'"></span>
                                    <span class="font-mono" x-text="'@ ' + (order.price / 10000000).toFixed(0) + 'M'"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="px-2 py-1 rounded text-xs"
                                        :class="order.paired_order_id ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'"
                                        x-text="order.paired_order_id ? '🔗 جفت‌شده' : '⏳ در انتظار'"
                                    ></span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Last Check -->
                    <div class="mt-4 pt-4 border-t text-sm text-gray-500" x-show="bot.last_check_at">
                        آخرین بررسی: <span x-text="bot.last_check_at"></span>
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
                    // Auto-refresh every 30 seconds
                    setInterval(() => this.fetchData(), 30000);
                },

                async fetchData() {
                    try {
                        const data = @json($this->getBotData());
                        this.bots = data;
                        this.loading = false;
                    } catch (error) {
                        console.error('Error fetching bot data:', error);
                    }
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
