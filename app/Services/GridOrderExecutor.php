<?php
declare(strict_types=1);

namespace App\Services;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
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
    public function apply(array $diff, bool $simulation = true): void
    {
        $symbol = (string) ($diff['symbol'] ?? 'UNKNOWN');
        $tick   = max(1, (int) ($diff['tick'] ?? 1));

        // Hard-coded fallback for min_order_value_irt to handle config loading issues
        $minIrt = (int) ($diff['min_order_value_irt'] ?? null);
        if (empty($minIrt)) {
            $minIrt = (int) config('trading.exchange.min_order_value_irt');
            if (empty($minIrt)) {
                Log::channel('trading')->warning('GridOrderExecutor: min_order_value_irt not loaded from config, using fallback: 3,000,000 IRT');
                $minIrt = 3_000_000; // 3M IRT = 300K Toman
            }
        }

        $placed = 0; $cancelled = 0; $errors = 0;

        /* =============================================================
         * 1) لغو سفارش‌هایی که «در پلن جدید نیستند»
         * ============================================================= */
        foreach ((array) ($diff['to_cancel'] ?? []) as $o) {
            $oid = is_array($o) ? (string) ($o['id'] ?? '') : (string) $o; // اجازهٔ ورودی ساده
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
                usleep(250_000); // soft rate-limit
            } catch (\Throwable $e) {
                $errors++;
                Log::channel('trading')->error('EXEC_CANCEL_ERR', ['symbol'=>$symbol,'err'=>$e->getMessage(),'order'=>$o]);
            }
        }

        /* =============================================================
         * 2) ثبت سفارش‌های «در پلن»
         * ============================================================= */
        foreach ((array) ($diff['to_place'] ?? []) as $p) {
            $side     = strtolower((string)($p['side'] ?? ''));
            $price    = (int)   ($p['price'] ?? 0);
            $quantity = (string)($p['quantity'] ?? '0');

            // notional را اگر نبود، خودمان محاسبه می‌کنیم تا گارد حداقل کار کند
            $notional = (int)   ($p['notional'] ?? 0);
            if ($notional <= 0 && $price > 0 && (float)$quantity > 0) {
                // دقت بالا نداریم؛ همین حد کفایت می‌کند چون فقط برای گارد مینیمم است
                $notional = (int) floor($price * (float) $quantity);
            }

            if ($side === '' || $price <= 0 || (float)$quantity <= 0.0) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_INVALID', ['symbol'=>$symbol,'plan'=>$p]);
                continue;
            }

            // گارد ۱: حداقل ارزش ریالی
            if ($notional < $minIrt) {
                Log::channel('trading')->warning('EXEC_SKIP_BELOW_MIN', compact('symbol','side','price','quantity','notional','minIrt'));
                continue;
            }

            // گارد ۲: tick-size → رُند کردن قیمت
            $price = $this->roundToTick($price, $tick);

            // نگاشت نماد به src/dst برای API خصوصی (IRT → RLS)
            [$src, $dst] = $this->splitSymbol($symbol);

            if ($simulation) {
                Log::channel('trading')->info('EXEC_SIM_PLACE', [
                    'symbol'=>$symbol,'side'=>$side,'price'=>$price,'quantity'=>$quantity,'notional'=>$notional,
                    'src'=>$src,'dst'=>$dst
                ]);
                $placed++;
                continue;
            }

            // اجرای واقعی
            try {
                $sideEnum = $side === 'buy' ? OrderSide::BUY : OrderSide::SELL;
                $clientRef = $this->buildClientRef($symbol, $side, $price);

                $dto = new CreateOrderDto(
                    side:        $sideEnum,
                    execution:   ExecutionType::LIMIT,
                    srcCurrency: $src,                // e.g. 'btc'
                    dstCurrency: $dst,                // 'rls' برای بازار ریالی
                    amountBase:  (string) $quantity,  // رشتهٔ ده‌دهی با دقت کوین
                    priceIRT:    $price,              // عدد صحیح IRT
                    clientRef:   $clientRef,
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
                usleep(300_000); // soft rate-limit

            } catch (\Throwable $e) {
                $errors++;
                Log::channel('trading')->error('EXEC_PLACE_ERR', ['symbol'=>$symbol,'err'=>$e->getMessage(),'plan'=>$p]);
            }
        }

        // خلاصه
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
    protected function splitSymbol(string $symbol): array
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

    /** ساخت clientRef استاندارد برای رهگیری */
    protected function buildClientRef(string $symbol, string $side, int $price): string
    {
        return sprintf('grid:%s:%s:%d:%d', strtoupper($symbol), $side === 'buy' ? 'B' : 'S', time(), $price);
    }
}
