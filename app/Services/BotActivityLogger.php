<?php

namespace App\Services;

use App\Models\BotActivityLog;
use App\Models\GridOrder;
use Illuminate\Support\Facades\Log;

class BotActivityLogger
{
    /**
     * لاگ شروع بررسی معاملات
     */
    public function logCheckTradesStart(int $botId): void
    {
        try {
            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_CHECK_TRADES_START,
                'level' => BotActivityLog::LEVEL_INFO,
                'message' => 'بررسی وضعیت سفارشات آغاز شد',
                'details' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log CHECK_TRADES_START', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ پایان بررسی معاملات
     */
    public function logCheckTradesEnd(int $botId, int $executionTimeMs): void
    {
        try {
            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_CHECK_TRADES_END,
                'level' => BotActivityLog::LEVEL_SUCCESS,
                'message' => 'بررسی سفارشات با موفقیت انجام شد',
                'execution_time' => $executionTimeMs,
                'details' => [
                    'duration' => round($executionTimeMs / 1000, 2) . ' ثانیه',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log CHECK_TRADES_END', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ فراخوانی API
     */
    public function logApiCall(
        int $botId,
        string $endpoint,
        ?array $request,
        ?array $response,
        int $executionTimeMs
    ): void {
        try {
            // تعیین پیام بر اساس endpoint
            $message = $this->getApiCallMessage($endpoint, $response);
            $level = isset($response['status']) && $response['status'] === 'ok'
                ? BotActivityLog::LEVEL_SUCCESS
                : BotActivityLog::LEVEL_WARNING;

            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_API_CALL,
                'level' => $level,
                'message' => $message,
                'api_request' => $this->sanitizeRequest($request),
                'api_response' => $this->sanitizeResponse($response),
                'execution_time' => $executionTimeMs,
                'details' => [
                    'endpoint' => $endpoint,
                    'response_time' => $executionTimeMs . 'ms',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log API_CALL', [
                'bot_id' => $botId,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ ثبت سفارش جدید
     */
    public function logOrderPlaced(int $botId, array $orderDetails): void
    {
        try {
            $type = $orderDetails['type'] ?? 'unknown';
            $price = $orderDetails['price'] ?? 0;
            $amount = $orderDetails['amount'] ?? 0;

            $message = sprintf(
                'سفارش %s ثبت شد - قیمت: %s - مقدار: %s',
                $type === 'buy' ? 'خرید' : 'فروش',
                $this->formatPrice($price),
                $amount
            );

            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_ORDER_PLACED,
                'level' => BotActivityLog::LEVEL_SUCCESS,
                'message' => $message,
                'details' => $orderDetails,
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log ORDER_PLACED', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ اجرای سفارش
     */
    public function logOrderFilled(int $botId, GridOrder $order): void
    {
        try {
            $message = sprintf(
                'سفارش %s #%d جفت شد - قیمت: %s',
                $order->type === 'buy' ? 'خرید' : 'فروش',
                $order->id,
                $this->formatPrice($order->price)
            );

            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_ORDER_FILLED,
                'level' => BotActivityLog::LEVEL_SUCCESS,
                'message' => $message,
                'details' => [
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'price' => $order->price,
                    'amount' => $order->amount,
                    'nobitex_order_id' => $order->nobitex_order_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log ORDER_FILLED', [
                'bot_id' => $botId,
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ لغو سفارش
     */
    public function logOrderCancelled(int $botId, GridOrder $order, string $reason = ''): void
    {
        try {
            $message = sprintf(
                'سفارش %s #%d لغو شد',
                $order->type === 'buy' ? 'خرید' : 'فروش',
                $order->id
            );

            if ($reason) {
                $message .= ' - دلیل: ' . $reason;
            }

            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_ORDER_CANCELLED,
                'level' => BotActivityLog::LEVEL_WARNING,
                'message' => $message,
                'details' => [
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'price' => $order->price,
                    'reason' => $reason,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log ORDER_CANCELLED', [
                'bot_id' => $botId,
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ بررسی قیمت
     */
    public function logPriceCheck(int $botId, int $currentPrice, array $targetPrices = []): void
    {
        try {
            $highestSell = $targetPrices['highest_sell'] ?? null;
            $lowestBuy = $targetPrices['lowest_buy'] ?? null;
            $decision = $targetPrices['decision'] ?? 'no_action';

            $message = sprintf(
                'قیمت فعلی: %s',
                $this->formatPrice($currentPrice)
            );

            if ($highestSell) {
                $message .= sprintf(' | بالاترین فروش: %s', $this->formatPrice($highestSell));
            }

            if ($lowestBuy) {
                $message .= sprintf(' | پایین‌ترین خرید: %s', $this->formatPrice($lowestBuy));
            }

            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_PRICE_CHECK,
                'level' => BotActivityLog::LEVEL_INFO,
                'message' => $message,
                'details' => [
                    'current_price' => $currentPrice,
                    'highest_sell' => $highestSell,
                    'lowest_buy' => $lowestBuy,
                    'decision' => $decision,
                    'waiting_for' => $this->getWaitingMessage($decision, $highestSell, $lowestBuy),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log PRICE_CHECK', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ تنظیم مجدد گرید
     */
    public function logGridAdjust(int $botId, string $message, array $details = []): void
    {
        try {
            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_GRID_ADJUST,
                'level' => BotActivityLog::LEVEL_INFO,
                'message' => $message,
                'details' => $details,
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log GRID_ADJUST', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ خطا
     */
    public function logError(int $botId, string $message, array $details = []): void
    {
        try {
            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_ERROR,
                'level' => BotActivityLog::LEVEL_ERROR,
                'message' => $message,
                'details' => $details,
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log ERROR', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لاگ تکمیل چرخه معاملاتی
     */
    public function logTradeCompleted(int $botId, array $tradeDetails): void
    {
        try {
            $profit = $tradeDetails['profit'] ?? 0;
            $buyPrice = $tradeDetails['buy_price'] ?? 0;
            $sellPrice = $tradeDetails['sell_price'] ?? 0;

            $message = sprintf(
                'چرخه معاملاتی کامل شد - خرید: %s | فروش: %s | سود: %s تومان',
                $this->formatPrice($buyPrice),
                $this->formatPrice($sellPrice),
                number_format($profit)
            );

            BotActivityLog::create([
                'bot_config_id' => $botId,
                'action_type' => BotActivityLog::ACTION_ORDER_FILLED,
                'level' => BotActivityLog::LEVEL_SUCCESS,
                'message' => $message,
                'details' => $tradeDetails,
            ]);
        } catch (\Exception $e) {
            Log::error('BotActivityLogger: Failed to log TRADE_COMPLETED', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper: فرمت قیمت
     */
    private function formatPrice($price): string
    {
        if (!$price) return '0';

        $priceInt = (int) $price;

        if ($priceInt >= 10000000) {
            return number_format($priceInt / 10000000, 1) . 'M';
        }

        return number_format($priceInt);
    }

    /**
     * Helper: پیام API call
     */
    private function getApiCallMessage(string $endpoint, ?array $response): string
    {
        if (str_contains($endpoint, 'orders/status')) {
            $count = is_array($response) && isset($response['order']) ? 1 : 0;
            return "دریافت وضعیت {$count} سفارش از نوبیتکس";
        }

        if (str_contains($endpoint, 'orders/list')) {
            $count = is_array($response) && isset($response['orders']) ? count($response['orders']) : 0;
            return "دریافت {$count} سفارش فعال از نوبیتکس";
        }

        if (str_contains($endpoint, 'orders/add')) {
            return 'ثبت سفارش جدید در نوبیتکس';
        }

        if (str_contains($endpoint, 'market/stats')) {
            return 'دریافت آمار بازار از نوبیتکس';
        }

        return "فراخوانی API: {$endpoint}";
    }

    /**
     * Helper: پاکسازی request (حذف اطلاعات حساس)
     */
    private function sanitizeRequest(?array $request): ?array
    {
        if (!$request) return null;

        $sanitized = $request;

        // حذف توکن‌های احراز هویت
        unset($sanitized['token']);
        unset($sanitized['api_key']);

        return $sanitized;
    }

    /**
     * Helper: پاکسازی response
     */
    private function sanitizeResponse(?array $response): ?array
    {
        if (!$response) return null;

        // فقط فیلدهای مهم را نگه دار
        return [
            'status' => $response['status'] ?? null,
            'message' => $response['message'] ?? null,
            'code' => $response['code'] ?? null,
            'order' => $response['order'] ?? null,
            'orders' => isset($response['orders']) ? count($response['orders']) : null,
        ];
    }

    /**
     * Helper: پیام انتظار
     */
    private function getWaitingMessage(string $decision, $highestSell, $lowestBuy): ?string
    {
        if ($decision === 'waiting_for_price_movement') {
            if ($highestSell) {
                return 'منتظر رسیدن قیمت به ' . $this->formatPrice($highestSell) . ' برای اجرای فروش';
            }
            if ($lowestBuy) {
                return 'منتظر رسیدن قیمت به ' . $this->formatPrice($lowestBuy) . ' برای اجرای خرید';
            }
        }

        return null;
    }
}
