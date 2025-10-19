<?php

namespace App\Jobs;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Models\CompletedTrade;
use App\Services\NobitexService;
use App\Services\TradingEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CheckTradesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * تعداد تلاش‌های مجدد در صورت خطا
     */
    public $tries = 3;

    /**
     * زمان انتظار قبل از تایم‌اوت (ثانیه)
     */
    public $timeout = 120;

    public function handle()
    {
        // فقط ربات‌های فعال رو چک کن
        $activeBots = BotConfig::where('is_active', true)->get();
        
        if ($activeBots->isEmpty()) {
            Log::info('CheckTradesJob: No active bots found');
            return;
        }
        
        Log::info('CheckTradesJob: Starting check for ' . $activeBots->count() . ' active bots');
        
        foreach ($activeBots as $bot) {
            try {
                $this->processBot($bot);
            } catch (\Exception $e) {
                Log::error("CheckTradesJob: Error checking bot {$bot->name}: " . $e->getMessage());
                Log::error($e->getTraceAsString());
            }
        }
        
        Log::info('CheckTradesJob: Completed successfully');
    }

    /**
     * پردازش یک ربات
     */
    private function processBot(BotConfig $bot)
    {
        Log::info("CheckTradesJob: Processing bot {$bot->name} (ID: {$bot->id})");
        
        // بررسی سفارشات فعال
        $activeOrders = $bot->gridOrders()
            ->where('status', 'placed')
            ->get();
        
        Log::info("CheckTradesJob: Found {$activeOrders->count()} active orders for bot {$bot->name}");
        
        foreach ($activeOrders as $order) {
            // TODO: چک کردن وضعیت واقعی از نوبیتکس
            // فعلاً شبیه‌سازی می‌کنیم
            $this->simulateOrderCheck($order);
        }
        
        // اگر سفارشی پر شده، سفارش جدید در طرف مقابل ایجاد کن
        $filledOrders = $bot->gridOrders()
            ->where('status', 'filled')
            ->whereNull('paired_order_id')
            ->get();
        
        Log::info("CheckTradesJob: Found {$filledOrders->count()} filled orders without pair for bot {$bot->name}");
        
        foreach ($filledOrders as $filledOrder) {
            $this->createPairOrder($filledOrder, $bot);
        }
    }
    
    /**
     * شبیه‌سازی چک کردن وضعیت سفارش
     */
    private function simulateOrderCheck(GridOrder $order)
    {
        // شبیه‌سازی: 20% شانس پر شدن سفارش (افزایش دادم برای تست بهتر)
        if (rand(1, 100) <= 20) {
            $order->update(['status' => 'filled']);
            
            Log::info("Order {$order->id} filled at price {$order->price} (type: {$order->type})");
        }
    }
    
    /**
     * ایجاد سفارش جفت برای سفارش پر شده
     */
    private function createPairOrder(GridOrder $filledOrder, BotConfig $bot)
    {
        DB::beginTransaction();
        
        try {
            // اگر سفارش خرید بود، سفارش فروش بساز و برعکس
            $newType = $filledOrder->type === 'buy' ? 'sell' : 'buy';
            $priceChange = $bot->grid_spacing / 100;
            
            // محاسبه قیمت سفارش جدید
            if ($newType === 'sell') {
                // سفارش فروش باید گرون‌تر از خرید باشه
                $newPrice = $filledOrder->price * (1 + $priceChange);
            } else {
                // سفارش خرید باید ارزون‌تر از فروش باشه
                $newPrice = $filledOrder->price * (1 - $priceChange);
            }
            
            // ایجاد سفارش جدید
            $newOrder = GridOrder::create([
                'bot_config_id' => $bot->id,
                'price' => round($newPrice),
                'amount' => $filledOrder->amount,
                'type' => $newType,
                'status' => 'placed',
                'nobitex_order_id' => 'fake_' . uniqid(), // برای شبیه‌سازی
            ]);
            
            // به‌روزرسانی سفارش قبلی با ID سفارش جفت
            $filledOrder->update(['paired_order_id' => $newOrder->id]);
            
            Log::info("Created pair order {$newOrder->id} (type: {$newType}, price: {$newPrice}) for filled order {$filledOrder->id}");
            
            // اگر سفارش جدید، فروش است، یعنی یک سیکل کامل شده
            if ($newType === 'sell') {
                $this->recordCompletedTrade($filledOrder, $newPrice, $bot);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create pair order: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * ثبت معامله کامل شده
     */
    private function recordCompletedTrade(GridOrder $buyOrder, float $sellPrice, BotConfig $bot)
    {
        $buyPrice = $buyOrder->price;
        $amount = $buyOrder->amount;
        
        // محاسبه سود/زیان
        $grossProfit = ($sellPrice - $buyPrice) * $amount;
        $feeRate = 0.002; // 0.2% کارمزد
        $totalFee = (($buyPrice * $amount) + ($sellPrice * $amount)) * $feeRate;
        $netProfit = $grossProfit - $totalFee;
        
        $trade = CompletedTrade::create([
            'bot_config_id' => $bot->id,
            'buy_price' => $buyPrice,
            'sell_price' => $sellPrice,
            'amount' => $amount,
            'profit' => $netProfit,
            'fee' => $totalFee,
        ]);
        
        Log::info("Recorded completed trade {$trade->id}: Buy at {$buyPrice}, Sell at {$sellPrice}, Profit: {$netProfit}");
    }

    /**
     * در صورت شکست job
     */
    public function failed(\Throwable $exception)
    {
        Log::error('CheckTradesJob failed: ' . $exception->getMessage());
    }
}