<?php
declare(strict_types=1);

namespace App\Services;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Models\GridOrder;
use App\Support\OrderRegistry;
use Illuminate\Support\Facades\Log;

/**
 * GridOrderExecutor
 * ------------------------------------------------------------------
 * اعمال diff تولیدی از GridOrderSync روی اکسچنج نوبیتکس.
 *  - لغو سفارش‌های خارج از پلن (to_cancel)
 *  - ثبت سفارش‌های جدید (to_place)
 *  - همگام‌سازی OrderRegistry
 *
 * نکتهٔ مهم بازارهای ریالی (IRT):
 *  برای endpoint های خصوصی ثبت/لغو سفارش، باید dstCurrency = "rls" ارسال شود
 *  (نه "irt"). متد splitSymbol این نگاشت را انجام می‌دهد.
 */
class GridOrderExecutor
{
    public function __construct(
        protected NobitexService $svc,
        protected OrderRegistry  $reg,
    ) {}

    /**
     * اجرای برنامهٔ تغییرات روی اکسچنج
     *
     * @param array $diff خروجی GridOrderSync::diff
     * @param bool  $simulation اگر true باشد، فقط لاگ ثبت می‌شود (بدون تماس واقعی با API)
     */
    /**
     * Primary entry point — scoped to a specific bot.
     * Creates GridOrder records, performs dedup checks, and uses a deterministic
     * client_order_id so timeout-retries cannot produce duplicate exchange orders.
     *
     * $role is stamped onto every GridOrder row created here: 'initial_grid'
     * when called from the initial setup path (TradingEngineService) and
     * 'rebalance' when called from AdjustGridJob. Null keeps the column unset
     * for any caller that predates role wiring.
     */
    public function applyForBot(int $botId, array $diff, bool $simulation = true, ?string $role = null): void
    {
        $symbol = (string) ($diff['symbol'] ?? 'UNKNOWN');
        $tick   = max(1, (int) ($diff['tick'] ?? 1));

        $minIrt = (int) ($diff['min_order_value_irt'] ?? null);
        if (empty($minIrt)) {
            $minIrt = (int) config('trading.min_order_value_irt');
            if (empty($minIrt)) {
                Log::channel('trading')->warning('GridOrderExecutor: min_order_value_irt not loaded from config, using fallback: 3,000,000 IRT');
                $minIrt = 3_000_000;
            }
        }

        $placed = 0; $cancelled = 0; $errors = 0;

        /* =============================================================
         * 1) لغو سفارش‌هایی که «در پلن جدید نیستند»
         * ============================================================= */
        foreach ((array) ($diff['to_cancel'] ?? []) as $o) {
            $oid = is_array($o) ? (string) ($o['id'] ?? '') : (string) $o;
            if ($oid === '') {
                Log::channel('trading')->warning('EXEC_CANCEL_SKIP_NO_ID', ['symbol'=>$symbol,'order'=>$o]);
                continue;
            }

            if ($simulation) {
                Log::channel('trading')->info('EXEC_SIM_CANCEL', ['symbol'=>$symbol,'id'=>$oid,'price'=>is_array($o) ? ($o['price'] ?? null) : null]);
                $cancelled++;
                continue;
            }

            try {
                // Exchange cancel call happens first, outside of any DB transaction.
                // The local GridOrder record is only flipped to 'cancelled' once the
                // exchange has confirmed the cancellation, immediately afterward —
                // never before, and never if the call below throws.
                $this->svc->cancelOrder($oid);
                $this->reg->forget($symbol, $oid);

                $updated = GridOrder::where('bot_config_id', $botId)
                    ->where('nobitex_order_id', $oid)
                    ->update(['status' => 'cancelled']);

                if ($updated === 0) {
                    Log::channel('trading')->warning('EXEC_CANCEL_NO_LOCAL_RECORD', [
                        'symbol' => $symbol, 'bot_id' => $botId, 'id' => $oid,
                    ]);
                }

                $cancelled++;
                Log::channel('trading')->info('EXEC_CANCEL_OK', ['symbol'=>$symbol,'id'=>$oid]);
                usleep(250_000);
            } catch (\Throwable $e) {
                // Cancel call failed or was ambiguous (e.g. timeout) — leave the
                // local GridOrder record in its current state and log for
                // investigation. Do NOT mark it 'cancelled' here: we don't know
                // whether the exchange actually cancelled it.
                $errors++;
                Log::channel('trading')->error('EXEC_CANCEL_ERR', ['symbol'=>$symbol,'err'=>$e->getMessage(),'order'=>$o]);
            }
        }

        /* =============================================================
         * 2) ثبت سفارش‌های «در پلن»
         * ============================================================= */
        foreach ((array) ($diff['to_place'] ?? []) as $levelIdx => $p) {
            $side     = strtolower((string)($p['side'] ?? ''));
            $price    = (int)   ($p['price'] ?? 0);
            $quantity = (string)($p['quantity'] ?? '0');

            $notional = (int)   ($p['notional'] ?? 0);
            if ($notional <= 0 && $price > 0 && (float)$quantity > 0) {
                $notional = (int) floor($price * (float) $quantity);
            }

            if ($side === '' || $price <= 0 || (float)$quantity <= 0.0) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_INVALID', ['symbol'=>$symbol,'plan'=>$p]);
                continue;
            }

            if ($notional < $minIrt) {
                Log::channel('trading')->warning('EXEC_SKIP_BELOW_MIN', compact('symbol','side','price','quantity','notional','minIrt'));
                continue;
            }

            $price = $this->roundToTick($price, $tick);
            [$src, $dst] = $this->splitSymbol($symbol);

            // Deterministic identifier based on the grid level's stable identity
            // (bot+symbol+side+price), not the transient $levelIdx loop index.
            $clientOrderId = GridOrder::buildClientOrderId($botId, $symbol, $side, $price);

            if ($simulation) {
                // Same dedup guard as the live path — skip if an active order
                // with this id already exists, so simulation re-runs of the same
                // plan don't pile up duplicate rows.
                $existing = GridOrder::where('bot_config_id', $botId)
                    ->where('client_order_id', $clientOrderId)
                    ->whereIn('status', ['pending', 'placed', 'filled', 'partially_filled'])
                    ->first();

                if ($existing) {
                    Log::channel('trading')->info('DEDUP_SKIP', [
                        'bot_id'           => $botId,
                        'client_order_id'  => $clientOrderId,
                        'existing_order_id'=> $existing->id,
                        'existing_status'  => $existing->status,
                    ]);
                    continue;
                }

                // Persist a GridOrder row for the simulated order. Status is set
                // DIRECTLY to 'placed' (there is no real API call that would later
                // flip it from 'pending'), making it visible to both
                // CheckTradesJob::checkSimulatedOrders() and the Filament panels.
                // nobitex_order_id uses a SIM-* sentinel so it is clearly
                // distinguishable from real orders and never collides with a real
                // Nobitex order id. No real exchange call is made.
                GridOrder::create([
                    'bot_config_id'    => $botId,
                    'price'            => $price,
                    'amount'           => $quantity,
                    'type'             => $side,
                    'status'           => 'placed',
                    'client_order_id'  => $clientOrderId,
                    'nobitex_order_id' => 'SIM-' . uniqid(),
                    'role'             => $role,
                ]);

                Log::channel('trading')->info('EXEC_SIM_PLACE', [
                    'symbol'=>$symbol,'side'=>$side,'price'=>$price,'quantity'=>$quantity,'notional'=>$notional,
                    'src'=>$src,'dst'=>$dst,'client_order_id'=>$clientOrderId,
                ]);
                $placed++;
                continue;
            }

            // Dedup guard — skip if an active order with this id already exists.
            $existing = GridOrder::where('bot_config_id', $botId)
                ->where('client_order_id', $clientOrderId)
                ->whereIn('status', ['pending', 'placed', 'filled', 'partially_filled'])
                ->first();

            if ($existing) {
                Log::channel('trading')->info('DEDUP_SKIP', [
                    'bot_id'           => $botId,
                    'client_order_id'  => $clientOrderId,
                    'existing_order_id'=> $existing->id,
                    'existing_status'  => $existing->status,
                ]);
                continue;
            }

            $gridOrder = null;
            $apiCallAttempted = false;
            try {
                // Persist intent row BEFORE calling the exchange so a timeout retry
                // will find this record and skip instead of creating a duplicate.
                $gridOrder = GridOrder::create([
                    'bot_config_id'   => $botId,
                    'price'           => $price,
                    'amount'          => $quantity,
                    'type'            => $side,
                    'status'          => 'pending',
                    'client_order_id' => $clientOrderId,
                    'role'            => $role,
                ]);

                $sideEnum = $side === 'buy' ? OrderSide::BUY : OrderSide::SELL;

                $dto = new CreateOrderDto(
                    side:        $sideEnum,
                    execution:   ExecutionType::LIMIT,
                    srcCurrency: $src,
                    dstCurrency: $dst,
                    amountBase:  $quantity,
                    priceIRT:    $price,
                    clientRef:   $clientOrderId,
                );

                $apiCallAttempted = true;
                $resp    = $this->svc->createOrder($dto);
                $orderId = $resp->orderId ?? null;

                $gridOrder->update([
                    'status'           => 'placed',
                    'nobitex_order_id' => $orderId ? (string) $orderId : null,
                ]);

                if ($orderId) {
                    $this->reg->remember($symbol, [
                        'id'       => (string) $orderId,
                        'side'     => $side,
                        'price'    => $price,
                        'quantity' => $quantity,
                    ]);
                }

                $placed++;
                Log::channel('trading')->info('EXEC_PLACE_OK', [
                    'symbol'=>$symbol,'side'=>$side,'price'=>$price,'quantity'=>$quantity,
                    'orderId'=>$orderId,'client_order_id'=>$clientOrderId,
                ]);
                usleep(300_000);

            } catch (\Throwable $e) {
                $errors++;
                if ($gridOrder && $gridOrder->exists) {
                    // If the exchange API call was never reached (e.g. DTO build failed,
                    // or the order never left our process), it is safe to mark 'cancelled'.
                    // If the call was attempted, we cannot tell whether Nobitex received
                    // and placed the order before the exception (timeout, dropped
                    // response, etc.), so the local record must NOT be marked 'cancelled' —
                    // that would risk a duplicate order being placed later for what is
                    // actually still a live exchange order. Such rows are left in
                    // 'submission_unknown' and require manual or automated reconciliation
                    // (checking directly with Nobitex) before being treated as cancelled
                    // or active. Building that reconciliation job is out of scope here.
                    $gridOrder->update([
                        'status' => $apiCallAttempted ? 'submission_unknown' : 'cancelled',
                    ]);
                }
                Log::channel('trading')->error('EXEC_PLACE_ERR', ['symbol'=>$symbol,'err'=>$e->getMessage(),'plan'=>$p,'api_call_attempted'=>$apiCallAttempted]);
            }
        }

        Log::channel('trading')->info('EXEC_APPLY_SUMMARY', [
            'bot_id'    => $botId,
            'symbol'    => $symbol,
            'placed'    => $placed,
            'cancelled' => $cancelled,
            'errors'    => $errors,
            'simulation'=> $simulation,
        ]);

        if ($simulation) {
            Log::channel('trading')->info('EXEC_DRY_RUN', [
                'symbol'    => $symbol,
                'to_place'  => count($diff['to_place'] ?? []),
                'to_cancel' => count($diff['to_cancel'] ?? []),
                'note'      => 'simulation=true → no real orders',
            ]);
        }
    }

    /**
     * Legacy entry point kept for backward compatibility.
     * Does not create GridOrder records or perform dedup checks.
     * Production code should call applyForBot() instead.
     */
    public function apply(array $diff, bool $simulation = true): void
    {
        $symbol = (string) ($diff['symbol'] ?? 'UNKNOWN');
        $tick   = max(1, (int) ($diff['tick'] ?? 1));

        $minIrt = (int) ($diff['min_order_value_irt'] ?? null);
        if (empty($minIrt)) {
            $minIrt = (int) config('trading.min_order_value_irt');
            if (empty($minIrt)) {
                Log::channel('trading')->warning('GridOrderExecutor: min_order_value_irt not loaded from config, using fallback: 3,000,000 IRT');
                $minIrt = 3_000_000;
            }
        }

        $placed = 0; $cancelled = 0; $errors = 0;

        foreach ((array) ($diff['to_cancel'] ?? []) as $o) {
            $oid = is_array($o) ? (string) ($o['id'] ?? '') : (string) $o;
            if ($oid === '') {
                Log::channel('trading')->warning('EXEC_CANCEL_SKIP_NO_ID', ['symbol'=>$symbol,'order'=>$o]);
                continue;
            }

            if ($simulation) {
                Log::channel('trading')->info('EXEC_SIM_CANCEL', ['symbol'=>$symbol,'id'=>$oid,'price'=>is_array($o) ? ($o['price'] ?? null) : null]);
                $cancelled++;
                continue;
            }

            try {
                $this->svc->cancelOrder($oid);
                $this->reg->forget($symbol, $oid);
                $cancelled++;
                Log::channel('trading')->info('EXEC_CANCEL_OK', ['symbol'=>$symbol,'id'=>$oid]);
                usleep(250_000);
            } catch (\Throwable $e) {
                $errors++;
                Log::channel('trading')->error('EXEC_CANCEL_ERR', ['symbol'=>$symbol,'err'=>$e->getMessage(),'order'=>$o]);
            }
        }

        foreach ((array) ($diff['to_place'] ?? []) as $p) {
            $side     = strtolower((string)($p['side'] ?? ''));
            $price    = (int)   ($p['price'] ?? 0);
            $quantity = (string)($p['quantity'] ?? '0');

            $notional = (int)   ($p['notional'] ?? 0);
            if ($notional <= 0 && $price > 0 && (float)$quantity > 0) {
                $notional = (int) floor($price * (float) $quantity);
            }

            if ($side === '' || $price <= 0 || (float)$quantity <= 0.0) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_INVALID', ['symbol'=>$symbol,'plan'=>$p]);
                continue;
            }

            if ($notional < $minIrt) {
                Log::channel('trading')->warning('EXEC_SKIP_BELOW_MIN', compact('symbol','side','price','quantity','notional','minIrt'));
                continue;
            }

            $price = $this->roundToTick($price, $tick);
            [$src, $dst] = $this->splitSymbol($symbol);

            if ($simulation) {
                Log::channel('trading')->info('EXEC_SIM_PLACE', [
                    'symbol'=>$symbol,'side'=>$side,'price'=>$price,'quantity'=>$quantity,'notional'=>$notional,
                    'src'=>$src,'dst'=>$dst
                ]);
                $placed++;
                continue;
            }

            try {
                $sideEnum = $side === 'buy' ? OrderSide::BUY : OrderSide::SELL;

                $dto = new CreateOrderDto(
                    side:        $sideEnum,
                    execution:   ExecutionType::LIMIT,
                    srcCurrency: $src,
                    dstCurrency: $dst,
                    amountBase:  (string) $quantity,
                    priceIRT:    $price,
                );

                $resp = $this->svc->createOrder($dto);
                $orderId = $resp->orderId ?? null;

                if ($orderId) {
                    $this->reg->remember($symbol, [
                        'id'       => (string) $orderId,
                        'side'     => $side,
                        'price'    => $price,
                        'quantity' => (string) $quantity,
                    ]);
                }

                $placed++;
                Log::channel('trading')->info('EXEC_PLACE_OK', [
                    'symbol'=>$symbol,'side'=>$side,'price'=>$price,'quantity'=>$quantity,'orderId'=>$orderId,
                ]);
                usleep(300_000);

            } catch (\Throwable $e) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_ERR', ['symbol'=>$symbol,'err'=>$e->getMessage(),'plan'=>$p]);
            }
        }

        Log::channel('trading')->info('EXEC_APPLY_SUMMARY', [
            'symbol'    => $symbol,
            'placed'    => $placed,
            'cancelled' => $cancelled,
            'errors'    => $errors,
            'simulation'=> $simulation,
        ]);

        if ($simulation) {
            Log::channel('trading')->info('EXEC_DRY_RUN', [
                'symbol'    => $symbol,
                'to_place'  => count($diff['to_place'] ?? []),
                'to_cancel' => count($diff['to_cancel'] ?? []),
                'note'      => 'simulation=true → no real orders',
            ]);
        }
    }

    /**
     * BTCIRT, ETHUSDT, USDTIRT, BTC-IRT, ... → ['btc', 'rls'|'usdt'|...]
     *
     * برای endpoint های خصوصی ثبت/لغو سفارش، بازار ریالی باید "rls" باشد.
     */
    public static function splitSymbol(string $symbol): array
    {
        $s = strtolower(str_replace('-', '', trim($symbol)));
        if ($s === '' || strlen($s) < 6) {
            throw new \InvalidArgumentException("Bad symbol: {$symbol}");
        }
        if (str_ends_with($s, 'irt')) {
            return [substr($s, 0, -3), 'rls']; // IRT → RLS برای ثبت سفارش
        }
        if (str_ends_with($s, 'usdt')) {
            return [substr($s, 0, -4), 'usdt'];
        }
        if (strlen($s) === 6) {
            return [substr($s, 0, 3), substr($s, 3)];
        }
        throw new \InvalidArgumentException("Unsupported symbol: {$symbol}");
    }

    /** رُند کردن قیمت روی مضارب tick (پیش‌فرض: کف تیک) */
    protected function roundToTick(int $price, int $tick): int
    {
        $tick = max(1, $tick);
        return (int) (floor($price / $tick) * $tick);
    }

}
