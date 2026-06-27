<?php

namespace App\Jobs;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Models\CompletedTrade;
use App\Services\NobitexService;
use App\Services\TradingEngineService;
use App\Services\BotActivityLogger;
use App\Services\MarketDataLayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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

        $failures = [];

        foreach ($activeBots as $bot) {
            try {
                $this->processBot($bot);
            } catch (\Exception $e) {
                Log::error("CheckTradesJob: Error checking bot {$bot->name}: " . $e->getMessage());
                Log::error($e->getTraceAsString());
                $failures[] = "{$bot->name} ({$bot->id}): " . $e->getMessage();
            }
        }

        if (!empty($failures)) {
            // At least one bot failed to process — let the Queue's retry/backoff
            // mechanism engage instead of silently reporting overall success.
            throw new \RuntimeException(
                'CheckTradesJob: ' . count($failures) . ' bot(s) failed to process: ' . implode(' | ', $failures)
            );
        }

        Log::info('CheckTradesJob: Completed successfully');
    }

    /**
     * پردازش یک ربات
     */
    private function processBot(BotConfig $bot)
    {
        $startTime = microtime(true);
        $logger = app(BotActivityLogger::class);

        // Log start of check
        $logger->logCheckTradesStart($bot->id);
        Log::info("CheckTradesJob: [START] Processing bot {$bot->name} (ID: {$bot->id})");

        try {
            // بررسی سفارشات فعال (placed = در انتظار اجرا)
            $activeOrders = $bot->gridOrders()
                ->where('status', 'placed')
                ->get();

            Log::info("CheckTradesJob: Found {$activeOrders->count()} active orders for bot {$bot->name}");

            // بررسی وضعیت سفارشات: برای ربات‌های simulation هرگز به API واقعی نوبیتکس
            // متصل نمی‌شویم (سفارشات SIM-* در نوبیتکس وجود ندارند)؛ به جای آن از موتور
            // تطبیق محلی (checkSimulatedOrders) استفاده می‌کنیم.
            if ($activeOrders->isNotEmpty()) {
                if ($bot->simulation) {
                    $this->checkSimulatedOrders($activeOrders, $bot);
                } else {
                    $this->checkOrdersStatus($activeOrders, $bot);
                }
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

            // ✅ ADD: Log before update
            Log::info("CheckTradesJob: [BEFORE UPDATE] Bot {$bot->name}");
        } catch (\Exception $e) {
            // Log error
            $logger->logError($bot->id, 'خطا در بررسی سفارشات: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            Log::error("CheckTradesJob: [CATCH] Error for bot {$bot->name}: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            // Re-thrown below (after the finally block runs) so handle()'s
            // per-bot catch can record this as a failure and ultimately let
            // the Queue's retry mechanism engage. finally still executes
            // before the rethrow propagates.
            $caught = $e;
        } finally {
            // ✅ ADD: Log at start of finally
            Log::info("CheckTradesJob: [FINALLY] Updating timestamp for bot {$bot->name}");

            try {
                // Use direct DB update to bypass Model events/observers
                $timestamp = now();
                $affected = DB::table('bot_configs')
                    ->where('id', $bot->id)
                    ->update(['last_check_at' => $timestamp]);

                Log::info("CheckTradesJob: [SUCCESS] Bot {$bot->name} timestamp updated (affected: {$affected}) to: {$timestamp}");

                // Log end of check with execution time
                $executionTime = (int) ((microtime(true) - $startTime) * 1000);
                $logger->logCheckTradesEnd($bot->id, $executionTime);
            } catch (\Exception $e) {
                Log::error("CheckTradesJob: [FINALLY ERROR] Failed to update timestamp: " . $e->getMessage());
                Log::error($e->getTraceAsString());
            }
        }

        if (isset($caught)) {
            throw $caught;
        }

        // ✅ ADD: Log at very end
        Log::info("CheckTradesJob: [END] Finished processing bot {$bot->name}");
    }

    /**
     * موتور تطبیق ساده برای سفارشات شبیه‌سازی‌شده (simulation=true).
     *
     * هیچ تماسی با API واقعی نوبیتکس (getOrdersStatus یا هر endpoint دیگری که
     * order ID می‌فرستد) برقرار نمی‌شود — فقط آخرین قیمت بازار واقعی را
     * می‌خوانیم (MarketDataLayer) و طبق منطق سفارش لیمیت واقعی تصمیم می‌گیریم:
     *   - سفارش خرید: وقتی قیمت بازار <= قیمت سفارش برسد، پر می‌شود.
     *   - سفارش فروش: وقتی قیمت بازار >= قیمت سفارش برسد، پر می‌شود.
     *
     * @param \Illuminate\Support\Collection $orders مجموعه سفارشات شبیه‌سازی‌شده برای بررسی
     * @param BotConfig $bot کانفیگ ربات
     * @return void
     */
    private function checkSimulatedOrders($orders, BotConfig $bot): void
    {
        try {
            $symbol = $bot->symbol ?? 'BTCIRT';

            /** @var MarketDataLayer $marketData */
            $marketData = app(MarketDataLayer::class);
            $currentPrice = $marketData->getLastPrice($symbol);

            Log::info("CheckTradesJob: [SIM] Current market price for {$symbol}: {$currentPrice}");

            foreach ($orders as $order) {
                $isFilled = $order->type === 'buy'
                    ? $order->price >= $currentPrice
                    : $order->price <= $currentPrice;

                if (!$isFilled) {
                    continue;
                }

                Log::info("CheckTradesJob: [SIM] Order {$order->id} ({$order->type} @ {$order->price}) filled at market price {$currentPrice}");

                DB::beginTransaction();
                try {
                    $order->update([
                        'status' => 'filled',
                        'filled_at' => now(),
                    ]);

                    app(BotActivityLogger::class)->logOrderFilled($bot->id, $order);

                    $this->createCompletedTradeIfPaired($order, $bot);

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("CheckTradesJob: [SIM] Error handling filled order {$order->id}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            Log::error("CheckTradesJob: [SIM] Error checking simulated orders for bot {$bot->name}: " . $e->getMessage());
            Log::error($e->getTraceAsString());
        }
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
            $logger = app(BotActivityLogger::class);

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
            $apiStart = microtime(true);
            $statusDtos = $nobitexService->getOrdersStatus($orderIds);
            $apiTime = (int) ((microtime(true) - $apiStart) * 1000);

            // Log API call and orders received
            $logger->logApiCall($bot->id, 'orders/status', ['order_ids' => $orderIds], ['orders' => $statusDtos], $apiTime);
            $logger->logOrdersReceived($bot->id, count($statusDtos));

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
        $logger = app(BotActivityLogger::class);

        try {
            // به‌روزرسانی وضعیت سفارش به filled
            $order->update([
                'status' => 'filled',
                'filled_at' => now(),
                'amount' => $statusDto->filledBase ?? $order->amount, // استفاده از مقدار واقعی اگر موجود باشد
            ]);

            Log::info("CheckTradesJob: Order {$order->id} marked as filled - Price: {$order->price}, Amount: {$order->amount}, Type: {$order->type}");

            // Log order filled
            $logger->logOrderFilled($bot->id, $order);

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
     * Phase 9, Step 4 — completed trades are now booked via the STABLE
     * parent/child continuation link (`paired_order_id`) established by
     * createPairOrder(), NOT via nearest-price matching.
     *
     * createPairOrder() links a filled order to the continuation order it
     * spawns, bidirectionally: when order A fills it creates the opposite-side
     * order B with B.paired_order_id = A.id, then sets A.paired_order_id = B.id.
     * A completed (round-trip) trade is booked exactly once — when BOTH legs of
     * such a linked pair are filled. If the partner leg has not filled yet, we
     * do nothing and wait for it.
     *
     * @param GridOrder $order سفارش پر شده
     * @param BotConfig $bot
     * @return void
     */
    private function createCompletedTradeIfPaired(GridOrder $order, BotConfig $bot): void
    {
        Log::info("CheckTradesJob: Checking paired completion for {$order->type} order #{$order->id} at price {$order->price}");

        // No continuation partner yet — typically an initial-grid order that
        // just filled. Its partner is created by createPairOrder() afterwards;
        // the trade is booked when that partner subsequently fills.
        if ($order->paired_order_id === null) {
            Log::info("CheckTradesJob: ⚠️ Order #{$order->id} has no paired_order_id yet — waiting for its continuation partner");
            return;
        }

        // Resolve the linked counterpart via the stable pairing relationship.
        $partner = GridOrder::where('bot_config_id', $bot->id)
            ->where('id', $order->paired_order_id)
            ->first();

        if (!$partner) {
            Log::warning("CheckTradesJob: Order #{$order->id} links to missing partner #{$order->paired_order_id} — skipping");
            return;
        }

        // Only book when BOTH legs are filled. If the partner hasn't filled
        // yet, defer — it will book when the partner's own fill is processed.
        if ($partner->status !== 'filled') {
            Log::info("CheckTradesJob: Partner #{$partner->id} of order #{$order->id} not filled yet (status: {$partner->status}) — deferring");
            return;
        }

        // The two legs must be opposite sides to form a buy/sell round-trip.
        if ($order->type === $partner->type) {
            Log::warning("CheckTradesJob: Order #{$order->id} and partner #{$partner->id} are both '{$order->type}' — cannot book a completed trade");
            return;
        }

        // Assign buy/sell roles correctly regardless of which leg filled last.
        [$buyOrder, $sellOrder] = $order->type === 'buy'
            ? [$order, $partner]
            : [$partner, $order];

        // Double-booking guard: skip if a completed_trade already exists for
        // this exact pair (covers re-runs and both-legs being re-processed).
        $alreadyBooked = CompletedTrade::where('buy_order_id', $buyOrder->id)
            ->where('sell_order_id', $sellOrder->id)
            ->exists();

        if ($alreadyBooked) {
            Log::info("CheckTradesJob: Completed trade for buy #{$buyOrder->id} / sell #{$sellOrder->id} already booked — skipping duplicate");
            return;
        }

        $profit = ($sellOrder->price - $buyOrder->price) * $buyOrder->amount;

        Log::info("CheckTradesJob: Booking completed trade via link — buy #{$buyOrder->id} <-> sell #{$sellOrder->id}, gross profit: {$profit}");

        // ایجاد CompletedTrade
        $this->recordCompletedTrade($buyOrder, $sellOrder, $bot);

        // Log pairing
        $logger = app(BotActivityLogger::class);
        $logger->logOrderPaired($bot->id, $buyOrder->id, $sellOrder->id, $profit);

        Log::info("CheckTradesJob: ✅ Created completed trade for buy order {$buyOrder->id} and sell order {$sellOrder->id}");
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
        $lock = Cache::lock("pair-order:{$filledOrder->id}", 10);

        if (!$lock->get()) {
            Log::channel('trading')->info('PAIR_ORDER_LOCK_BUSY', [
                'filled_order_id' => $filledOrder->id,
                'bot_id'          => $bot->id,
            ]);
            return;
        }

        try {
            $this->createPairOrderLocked($filledOrder, $bot);
        } finally {
            $lock->release();
        }
    }

    private function createPairOrderLocked(GridOrder $filledOrder, BotConfig $bot): void
    {
        $logger = app(BotActivityLogger::class);

        $newType     = $filledOrder->type === 'buy' ? 'sell' : 'buy';
        $priceChange = $bot->grid_spacing / 100;
        $newPrice    = $newType === 'sell'
            ? (int) round($filledOrder->price * (1 + $priceChange))
            : (int) round($filledOrder->price * (1 - $priceChange));

        $symbol = $bot->symbol ?? 'BTCIRT';

        $clientOrderId = GridOrder::buildClientOrderId($bot->id, $symbol, $newType, $newPrice);

        Log::info("CheckTradesJob: Creating pair order - Type: {$newType}, Price: {$newPrice} for filled order {$filledOrder->id}");

        // Dedup guard — abort before opening a transaction if this pair was already placed.
        $existingOrder = GridOrder::where('bot_config_id', $bot->id)
            ->where('client_order_id', $clientOrderId)
            ->whereIn('status', ['pending', 'placed', 'filled', 'partially_filled'])
            ->first();

        if ($existingOrder) {
            Log::channel('trading')->info('DEDUP_SKIP', [
                'bot_id'            => $bot->id,
                'client_order_id'   => $clientOrderId,
                'existing_order_id' => $existingOrder->id,
                'existing_status'   => $existingOrder->status,
            ]);
            return;
        }

        DB::beginTransaction();
        try {
            // Re-read under a row lock to confirm no other process paired this
            // order while we were waiting for the per-order Cache::lock above.
            $current = GridOrder::where('id', $filledOrder->id)->lockForUpdate()->first();

            if (!$current || $current->paired_order_id !== null) {
                Log::channel('trading')->info('PAIR_ORDER_ALREADY_PAIRED', [
                    'filled_order_id' => $filledOrder->id,
                    'bot_id'          => $bot->id,
                ]);
                DB::commit();
                return;
            }

            // Persist the intent row BEFORE the API call so a timeout-retry sees
            // the 'pending' record and the dedup guard above blocks the duplicate.
            Log::channel('trading')->info('PAIR_ORDER_PRE_CREATE', [
                'bot_id'          => $bot->id,
                'type'            => $newType,
                'calculated_price'=> $newPrice,
                'filled_order_id' => $filledOrder->id,
                'client_order_id' => $clientOrderId,
                'simulation'      => (bool) $bot->simulation,
            ]);

            $newOrder = GridOrder::create([
                'bot_config_id'   => $bot->id,
                'price'           => $newPrice,
                'amount'          => $filledOrder->amount,
                'type'            => $newType,
                'status'          => 'pending',
                'client_order_id' => $clientOrderId,
                'paired_order_id' => $filledOrder->id,
            ]);

            if ($bot->simulation) {
                // SIMULATION MODE - never call the real exchange API.
                $nobitexOrderId = 'SIM-' . uniqid() . '-' . time();

                $newOrder->update([
                    'status'           => 'placed',
                    'nobitex_order_id' => $nobitexOrderId,
                ]);

                Log::channel('trading')->info('SIM_PAIR_ORDER_PLACED', [
                    'order_id' => $newOrder->id,
                    'bot_id'   => $bot->id,
                    'type'     => $newType,
                    'price'    => $newPrice,
                ]);

                $logger->logOrderPlaced($bot->id, [
                    'order_id'        => $newOrder->id,
                    'type'            => $newType,
                    'price'           => $newPrice,
                    'amount'          => $filledOrder->amount,
                    'nobitex_order_id'=> $nobitexOrderId,
                ]);
            } else {
                /** @var NobitexService $nobitexService */
                $nobitexService = app(NobitexService::class);

                // Call exchange API
                $startTime   = microtime(true);
                $apiResponse = $nobitexService->placeOrder(
                    $symbol,
                    $newType,
                    $newPrice,
                    (string) $filledOrder->amount,
                    $clientOrderId
                );
                $executionTime = (int) ((microtime(true) - $startTime) * 1000);

                if (($apiResponse['status'] ?? null) !== 'ok') {
                    throw new \RuntimeException('Nobitex order placement failed: ' . ($apiResponse['message'] ?? 'Unknown error'));
                }

                $nobitexOrderId = $apiResponse['order']['id'] ?? null;
                if (!$nobitexOrderId) {
                    throw new \RuntimeException('Nobitex order ID not found in response');
                }

                $newOrder->update([
                    'status'           => 'placed',
                    'nobitex_order_id' => (string) $nobitexOrderId,
                ]);

                $logger->logApiCall($bot->id, '/market/orders/add', null, $apiResponse, $executionTime);
                $logger->logOrderPlaced($bot->id, [
                    'order_id'        => $newOrder->id,
                    'type'            => $newType,
                    'price'           => $newPrice,
                    'amount'          => $filledOrder->amount,
                    'nobitex_order_id'=> $nobitexOrderId,
                ]);
            }

            Log::channel('trading')->info('PAIR_ORDER_POST_CREATE', [
                'order_id'    => $newOrder->id,
                'stored_price'=> $newOrder->price,
                'price_match' => ($newOrder->price == $newPrice) ? 'YES' : 'NO',
            ]);

            $filledOrder->update(['paired_order_id' => $newOrder->id]);

            Log::info("CheckTradesJob: Successfully created pair order {$newOrder->id} (Nobitex ID: {$nobitexOrderId}) - Type: {$newType}, Price: {$newPrice} for filled order {$filledOrder->id}");

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();

            $logger->logError($bot->id, 'خطا در ایجاد سفارش جفت: ' . $e->getMessage(), [
                'filled_order_id' => $filledOrder->id,
                'exception'       => get_class($e),
            ]);

            Log::error("CheckTradesJob: Failed to create pair order for filled order {$filledOrder->id}: " . $e->getMessage());
            Log::error($e->getTraceAsString());
        }
    }
    
    /**
     * ثبت معامله کامل شده
     */
    private function recordCompletedTrade(GridOrder $buyOrder, GridOrder $sellOrder, BotConfig $bot)
    {
        $logger = app(BotActivityLogger::class);

        $buyPrice = $buyOrder->price;
        $sellPrice = $sellOrder->price;
        $amount = $buyOrder->amount;

        // محاسبه سود/زیان برای logging.
        // نرخ کارمزد از همان منبع رسمی‌ای خوانده می‌شود که CompletedTrade::createFromOrders
        // برای persist استفاده می‌کند (fee_bps ربات، fallback به config). این تضمین می‌کند
        // مقدار log شده دقیقاً با مقدار ذخیره‌شده در رکورد معامله یکی باشد.
        $feeBps = $bot->fee_bps ?? config('trading.exchange.fee_bps', 35);
        $feeRate = $feeBps / 10000.0; // bps → نرخ (35 bps = 0.0035)
        $grossProfit = ($sellPrice - $buyPrice) * $amount;
        $totalFee = (($buyPrice * $amount) + ($sellPrice * $amount)) * $feeRate;
        $netProfit = $grossProfit - $totalFee;

        // ✅ DEBUG: Log before creating trade with all details
        Log::info("🔄 Attempting to create completed trade from orders", [
            'bot_id' => $bot->id,
            'bot_name' => $bot->name,
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buy_price' => $buyPrice,
            'sell_price' => $sellPrice,
            'amount' => $amount,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
            'total_fee' => $totalFee,
            'execution_time' => $sellOrder->updated_at->diffInSeconds($buyOrder->created_at),
        ]);

        try {
            // استفاده از متد createFromOrders که همه فیلدهای پیشرفته رو هم ست می‌کنه
            $trade = CompletedTrade::createFromOrders($buyOrder, $sellOrder);

            Log::info("✅ Successfully created completed trade ID: {$trade->id}", [
                'trade_id' => $trade->id,
                'buy_price' => $trade->buy_price,
                'sell_price' => $trade->sell_price,
                'profit' => $trade->profit,
                'net_profit' => $trade->net_profit,
                'profit_percentage' => $trade->profit_percentage,
                'execution_time_seconds' => $trade->execution_time_seconds,
                'trade_type' => $trade->trade_type,
                'grid_level_buy' => $trade->grid_level_buy,
                'grid_level_sell' => $trade->grid_level_sell,
            ]);

            // Log completed trade
            $logger->logTradeCompleted($bot->id, [
                'trade_id' => $trade->id,
                'buy_order_id' => $buyOrder->id,
                'sell_order_id' => $sellOrder->id,
                'buy_price' => $buyPrice,
                'sell_price' => $sellPrice,
                'amount' => $amount,
                'profit' => $netProfit,
                'fee' => $totalFee,
            ]);

            return $trade;
        } catch (\Exception $e) {
            Log::error("❌ CRITICAL: Failed to create completed trade", [
                'error_message' => $e->getMessage(),
                'sql_state' => $e->getCode(),
                'buy_order_id' => $buyOrder->id,
                'sell_order_id' => $sellOrder->id,
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * در صورت شکست job
     */
    public function failed(\Throwable $exception)
    {
        Log::error('CheckTradesJob failed: ' . $exception->getMessage());
    }
}