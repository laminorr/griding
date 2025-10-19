<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\GridRun;
use App\Models\GridEvent;
use App\Models\GridRunOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * GridRunRecorder
 * - ساخت GridRun همراه با trace_id (داخل مدل GridRun تولید می‌شود)
 * - ثبت رویدادها در GridEvent
 * - الصاق plan / diff / summary به GridRun
 * - ثبت سفارش‌های مرتبط با اجرای فعلی در grid_run_orders
 *
 * نکته مهم: برای جلوگیری از سرریز اعداد بزرگ (مثل price_irt و exchange_order_id)
 * همه‌ی مقادیر حساس به صورت string/decimal ذخیره می‌شوند.
 */
class GridRunRecorder
{
    protected GridRun $run;

    /** شروع یک اجرا و برگرداندن رکوردر */
    public static function start(array $attrs): self
    {
        $defaults = [
            'symbol'     => 'BTCIRT',
            'mode'       => 'buy',       // buy|sell|both
            'levels'     => 3,
            'step_pct'   => 0.250,
            'budget_irt' => 24_000_000,
            'simulation' => true,
            'status'     => 'running',
            'started_at' => now(),
        ];

        $payload = array_merge($defaults, Arr::only($attrs, [
            'bot_id', 'symbol', 'mode', 'levels', 'step_pct', 'budget_irt', 'simulation', 'status', 'started_at',
        ]));

        /** @var GridRun $run */
        $run = GridRun::create($payload);

        $self = new self($run);
        $self->event('RunStarted', [
            'trace_id'   => $run->trace_id,
            'symbol'     => $run->symbol,
            'mode'       => $run->mode,
            'levels'     => $run->levels,
            'step_pct'   => (string) $run->step_pct,
            'budget_irt' => (int) $run->budget_irt,
            'simulation' => (bool) $run->simulation,
        ]);

        return $self;
    }

    public function __construct(GridRun $run)
    {
        $this->run = $run;
    }

    public function run(): GridRun
    {
        return $this->run;
    }

    /** ثبت یک رویداد در تایم‌لاین اجرا */
    public function event(string $type, array $payload = [], string $severity = 'info'): self
    {
        GridEvent::create([
            'run_id'       => $this->run->id,
            'type'         => $type,
            'severity'     => $severity,
            'payload_json' => $payload,
            'ts'           => now(),
        ]);

        return $this;
    }

    /** الصاق Plan به اجرا */
    public function attachPlan(array $plan): self
    {
        $this->run->plan_json = $plan;
        $this->run->save();

        $this->event('PlanAttached', [
            'items' => count($plan['items'] ?? []),
        ]);

        return $this;
    }

    /** الصاق Diff به اجرا */
    public function attachDiff(array $diff): self
    {
        $this->run->diff_json = $diff;
        $this->run->save();

        $this->event('DiffAttached', $diff['counts'] ?? []);

        return $this;
    }

    /** الصاق Summary به اجرا */
    public function attachSummary(array $summary): self
    {
        $this->run->summary_json = $summary;
        $this->run->save();

        $this->event('SummaryAttached', [
            'keys' => array_keys($summary),
        ]);

        return $this;
    }

    /**
     * ثبت یک سفارش (از پاسخ API یا داده‌ی داخلی)
     * - همه‌ی فیلدهای حساس به‌صورت «رشته» ذخیره می‌شوند.
     */
    public function addOrder(array $data): self
    {
        $clientOrderId = (string) ($data['client_order_id'] ?? $data['clientOrderId'] ?? Str::uuid()->toString());
        $exchangeOrderId = isset($data['exchange_order_id'])
            ? (string) $data['exchange_order_id']
            : (isset($data['id']) ? (string) $data['id'] : null);

        $side   = strtolower((string) ($data['side'] ?? 'buy'));
        $status = (string) ($data['status'] ?? 'Active');

        // قیمت حتماً به صورت رشته (برای DECIMAL(20,0) در DB)
        $priceIrt = (string) ($data['price_irt'] ?? $data['price'] ?? '0');

        // مقادیر مقدار/تطبیق‌ها هم به صورت رشته (decimal strings)
        $amount    = (string) ($data['amount'] ?? $data['qty'] ?? $data['quantity'] ?? '0');
        $matched   = (string) ($data['matched'] ?? $data['matchedAmount'] ?? '0');
        $unmatched = (string) ($data['unmatched'] ?? $data['unmatchedAmount'] ?? '0');

        $raw = $data['raw'] ?? $data;

        GridRunOrder::create([
            'run_id'            => $this->run->id,
            'client_order_id'   => $clientOrderId,
            'exchange_order_id' => $exchangeOrderId,  // VARCHAR در DB
            'side'              => $side,
            'status'            => $status,
            'price_irt'         => $priceIrt,         // DECIMAL در DB
            'amount'            => $amount,
            'matched'           => $matched,
            'unmatched'         => $unmatched,
            'raw_json'          => $raw,
        ]);

        $this->event('OrderRecorded', [
            'clientOrderId' => $clientOrderId,
            'exchangeId'    => $exchangeOrderId,
            'side'          => $side,
            'status'        => $status,
            'price_irt'     => $priceIrt,
            'amount'        => $amount,
        ]);

        return $this;
    }

    /**
     * کمک‌متد: ثبت سفارش از آبجکت استاندارد API نوبیتکس (کلید 'order')
     */
    public function addOrderFromApi(array $apiResponse): self
    {
        $o = $apiResponse['order'] ?? $apiResponse;

        return $this->addOrder([
            'client_order_id'   => $o['clientOrderId'] ?? null,
            'exchange_order_id' => $o['id'] ?? null,
            'side'              => strtolower($o['type'] ?? 'buy'),
            'status'            => $o['status'] ?? 'Active',
            'price_irt'         => (string) ($o['price'] ?? '0'),
            'amount'            => (string) ($o['amount'] ?? '0'),
            'matched'           => (string) ($o['matchedAmount'] ?? '0'),
            'unmatched'         => (string) ($o['unmatchedAmount'] ?? '0'),
            'raw'               => $o,
        ]);
    }

    /** پایان موفق اجرا */
    public function finish(string $status = 'ok', ?array $summary = null): void
    {
        if ($summary) {
            $this->attachSummary($summary);
        }

        $this->run->status      = $status;
        $this->run->finished_at = now();
        $this->run->save();

        $this->event('RunFinished', ['status' => $status]);
    }

    /** پایان با خطا */
    public function fail(Throwable $e, ?string $code = null): void
    {
        $this->run->status        = 'failed';
        $this->run->error_code    = $code ?: ((string) $e->getCode() ?: 'Exception');
        $this->run->error_message = $e->getMessage();
        $this->run->finished_at   = now();
        $this->run->save();

        $this->event('RunFailed', [
            'error' => $e->getMessage(),
            'code'  => $this->run->error_code,
        ], 'error');
    }
}
