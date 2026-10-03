<?php
declare(strict_types=1);

namespace App\Services;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Exceptions\DefinitiveOrderRejection;
use App\Models\GridOrder;
use App\Support\MarketPrecision;
use App\Support\Money;
use App\Support\OrderRegistry;
use App\Support\QtyPrecision;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Statuses of an order that is (or may be) live on the exchange. A second
     * order for the same bot+side+price is refused while one of these exists.
     */
    public const ACTIVE_STATUSES = ['pending', 'placed', 'partially_filled', 'submission_unknown'];

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
     * Creates GridOrder intent rows (each with its own clientOrderId,
     * GridOrder::clientOrderIdFor) before any exchange call, and skips a level
     * that already has an ACTIVE order at the same bot+side+price, so
     * timeout-retries cannot produce duplicate exchange orders.
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
                // Mirror the live branch's local DB effect (:89-91) without touching
                // the real exchange: flip the same GridOrder row to 'cancelled' so a
                // simulated rebalance actually retires stale orders instead of leaving
                // them 'placed' forever alongside the newly-placed sim orders.
                $updated = GridOrder::where('bot_config_id', $botId)
                    ->where('nobitex_order_id', $oid)
                    ->update(['status' => 'cancelled']);

                if ($updated === 0) {
                    Log::channel('trading')->warning('EXEC_SIM_CANCEL_NO_LOCAL_RECORD', [
                        'symbol' => $symbol, 'bot_id' => $botId, 'id' => $oid,
                    ]);
                }

                Log::channel('trading')->info('EXEC_SIM_CANCEL', ['symbol'=>$symbol,'id'=>$oid,'price'=>is_array($o) ? ($o['price'] ?? null) : null]);
                $cancelled++;
                continue;
            }

            try {
                // Exchange cancel call happens first, outside of any DB transaction.
                // The local GridOrder record is only flipped to 'cancelled' once the
                // exchange has confirmed the cancellation, immediately afterward —
                // never before, and never if the call below throws.
                $cancelResult = $this->svc->cancelOrder($oid);

                // Nobitex returns HTTP 200 with status:"failed" when the cancel
                // transition cannot be applied — most importantly when the order
                // already FILLED and is therefore no longer cancellable. Marking
                // the local row 'cancelled' here would silently erase a real
                // fill, so on a failed cancel we NEVER cancel locally: we observe
                // the order's actual state and leave the row in place so
                // CheckTradesJob's next status poll (by numeric id) runs the
                // normal fill accounting / pairing for a filled order, or retries
                // the cancel for one still open.
                if (! $cancelResult->isOk()) {
                    $this->handleFailedCancel($botId, $symbol, $oid, $cancelResult->message);
                    $errors++;
                    continue;
                }

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
                // exact price × quantity on strings; scale 0 truncates == floor for positive
                $notional = (int) Money::mul((string) $price, $quantity, 0);
            }

            if ($side === '' || $price <= 0 || (float)$quantity <= 0.0) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_INVALID', ['symbol'=>$symbol,'plan'=>$p]);
                continue;
            }

            if (Money::compare((string) $notional, (string) $minIrt) < 0) {
                Log::channel('trading')->warning('EXEC_SKIP_BELOW_MIN', compact('symbol','side','price','quantity','notional','minIrt'));
                continue;
            }

            // "The row is what was sent": fit price and amount to the market
            // BEFORE the intent row is written, exactly as the send boundary
            // (CreateOrderDto::toApiPayload) will, so grid_orders never
            // diverges from the exchange. Price: side-safe (buy down, sell
            // up) to the plan tick and the market tick. Amount: floored.
            $price    = $this->roundToTick($price, $tick, $side, $symbol);
            $quantity = QtyPrecision::floor($quantity, $symbol);
            if (! Money::isPositive($quantity)) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_INVALID', ['symbol'=>$symbol,'plan'=>$p,'reason'=>'quantity truncates to zero at the market step']);
                continue;
            }
            [$src, $dst] = $this->splitSymbol($symbol);

            // Per-level lock around dedup + intent-row creation. Each intent
            // now has its own clientOrderId, so the UNIQUE index no longer
            // stops two concurrent runs (e.g. a double-clicked grid start
            // racing a rebalance) from both inserting the same level; this
            // lock does. Held only until the intent row is committed — never
            // across the exchange call. Busy → another run is placing this
            // exact level right now, so skipping is the correct outcome.
            $levelLock = Cache::lock("grid-level:{$botId}:{$side}:{$price}", 10);
            if (! $levelLock->get()) {
                Log::channel('trading')->info('DEDUP_LOCK_BUSY', [
                    'bot_id' => $botId, 'side' => $side, 'price' => $price, 'simulation' => $simulation,
                ]);
                continue;
            }
            $levelLockHeld = true;

            try {
                // Intent dedup: never place a second ACTIVE order for the same
                // bot+side+price. Only live states block — a level whose previous
                // order is 'filled' or 'cancelled' may be placed again (the old
                // clientOrderId-based guard also matched 'filled' rows and, because
                // the id was price-derived, a re-placement then collided with the
                // UNIQUE index). 'submission_unknown' blocks: that order may be live
                // on the exchange until the reconciler says otherwise.
                $existing = GridOrder::where('bot_config_id', $botId)
                    ->where('type', $side)
                    ->where('price', (string) $price)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->first();

                if ($existing) {
                    Log::channel('trading')->info('DEDUP_SKIP', [
                        'bot_id'           => $botId,
                        'side'             => $side,
                        'price'            => $price,
                        'existing_order_id'=> $existing->id,
                        'existing_status'  => $existing->status,
                        'simulation'       => $simulation,
                    ]);
                    continue;
                }

                if ($simulation) {
                    // Persist a GridOrder row for the simulated order. Status is set
                    // DIRECTLY to 'placed' (there is no real API call that would later
                    // flip it from 'pending'), making it visible to both
                    // CheckTradesJob::checkSimulatedOrders() and the Filament panels.
                    // nobitex_order_id uses a SIM-* sentinel so it is clearly
                    // distinguishable from real orders and never collides with a real
                    // Nobitex order id. No real exchange call is made.
                    $simRow = GridOrder::createIntent([
                        'bot_config_id'    => $botId,
                        'price'            => $price,
                        'amount'           => $quantity,
                        'type'             => $side,
                        'status'           => 'placed',
                        'nobitex_order_id' => 'SIM-' . uniqid(),
                        'role'             => $role,
                    ]);

                    Log::channel('trading')->info('EXEC_SIM_PLACE', [
                        'symbol'=>$symbol,'side'=>$side,'price'=>$price,'quantity'=>$quantity,'notional'=>$notional,
                        'src'=>$src,'dst'=>$dst,'client_order_id'=>$simRow->client_order_id,
                    ]);
                    $placed++;
                    continue;
                }

                $gridOrder = null;
                $clientOrderId = null;
                $apiCallAttempted = false;
                try {
                    // Intent row FIRST (committed, with its clientOrderId stamped in
                    // the same transaction), exchange call SECOND. A retry of this
                    // intent finds this row (dedup above / reconciler) and its id —
                    // a new id is never generated for an existing intent.
                    $gridOrder = GridOrder::createIntent([
                        'bot_config_id'   => $botId,
                        'price'           => $price,
                        'amount'          => $quantity,
                        'type'            => $side,
                        'status'          => 'pending',
                        'role'            => $role,
                    ]);
                    $clientOrderId = (string) $gridOrder->client_order_id;

                    // The committed row is now visible to any concurrent dedup.
                    $levelLock->release();
                    $levelLockHeld = false;

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
                        //
                        // Fee model Phase 5: a DefinitiveOrderRejection (Nobitex
                        // answered status "failed" with InsufficientBalance,
                        // SmallOrder, BadPrice, … or the request was refused before
                        // sending) means the order certainly does NOT exist, so it is
                        // 'cancelled' with last_error_code — never parked as
                        // submission_unknown.
                        if ($e instanceof DefinitiveOrderRejection) {
                            $gridOrder->update([
                                'status'             => 'cancelled',
                                'last_error_code'    => $e->errorCode(),
                                'last_error_message' => mb_substr($e->getMessage(), 0, 255),
                            ]);
                        } else {
                            $gridOrder->update([
                                'status' => $apiCallAttempted ? 'submission_unknown' : 'cancelled',
                            ]);
                        }
                    }
                    Log::channel('trading')->error('EXEC_PLACE_ERR', [
                        'symbol' => $symbol, 'err' => $e->getMessage(), 'plan' => $p,
                        'client_order_id' => $clientOrderId,
                        'api_call_attempted' => $apiCallAttempted,
                        'definitive' => $e instanceof DefinitiveOrderRejection,
                        'code' => $e instanceof DefinitiveOrderRejection ? $e->errorCode() : null,
                    ]);
                }
            } finally {
                if ($levelLockHeld) {
                    $levelLock->release();
                }
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

    /**
     * React to a cancel that came back status:"failed" (HTTP 200).
     *
     * Observe the order's ACTUAL state on the exchange (a Done/Filled order is
     * still resolvable by its numeric id, unlike a clientOrderId lookup) and
     * record it. Crucially, this NEVER flips the local GridOrder to 'cancelled':
     *   - if the order FILLED, the local row is left in its live status so
     *     CheckTradesJob's next status poll drives it through the normal
     *     handleFilledOrder path (CompletedTrade booked / continuation pair
     *     created) — the fill is accounted, never lost;
     *   - if it is genuinely still open, the row likewise stays live and the
     *     failed cancel is surfaced for a retry on a later run.
     */
    private function handleFailedCancel(int $botId, string $symbol, string $oid, ?string $message): void
    {
        $observed = $this->observeOrderStatus($oid);

        Log::channel('trading')->warning('CANCEL_FAILED_ORDER_STATE', [
            'bot_id'           => $botId,
            'symbol'           => $symbol,
            'nobitex_order_id' => $oid,
            'observed_status'  => $observed,
            'cancel_message'   => $message,
            'note'             => $observed === 'FILLED'
                ? 'Cancel failed because the order already filled — local row left live for CheckTradesJob to account the fill.'
                : 'Cancel failed; order not confirmed cancelled — local row left live for retry.',
        ]);
    }

    /**
     * Best-effort read of an order's current status enum value by numeric id.
     * Returns 'unknown' when the probe fails or the exchange returns nothing.
     */
    private function observeOrderStatus(string $oid): string
    {
        try {
            $dtos = $this->svc->getOrdersStatus([$oid]);
            $dto  = $dtos[0] ?? null;

            return $dto !== null ? $dto->status->value : 'unknown';
        } catch (\Throwable $e) {
            Log::channel('trading')->warning('CANCEL_FAILED_STATUS_PROBE_ERR', [
                'nobitex_order_id' => $oid,
                'error'            => $e->getMessage(),
            ]);

            return 'unknown';
        }
    }

    /**
     * رُند کردن قیمت روی مضارب tick — side-safe: buy → کف، sell → سقف.
     * First the plan's tick, then the market tick (MarketPrecision), so the
     * stored price is exactly what toApiPayload sends.
     */
    protected function roundToTick(int $price, int $tick, string $side, string $symbol): int
    {
        $up    = $side === 'sell';
        $price = MarketPrecision::alignToTick((string) $price, (string) max(1, $tick), $up);
        return MarketPrecision::roundPrice($price, $symbol, $side);
    }

}
