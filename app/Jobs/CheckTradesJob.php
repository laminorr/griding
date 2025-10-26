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

    /**
     * زمان تاخیر بین تلاش‌های مجدد (ثانیه) - exponential backoff
     * [اولین تلاش مجدد: 2 ثانیه، دومین: 4 ثانیه، سومین: 8 ثانیه]
     */
    public $backoff = [2, 4, 8];

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

        // بررسی سفارشات فعال (placed = در انتظار اجرا)
        $activeOrders = $bot->gridOrders()
            ->where('status', 'placed')
            ->get();

        Log::info("CheckTradesJob: Found {$activeOrders->count()} active orders for bot {$bot->name}");

        // بررسی وضعیت واقعی سفارشات از نوبیتکس
        if ($activeOrders->isNotEmpty()) {
            $this->checkOrdersStatus($activeOrders, $bot);
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

        // ✅ Update last_check_at timestamp
        $bot->update([
            'last_check_at' => now(),
        ]);

        Log::info("CheckTradesJob: Bot {$bot->name} check completed and timestamp updated");
    }

    /**
     * بررسی وضعیت واقعی سفارشات از نوبیتکس
     *
     * @param \Illuminate\Support\Collection $orders مجموعه سفارشات برای بررسی
     * @param BotConfig $bot کانفیگ ربات
     * @return void
     */
    private function checkOrdersStatus($orders, BotConfig $bot): void
    {
        try {
            // دریافت NobitexService از service container
            /** @var NobitexService $nobitexService */
            $nobitexService = app(NobitexService::class);

            // جمع‌آوری IDهای سفارشات برای بررسی batch
            $orderIds = $orders->pluck('nobitex_order_id')->filter()->toArray();

            if (empty($orderIds)) {
                Log::warning("CheckTradesJob: No valid Nobitex order IDs found for bot {$bot->name}");
                return;
            }

            Log::info("CheckTradesJob: Checking status for " . count($orderIds) . " orders from Nobitex API");

            // دریافت وضعیت سفارشات از نوبیتکس (batch request برای بهینه‌سازی)
            $statusDtos = $nobitexService->getOrdersStatus($orderIds);

            // ایجاد نقشه برای دسترسی سریع به DTOها
            $statusMap = collect($statusDtos)->keyBy('orderId');

            // پردازش هر سفارش
            foreach ($orders as $order) {
                try {
                    // اگر ID نوبیتکس وجود ندارد، رد کن
                    if (!$order->nobitex_order_id) {
                        Log::warning("CheckTradesJob: Order {$order->id} has no Nobitex order ID, skipping");
                        continue;
                    }

                    // یافتن DTO مربوط به این سفارش
                    $statusDto = $statusMap->get($order->nobitex_order_id);

                    if (!$statusDto) {
                        Log::warning("CheckTradesJob: No status received from Nobitex for order {$order->id} (Nobitex ID: {$order->nobitex_order_id})");
                        continue;
                    }

                    // پردازش وضعیت سفارش
                    $this->processOrderStatus($order, $statusDto, $bot);

                } catch (\Exception $e) {
                    Log::error("CheckTradesJob: Error processing order {$order->id}: " . $e->getMessage());
                    Log::error($e->getTraceAsString());
                }

                // تاخیر کوچک برای respect rate limits (اگر چند بار loop شود)
                usleep(100000); // 100ms delay
            }

        } catch (\Exception $e) {
            Log::error("CheckTradesJob: Error checking orders status for bot {$bot->name}: " . $e->getMessage());
            Log::error($e->getTraceAsString());
        }
    }

    /**
     * پردازش وضعیت یک سفارش بر اساس پاسخ API
     *
     * @param GridOrder $order سفارش محلی
     * @param \App\DTOs\OrderStatusDto $statusDto وضعیت از API نوبیتکس
     * @param BotConfig $bot کانفیگ ربات
     * @return void
     */
    private function processOrderStatus(GridOrder $order, $statusDto, BotConfig $bot): void
    {
        // گرفتن وضعیت از DTO
        $apiStatus = $statusDto->status->value; // PENDING, ACTIVE, FILLED, CANCELED, ERROR

        Log::debug("CheckTradesJob: Processing order {$order->id}, current status: {$order->status}, API status: {$apiStatus}");

        // اگر وضعیت تغییری نکرده، ادامه نده
        $mappedStatus = $this->mapNobitexStatusToLocal($apiStatus);
        if ($order->status === $mappedStatus) {
            return;
        }

        // پردازش بر اساس وضعیت جدید
        switch ($apiStatus) {
            case 'FILLED':
                // سفارش کامل شده است
                $this->handleFilledOrder($order, $statusDto, $bot);
                break;

            case 'ACTIVE':
                // سفارش هنوز در حال اجرا است (تغییری لازم نیست)
                Log::debug("CheckTradesJob: Order {$order->id} is still active");
                break;

            case 'CANCELED':
            case 'INACTIVE':
                // سفارش لغو شده است
                $this->handleCanceledOrder($order, $statusDto);
                break;

            case 'ERROR':
                // سفارش با خطا مواجه شده
                $this->handleErrorOrder($order, $statusDto);
                break;

            case 'PENDING':
                // سفارش هنوز در حال انتظار است
                Log::debug("CheckTradesJob: Order {$order->id} is still pending");
                break;

            default:
                Log::warning("CheckTradesJob: Unknown order status '{$apiStatus}' for order {$order->id}");
        }
    }

    /**
     * پردازش سفارش پر شده (FILLED)
     *
     * @param GridOrder $order
     * @param \App\DTOs\OrderStatusDto $statusDto
     * @param BotConfig $bot
     * @return void
     */
    private function handleFilledOrder(GridOrder $order, $statusDto, BotConfig $bot): void
    {
        DB::beginTransaction();

        try {
            // به‌روزرسانی وضعیت سفارش به filled
            $order->update([
                'status' => 'filled',
                'filled_at' => now(),
                'amount' => $statusDto->filledBase ?? $order->amount, // استفاده از مقدار واقعی اگر موجود باشد
            ]);

            Log::info("CheckTradesJob: Order {$order->id} marked as filled - Price: {$order->price}, Amount: {$order->amount}, Type: {$order->type}");

            // ایجاد رکورد CompletedTrade (اگر سفارش جفتی دارد)
            $this->createCompletedTradeIfPaired($order, $bot);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("CheckTradesJob: Error handling filled order {$order->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * پردازش سفارش لغو شده (CANCELED)
     *
     * @param GridOrder $order
     * @param \App\DTOs\OrderStatusDto $statusDto
     * @return void
     */
    private function handleCanceledOrder(GridOrder $order, $statusDto): void
    {
        $order->update([
            'status' => 'cancelled',
        ]);

        Log::info("CheckTradesJob: Order {$order->id} marked as cancelled");
    }

    /**
     * پردازش سفارش با خطا (ERROR)
     *
     * @param GridOrder $order
     * @param \App\DTOs\OrderStatusDto $statusDto
     * @return void
     */
    private function handleErrorOrder(GridOrder $order, $statusDto): void
    {
        $order->update([
            'status' => 'cancelled', // تبدیل به cancelled چون قابل اجرا نیست
        ]);

        Log::error("CheckTradesJob: Order {$order->id} encountered an error on Nobitex, marked as cancelled");
    }

    /**
     * نگاشت وضعیت نوبیتکس به وضعیت محلی
     *
     * @param string $nobitexStatus
     * @return string
     */
    private function mapNobitexStatusToLocal(string $nobitexStatus): string
    {
        return match ($nobitexStatus) {
            'PENDING' => 'pending',
            'ACTIVE' => 'placed',
            'FILLED' => 'filled',
            'CANCELED', 'INACTIVE', 'ERROR' => 'cancelled',
            default => 'placed',
        };
    }

    /**
     * ایجاد رکورد CompletedTrade اگر سفارش جفت دارد
     *
     * @param GridOrder $order سفارش پر شده
     * @param BotConfig $bot
     * @return void
     */
    private function createCompletedTradeIfPaired(GridOrder $order, BotConfig $bot): void
    {
        // چک کن آیا این سفارش، جفت یک سفارش دیگر است که قبلاً پر شده
        // مثلاً اگر این sell است، باید یک buy پر شده قبلی پیدا کنیم

        if ($order->type === 'sell') {
            // پیدا کردن آخرین سفارش خرید پر شده که قیمتش کمتر از این فروش است
            $buyOrder = GridOrder::where('bot_config_id', $bot->id)
                ->where('type', 'buy')
                ->where('status', 'filled')
                ->where('price', '<', $order->price)
                ->whereNull('paired_order_id') // هنوز pair نشده
                ->orderBy('price', 'desc') // نزدیک‌ترین به قیمت فروش
                ->first();

            if ($buyOrder) {
                // ایجاد CompletedTrade
                $this->recordCompletedTrade($buyOrder, $order->price, $bot);

                // مارک کردن این دو سفارش به عنوان paired
                $buyOrder->update(['paired_order_id' => $order->id]);
                $order->update(['paired_order_id' => $buyOrder->id]);

                Log::info("CheckTradesJob: Created completed trade for buy order {$buyOrder->id} and sell order {$order->id}");
            }
        } elseif ($order->type === 'buy') {
            // پیدا کردن آخرین سفارش فروش پر شده که قیمتش بیشتر از این خرید است
            $sellOrder = GridOrder::where('bot_config_id', $bot->id)
                ->where('type', 'sell')
                ->where('status', 'filled')
                ->where('price', '>', $order->price)
                ->whereNull('paired_order_id') // هنوز pair نشده
                ->orderBy('price', 'asc') // نزدیک‌ترین به قیمت خرید
                ->first();

            if ($sellOrder) {
                // ایجاد CompletedTrade
                $this->recordCompletedTrade($order, $sellOrder->price, $bot);

                // مارک کردن این دو سفارش به عنوان paired
                $order->update(['paired_order_id' => $sellOrder->id]);
                $sellOrder->update(['paired_order_id' => $order->id]);

                Log::info("CheckTradesJob: Created completed trade for buy order {$order->id} and sell order {$sellOrder->id}");
            }
        }
    }
    
    /**
     * ایجاد سفارش جفت برای سفارش پر شده
     *
     * این متد بعد از پر شدن یک سفارش، سفارش جدید در جهت مخالف ایجاد می‌کند
     * برای ادامه چرخه Grid Trading
     *
     * @param GridOrder $filledOrder سفارش پر شده
     * @param BotConfig $bot تنظیمات ربات
     * @return void
     */
    private function createPairOrder(GridOrder $filledOrder, BotConfig $bot): void
    {
        DB::beginTransaction();

        try {
            // اگر سفارش خرید بود، سفارش فروش بساز و برعکس
            $newType = $filledOrder->type === 'buy' ? 'sell' : 'buy';
            $priceChange = $bot->grid_spacing / 100;

            // محاسبه قیمت سفارش جدید بر اساس grid spacing
            if ($newType === 'sell') {
                // سفارش فروش باید گران‌تر از خرید باشد
                $newPrice = $filledOrder->price * (1 + $priceChange);
            } else {
                // سفارش خرید باید ارزان‌تر از فروش باشد
                $newPrice = $filledOrder->price * (1 - $priceChange);
            }

            // گرد کردن قیمت
            $newPrice = (int) round($newPrice);

            Log::info("CheckTradesJob: Creating pair order - Type: {$newType}, Price: {$newPrice} for filled order {$filledOrder->id}");

            // دریافت NobitexService از service container
            /** @var NobitexService $nobitexService */
            $nobitexService = app(NobitexService::class);

            // تعیین symbol (پیش‌فرض BTCIRT)
            $symbol = $bot->symbol ?? 'BTCIRT';

            // ثبت سفارش در نوبیتکس
            $apiResponse = $nobitexService->placeOrder(
                $symbol,
                $newType,
                $newPrice,
                (string) $filledOrder->amount
            );

            // بررسی موفقیت‌آمیز بودن ثبت سفارش
            if (($apiResponse['status'] ?? null) !== 'ok') {
                throw new \RuntimeException('Nobitex order placement failed: ' . ($apiResponse['message'] ?? 'Unknown error'));
            }

            // دریافت ID سفارش از پاسخ نوبیتکس
            $nobitexOrderId = $apiResponse['order']['id'] ?? null;

            if (!$nobitexOrderId) {
                throw new \RuntimeException('Nobitex order ID not found in response');
            }

            // ایجاد رکورد سفارش جدید در دیتابیس
            $newOrder = GridOrder::create([
                'bot_config_id' => $bot->id,
                'price' => $newPrice,
                'amount' => $filledOrder->amount,
                'type' => $newType,
                'status' => 'placed',
                'nobitex_order_id' => (string) $nobitexOrderId,
            ]);

            // به‌روزرسانی سفارش قبلی با ID سفارش جفت
            $filledOrder->update(['paired_order_id' => $newOrder->id]);

            Log::info("CheckTradesJob: Successfully created pair order {$newOrder->id} (Nobitex ID: {$nobitexOrderId}) - Type: {$newType}, Price: {$newPrice} for filled order {$filledOrder->id}");

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("CheckTradesJob: Failed to create pair order for filled order {$filledOrder->id}: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            // ثبت خطا در دیتابیس برای پیگیری
            // می‌توانیم یک فیلد error_message در جدول اضافه کنیم یا در لاگ ذخیره کنیم
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