<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * مدل تنظیمات ربات (BotConfig)
 * - شامل فیلدهای نسل جدید + فیلدهای قدیمی برای سازگاری
 * - ریلیشن با GridRun و GridRunOrder
 * - اسکوپ‌ها، پراپرتی‌های محاسباتی، اکشن‌های ساده start/stop
 */
class BotConfig extends Model
{
    // ========= Fillable =========
    protected $fillable = [
        // -- نسل جدید
        'name',
        'user_id',              // صاحب ربات
        'symbol',               // مثال: BTCIRT / ETHUSDT
        'mode',                 // buy | sell | both
        'levels',               // تعداد لِول‌ها (per_side)
        'step_pct',             // درصد فاصله بین لول‌ها
        'budget_irt',           // بودجه به ریال (یا quote)
        'simulation',           // Dry-run به صورت پیش‌فرض
        'is_active',            // Start/Stop
        // 'status',            // Computed via getStatusAttribute() accessor, not fillable
        'min_order_value_irt',  // حداقل ارزش سفارش
        'fee_bps',              // کارمزد به bps (مثلاً 35 = 0.35%)
        'qty_decimals',         // تعداد اعشار مقدار
        'tick',                 // سایز تیک قیمت
        'settings_json',        // تنظیمات اضافی (json)
        'last_run_at',
        'last_error_code',
        'last_error_message',

        // -- فیلدهای قدیمی (برای سازگاری)
        'total_capital',
        'active_capital_percent',
        'grid_spacing',
        'grid_levels',
        'center_price',
        'stop_loss_percent',
        'take_profit_percent',
        'max_drawdown_percent',
        'rebalance_threshold',
        'started_at',
        'stopped_at',
        'last_rebalance_at',
        'notes',
    ];

    // ========= Casts =========
    protected $casts = [
        // جدید
        'levels'               => 'integer',
        'step_pct'             => 'decimal:3',
        'budget_irt'           => 'integer',
        'simulation'           => 'boolean',
        'is_active'            => 'boolean',
        'min_order_value_irt'  => 'integer',
        'fee_bps'              => 'integer',
        'qty_decimals'         => 'integer',
        'tick'                 => 'integer',
        'settings_json'        => 'array',
        'last_run_at'          => 'datetime',

        // قدیمی
        'total_capital'        => 'decimal:0',   // IRR - بدون اعشار
        'active_capital_percent' => 'decimal:2',
        'grid_spacing'         => 'decimal:2',
        'center_price'         => 'decimal:0',
        'stop_loss_percent'    => 'decimal:2',
        'take_profit_percent'  => 'decimal:2',
        'max_drawdown_percent' => 'decimal:2',
        'rebalance_threshold'  => 'decimal:2',
        'started_at'           => 'datetime',
        'stopped_at'           => 'datetime',
        'last_rebalance_at'    => 'datetime',
    ];

    // ========= Relations =========

    /**
     * اجرای‌ها (Grid Runs) مربوط به این ربات.
     */
    public function gridRuns(): HasMany
    {
        // از string استفاده می‌کنیم تا اگر مدل‌ها ناموجود بودند، Parse Error ندهد.
        return $this->hasMany('App\Models\GridRun', 'bot_id');
    }

    /**
     * سفارش‌های اجرای گرید از طریق GridRun.
     */
    public function gridRunOrders(): HasManyThrough
    {
        return $this->hasManyThrough(
            'App\Models\GridRunOrder', // مدل مقصد
            'App\Models\GridRun',      // مدل میانی
            'bot_id',                  // FK در GridRun که به این مدل وصل است
            'run_id',                  // FK در GridRunOrder که به GridRun وصل است
            'id',                      // PK این مدل
            'id'                       // PK مدل میانی
        );
    }

    /**
     * آلیاس برای سازگاری با کدهای قدیمی (مثلاً ListBotConfigs).
     */
    public function gridOrders(): HasManyThrough
    {
        return $this->gridRunOrders();
    }

    /**
     * سفارش‌های فعال (placed) از طریق GridRun.
     * این ریلیشن برای جلوگیری از ambiguous column در کوئری‌ها استفاده می‌شود.
     */
    public function activeGridRunOrders(): HasManyThrough
    {
        return $this->hasManyThrough(
            'App\Models\GridRunOrder',
            'App\Models\GridRun',
            'bot_id',      // FK on grid_runs
            'run_id',      // FK on grid_run_orders
            'id',          // Local key on bot_configs
            'id'           // Local key on grid_runs
        )->where('grid_run_orders.status', 'placed');
    }

    /**
     * معاملات تکمیل‌شده (برای آمار سود و …) — در صورت وجود.
     * جدول این مدل باید ستون bot_config_id داشته باشد.
     */
    public function completedTrades(): HasMany
    {
        return $this->hasMany(CompletedTrade::class, 'bot_config_id');
    }

    // ========= Scopes =========

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeInactive(Builder $q): Builder
    {
        return $q->where('is_active', false);
    }

    public function scopeSimulation(Builder $q): Builder
    {
        return $q->where('simulation', true);
    }

    public function scopeSymbol(Builder $q, string $symbol): Builder
    {
        return $q->where('symbol', $symbol);
    }

    public function scopeHasError(Builder $q): Builder
    {
        return $q->whereNotNull('last_error_code');
    }

    // ========= Computed Properties / Helpers =========

    /**
     * متن وضعیت برای UI.
     */
    public function getStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'متوقف';
        }

        // اگر اجرای جاری/اخیر داریم، وضعیت آن را منعکس کنیم
        $lastRun = $this->gridRuns()->latest('started_at')->first();
        if ($lastRun) {
            return match ($lastRun->status) {
                'running' => 'در حال اجرا',
                'ok'      => 'فعال',
                'failed'  => 'خطا در اجرا',
                default   => 'فعال',
            };
        }

        return 'فعال';
    }

    /**
     * رنگ وضعیت برای UI.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'در حال اجرا' => 'warning',
            'فعال'        => 'success',
            'خطا در اجرا' => 'danger',
            'متوقف'       => 'gray',
            default       => 'primary',
        };
    }

    /**
     * تعداد سفارش‌های «Active» (با نگاه به جدول grid_run_orders).
     */
    public function getActiveOrdersCountAttribute(): int
    {
        return (int) $this->gridRunOrders()
            ->where('status', 'Active')
            ->count();
    }

    /**
     * خلاصه‌ی خطای اخیر به شکل کوتاه.
     */
    public function getLastErrorSummaryAttribute(): ?string
    {
        if (! $this->last_error_code && ! $this->last_error_message) {
            return null;
        }
        $code = $this->last_error_code ?: 'Error';
        $msg  = $this->last_error_message ?: '';
        return $msg ? ($code . ': ' . mb_strimwidth($msg, 0, 160, '…', 'UTF-8')) : $code;
    }

    // ========= آماری (در صورت استفاده از CompletedTrade) =========

    public function getTotalProfitAttribute(): float
    {
        return (float) ($this->completedTrades()
            ->selectRaw('COALESCE(SUM(profit - COALESCE(fee, 0)), 0) as net_profit')
            ->value('net_profit') ?? 0);
    }

    public function getTotalTradesCountAttribute(): int
    {
        return (int) $this->completedTrades()->count();
    }

    public function getSuccessfulTradesCountAttribute(): int
    {
        return (int) $this->completedTrades()
            ->whereRaw('profit - COALESCE(fee, 0) > 0')
            ->count();
    }

    public function getWinRateAttribute(): float
    {
        $total = $this->total_trades_count;
        return $total > 0 ? (100.0 * $this->successful_trades_count / $total) : 0.0;
    }

    public function getAvgProfitPerTradeAttribute(): float
    {
        $total = $this->total_trades_count;
        return $total > 0 ? ($this->total_profit / $total) : 0.0;
    }

    public function getTotalReturnPercentAttribute(): float
    {
        $base = (float) ($this->total_capital ?? 0);
        return $base > 0 ? (100.0 * $this->total_profit / $base) : 0.0;
    }

    // ========= کنترل ریسک قدیمی (دلخواه) =========

    public function needsRebalance(): bool
    {
        if (! $this->center_price || ! $this->rebalance_threshold) {
            return false;
        }

        $currentPrice  = cache('btc_current_price', $this->center_price);
        $priceDeviation = abs($currentPrice - (float)$this->center_price) / (float)$this->center_price * 100;

        return $priceDeviation > (float)$this->rebalance_threshold;
    }

    public function shouldStopLoss(): bool
    {
        return $this->stop_loss_percent
            ? ($this->total_return_percent <= -1 * (float)$this->stop_loss_percent)
            : false;
    }

    public function shouldTakeProfit(): bool
    {
        return $this->take_profit_percent
            ? ($this->total_return_percent >= (float)$this->take_profit_percent)
            : false;
    }

    // ========= Actions =========

    public function start(): bool
    {
        if ($this->is_active) {
            return false;
        }

        $this->forceFill([
            'is_active'  => true,
            'started_at' => now(),
            'stopped_at' => null,
        ])->save();

        return true;
    }

    public function stop(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $this->forceFill([
            'is_active' => false,
            'stopped_at' => now(),
        ])->save();

        // اگر بخواهید سفارش‌های Active را نرم‌لغو کنید (بسته به منطق‌تان):
        // $this->gridRunOrders()->where('status','Active')->update(['status'=>'Cancelled']);

        return true;
    }

    /**
     * اجرای فوری (در صورت موجود بودن سرویس BotRunner)
     * - $dry = null => از مقدار simulation مدل استفاده می‌شود
     */
    public function runNow(?bool $dry = null): ?Model
    {
        // از class_exists استفاده می‌کنیم تا اگر سرویس فعلاً ساخته نشده بود، خطا ندهد.
        if (! class_exists('App\Services\Bot\BotRunner')) {
            return null;
        }

        $runner = app('App\Services\Bot\BotRunner');
        return $runner->run($this, $dry);
    }

    // ========= Model Events =========

    protected static function booted(): void
    {
        // اگر is_active خاموش شد، می‌توانید اقداماتی انجام دهید (دلخواه)
        static::updating(function (BotConfig $bot) {
            if ($bot->isDirty('is_active') && ! $bot->is_active) {
                // نمونه: ثبت زمان توقف اگر خالی است
                if (! $bot->stopped_at) {
                    $bot->stopped_at = now();
                }
            }
        });

        // تنظیم پیش‌فرض‌های امن هنگام ساخت (دلخواه)
        static::creating(function (BotConfig $bot) {
            $bot->symbol      = $bot->symbol ?? 'BTCIRT';
            $bot->mode        = $bot->mode   ?? 'buy';
            $bot->levels      = $bot->levels ?? ($bot->grid_levels ?? 3);
            $bot->step_pct    = $bot->step_pct ?? ($bot->grid_spacing ?? 0.250);
            $bot->budget_irt  = $bot->budget_irt ?? ($bot->total_capital ?? 0);
            $bot->simulation  = $bot->simulation ?? true;
        });
    }

    // ========= Validation Rules (برای استفاده در فرم‌ها) =========

    public static function validationRules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'symbol'      => 'required|string|max:32',
            'mode'        => 'required|in:buy,sell,both',
            'levels'      => 'required|integer|min:1|max:200',
            'step_pct'    => 'required|numeric|min:0.01|max:50',
            'budget_irt'  => 'required|integer|min:0',
            'simulation'  => 'boolean',
            'is_active'   => 'boolean',

            // قدیمی
            'total_capital'          => 'nullable|numeric|min:0',
            'active_capital_percent' => 'nullable|numeric|min:0|max:100',
            'grid_spacing'           => 'nullable|numeric|min:0|max:100',
            'grid_levels'            => 'nullable|integer|min:0|max:1000',
            'center_price'           => 'nullable|numeric|min:0',
            'stop_loss_percent'      => 'nullable|numeric|min:0|max:100',
            'take_profit_percent'    => 'nullable|numeric|min:0|max:1000',
            'max_drawdown_percent'   => 'nullable|numeric|min:0|max:100',
            'rebalance_threshold'    => 'nullable|numeric|min:0|max:100',
            'notes'                  => 'nullable|string|max:2000',
        ];
    }

    // ========= Format Helpers =========

    public function formatAmount(float|int $amount): string
    {
        return number_format((float) $amount, 0, '.', ',') . ' IRR';
    }

    public function formatPercent(float|int $percent): string
    {
        return number_format((float) $percent, 2, '.', ',') . '%';
    }
}
