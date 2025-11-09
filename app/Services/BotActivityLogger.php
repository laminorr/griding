<?php
namespace App\Services;

use App\Models\BotActivityLog;
use App\Models\BotConfig;

class BotActivityLogger
{
    public function log($botId, $actionType, $level, $message, $details = null, $apiRequest = null, $apiResponse = null, $executionTime = null)
    {
        return BotActivityLog::create([
            'bot_config_id' => $botId,
            'action_type' => $actionType,
            'level' => $level,
            'message' => $message,
            'details' => $details,
            'api_request' => $apiRequest,
            'api_response' => $apiResponse,
            'execution_time' => $executionTime,
        ]);
    }

    public function logCheckStart($botId)
    {
        return $this->log($botId, 'CHECK_TRADES_START', 'INFO', '🔍 بررسی وضعیت سفارشات آغاز شد');
    }

    public function logApiCall($botId, $endpoint, $request, $response, $executionTime)
    {
        $message = sprintf('📡 درخواست به نوبیتکس: %s (زمان پاسخ: %dms)', $endpoint, $executionTime);
        return $this->log($botId, 'API_CALL', 'INFO', $message, null, $request, $response, $executionTime);
    }

    public function logOrdersReceived($botId, $count)
    {
        return $this->log($botId, 'ORDERS_RECEIVED', 'SUCCESS', "✅ دریافت $count سفارش فعال از نوبیتکس");
    }

    public function logPriceCheck($botId, $currentPrice, $targetPrices)
    {
        $message = sprintf('📊 قیمت فعلی: %s تومان', number_format($currentPrice / 10));
        $details = ['current_price' => $currentPrice, 'targets' => $targetPrices];
        return $this->log($botId, 'PRICE_CHECK', 'INFO', $message, $details);
    }

    public function logWaitingFor($botId, $type, $price)
    {
        $message = $type === 'buy'
            ? sprintf('⏳ منتظر کاهش قیمت به %s تومان برای خرید', number_format($price / 10))
            : sprintf('⏳ منتظر افزایش قیمت به %s تومان برای فروش', number_format($price / 10));
        return $this->log($botId, 'WAITING', 'INFO', $message, ['waiting_for' => $type, 'target_price' => $price]);
    }

    public function logOrderPaired($botId, $buyOrderId, $sellOrderId, $profit)
    {
        $message = sprintf('🔗 سفارش‌ها جفت شدند - سود: %s تومان', number_format($profit));
        $details = ['buy_order' => $buyOrderId, 'sell_order' => $sellOrderId, 'profit' => $profit];
        return $this->log($botId, 'ORDER_PAIRED', 'SUCCESS', $message, $details);
    }

    public function logCheckEnd($botId, $duration)
    {
        $message = sprintf('✨ چرخه بررسی کامل شد (زمان کل: %.1fs)', $duration / 1000);
        return $this->log($botId, 'CHECK_TRADES_END', 'SUCCESS', $message, ['duration' => $duration]);
    }

    public function logError($botId, $message, $details = null)
    {
        return $this->log($botId, 'ERROR', 'ERROR', '❌ ' . $message, $details);
    }

    public function logOrderPlaced($botId, $orderDetails)
    {
        $message = sprintf('📝 سفارش جدید ثبت شد - نوع: %s، قیمت: %s تومان',
            $orderDetails['type'] === 'buy' ? 'خرید' : 'فروش',
            number_format($orderDetails['price'] / 10)
        );
        return $this->log($botId, 'ORDER_PLACED', 'SUCCESS', $message, $orderDetails);
    }

    public function logOrderFilled($botId, $order)
    {
        $message = sprintf('🎯 سفارش %s اجرا شد - قیمت: %s تومان',
            $order->type === 'buy' ? 'خرید' : 'فروش',
            number_format($order->price / 10)
        );
        $details = [
            'order_id' => $order->id,
            'type' => $order->type,
            'price' => $order->price,
            'amount' => $order->amount,
        ];
        return $this->log($botId, 'ORDER_FILLED', 'SUCCESS', $message, $details);
    }

    public function logTradeCompleted($botId, $tradeDetails)
    {
        $profit = $tradeDetails['profit'];
        $message = sprintf('💰 معامله کامل شد - سود: %s تومان',
            number_format($profit)
        );
        return $this->log($botId, 'TRADE_COMPLETED', 'SUCCESS', $message, $tradeDetails);
    }

    public function logCheckTradesStart($botId)
    {
        return $this->logCheckStart($botId);
    }

    public function logCheckTradesEnd($botId, $duration)
    {
        return $this->logCheckEnd($botId, $duration);
    }
}
