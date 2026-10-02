<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;
use App\Services\FeeModel;
use App\Support\Money;
use App\Support\QtyPrecision;
use Carbon\Carbon;

// NOTE: declare(strict_types=1) intentionally omitted here. Money::normalize()
// coerces float/int inputs to safe decimal strings at all call sites, and
// enabling strict types would change int-to-string coercion behaviour across
// this large model without a proven benefit. Deferred as a documented latent
// risk (Cleanup Phase 4).
class CompletedTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_config_id',
        'buy_order_id',
        'sell_order_id',
        'buy_price',
        'sell_price',
        'amount',
        'profit',
        'fee',
        'gross_profit',
        'net_profit',
        'profit_percentage',
        'execution_time_seconds',
        'market_conditions',
        'trade_type',
        'grid_level_buy',
        'grid_level_sell',
        'slippage',
        'notes',
        // Fee model Phase 6 — per-leg fee breakdown.
        'buy_fee_amount', 'buy_fee_currency', 'buy_fee_quote',
        'sell_fee_amount', 'sell_fee_currency', 'sell_fee_quote',
        'fee_source', 'buy_filled_amount', 'sell_filled_amount', 'base_residual',
        'fee_model_version', 'profit_v0', 'net_profit_v0',
    ];

    protected $casts = [
        'buy_price' => 'decimal:8',
        'sell_price' => 'decimal:8',
        'amount' => 'decimal:8',
        'profit' => 'decimal:0',
        'fee' => 'decimal:8',
        'gross_profit' => 'decimal:8',
        'net_profit' => 'decimal:8',
        'profit_percentage' => 'decimal:4',
        'execution_time_seconds' => 'integer',
        'slippage' => 'decimal:4',
        'market_conditions' => 'array',
        // Fee model Phase 6 — DECIMAL(36,18), read back as exact strings.
        'buy_fee_amount'     => 'decimal:18',
        'buy_fee_quote'      => 'decimal:18',
        'sell_fee_amount'    => 'decimal:18',
        'sell_fee_quote'     => 'decimal:18',
        'buy_filled_amount'  => 'decimal:18',
        'sell_filled_amount' => 'decimal:18',
        'base_residual'      => 'decimal:18',
        'profit_v0'          => 'decimal:18',
        'net_profit_v0'      => 'decimal:18',
        'fee_model_version'  => 'integer',
    ];

    protected $appends = [
        'profit_toman',
        'volume_toman',
        'is_profitable',
        'roi_percentage',
        'execution_time_formatted'
    ];

    // ========== Attribute Mutators ==========
    //
    // Force the large IRT decimal(20,0) value columns to string before they are
    // bound, exactly mirroring GridOrder::setPriceAttribute.
    //
    // Phase 10, Step 7 — upstream now computes these via the bcmath Money helper
    // and hands us decimal strings natively, so these mutators no longer
    // *convert* anything in the normal flow. They are retained as defensive
    // normalization + validation guards because the overflow risk lives in the
    // PDO layer, not our code: Laravel's Connection::bindValues binds a native
    // PHP int as PDO::PARAM_INT, which truncates a value above the signed 32-bit
    // ceiling on 32-bit / emulated-prepare drivers (101500000000 ->
    // -1579215104). Passing a string keeps the binding as PARAM_STR so the full
    // DECIMAL(20,0) value is stored intact. Applies to every decimal(20,0)
    // column: buy_price, sell_price, profit, fee.

    public function setBuyPriceAttribute($value): void
    {
        $this->attributes['buy_price'] = $this->normalizeDecimalString('buy_price', $value);
    }

    public function setSellPriceAttribute($value): void
    {
        $this->attributes['sell_price'] = $this->normalizeDecimalString('sell_price', $value);
    }

    public function setProfitAttribute($value): void
    {
        $this->attributes['profit'] = $this->normalizeDecimalString('profit', $value);
    }

    public function setFeeAttribute($value): void
    {
        $this->attributes['fee'] = $this->normalizeDecimalString('fee', $value);
    }

    /**
     * Normalize an incoming DECIMAL(20,0) value into a safe string binding.
     *
     * - Rejects arrays/objects (never valid for a numeric column).
     * - Emits a debug regression signal if a native PHP int larger than
     *   PHP_INT_MAX / 2 arrives, which means some caller bypassed the bcmath
     *   Money pipeline and is one 32-bit driver away from silently truncating.
     * - Returns the value as a string so it binds as PARAM_STR.
     */
    private function normalizeDecimalString(string $column, $value): string
    {
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException(
                "CompletedTrade::{$column} expects a scalar decimal value, " . gettype($value) . ' given.'
            );
        }

        if (is_int($value) && abs($value) > PHP_INT_MAX / 2) {
            Log::debug('COMPLETED_TRADE_LARGE_NATIVE_INT_BINDING', [
                'column' => $column,
                'value'  => $value,
                'note'   => 'Native int on a DECIMAL(20,0) column — expected a bcmath string. Possible upstream regression.',
            ]);
        }

        return (string) $value;
    }

    // ========== Relations ==========
    public function botConfig(): BelongsTo
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }

    public function buyOrder(): BelongsTo
    {
        return $this->belongsTo(GridOrder::class, 'buy_order_id');
    }

    public function sellOrder(): BelongsTo
    {
        return $this->belongsTo(GridOrder::class, 'sell_order_id');
    }

    // ========== Scopes ==========
    public function scopeProfitable(Builder $query): Builder
    {
        return $query->where('profit', '>', 0);
    }

    public function scopeLosses(Builder $query): Builder
    {
        return $query->where('profit', '<', 0);
    }

    public function scopeBreakeven(Builder $query): Builder
    {
        return $query->where('profit', '=', 0);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeThisWeek(Builder $query): Builder
    {
        return $query->whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year);
    }

    public function scopeByDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeByBot(Builder $query, $botId): Builder
    {
        return $query->where('bot_config_id', $botId);
    }

    public function scopeHighProfit(Builder $query, $threshold = 1000): Builder
    {
        return $query->where('profit', '>', $threshold);
    }

    public function scopeByTradeType(Builder $query, string $type): Builder
    {
        return $query->where('trade_type', $type);
    }

    // ========== Computed Properties ==========
    
    /**
     * سود به تومان (برای نمایش)
     */
    public function getProfitTomanAttribute(): string
    {
        return number_format($this->profit, 0) . ' تومان';
    }

    /**
     * حجم معامله به تومان
     */
    public function getVolumeTomanAttribute(): float
    {
        return $this->buy_price * $this->amount;
    }

    /**
     * آیا معامله سودآور است؟
     */
    public function getIsProfitableAttribute(): bool
    {
        return $this->profit > 0;
    }

    /**
     * درصد بازدهی سرمایه (ROI)
     */
    public function getRoiPercentageAttribute(): float
    {
        if ($this->volume_toman <= 0) {
            return 0;
        }
        
        return ($this->profit / $this->volume_toman) * 100;
    }

    /**
     * فرمت زمان اجرا
     */
    public function getExecutionTimeFormattedAttribute(): ?string
    {
        // BY DESIGN: a zero-second (same-second fill) duration returns null, so
        // the UI shows an empty duration rather than "0s". The falsy check below
        // treats 0 and null identically. Locked by CompletedTradeExecutionTimeFormatTest.
        if (!$this->execution_time_seconds) {
            return null;
        }

        $minutes = intval($this->execution_time_seconds / 60);
        $seconds = $this->execution_time_seconds % 60;

        if ($minutes > 0) {
            return $minutes . ' دقیقه، ' . $seconds . ' ثانیه';
        }

        return $seconds . ' ثانیه';
    }

    /**
     * رنگ سود برای UI
     */
    public function getProfitColorAttribute(): string
    {
        if ($this->profit > 0) {
            return 'success';
        } elseif ($this->profit < 0) {
            return 'danger';
        }
        
        return 'gray';
    }

    /**
     * آیکون سود
     */
    public function getProfitIconAttribute(): string
    {
        if ($this->profit > 0) {
            return 'heroicon-m-arrow-trending-up';
        } elseif ($this->profit < 0) {
            return 'heroicon-m-arrow-trending-down';
        }
        
        return 'heroicon-m-minus';
    }

    /**
     * اندازه معامله (کوچک، متوسط، بزرگ)
     */
    public function getTradeSizeAttribute(): string
    {
        $volume = $this->volume_toman;
        
        if ($volume >= 50000000) { // 50 میلیون تومان
            return 'بزرگ';
        } elseif ($volume >= 10000000) { // 10 میلیون تومان
            return 'متوسط';
        }
        
        return 'کوچک';
    }

    /**
     * نوع بازار هنگام معامله
     */
    public function getMarketTrendAttribute(): ?string
    {
        if (!$this->market_conditions) {
            return null;
        }

        $conditions = $this->market_conditions;
        
        if (isset($conditions['trend'])) {
            return match($conditions['trend']) {
                'bullish' => 'صعودی',
                'bearish' => 'نزولی',
                'sideways' => 'خنثی',
                default => 'نامشخص'
            };
        }

        return null;
    }

    // ========== Static Methods ==========
    
    /**
     * ایجاد معامله تکمیل شده
     *
     * Fee model Phase 6 — books the cycle from what actually happened on each
     * leg, all in bcmath:
     *
     *   quantities  buyQty / sellQty = filled_amount (else amount)
     *   prices      buyPx / sellPx   = avg_fill_price (else the limit price)
     *   fees        each leg's stored fee (grid_orders.fee_*, Phase 3) — the
     *               exchange's actual fee when captured — else a FeeModel
     *               estimate (exact, not rounded up) at the leg's rate.
     *
     *   Every BASE-currency fee is valued at the BUY fill price (buyPx); every
     *   QUOTE fee is taken as is:
     *     fee   = Σ quote fees + Σ base fees × buyPx                (rial)
     *     gross = (sellPx − buyPx) × sellQty
     *     net   = gross − fee
     *   which is exactly   rialΔ + baseΔ × buyPx   with
     *     rialΔ = sellPx·sellQty − buyPx·buyQty − Σ quote fees
     *     baseΔ = buyQty − buyBaseFee − sellQty − sellBaseFee  (= base_residual)
     *   i.e. the cycle's real cash flow plus the BTC it left behind, valued at
     *   the price that BTC was bought at (docs/fee-audit.md §C1/E5).
     *   Alternative (documented, not used): valuing BTC at the sell price
     *   changes net by ≈ buyBaseFee × (sellPx − buyPx) — ~47k IRT on a
     *   1.25B-IRT 1.5% cycle.
     *
     * Unequal legs are now the NORMAL case (a buy-first exit sells the fee-net
     * amount; a sell-first exit buy restores the fee): `amount` is the
     * quantity round-tripped (sellQty) and the leftover is base_residual. A
     * residual of a whole quantity step or more is unexpected and logged.
     */
    public static function createFromOrders(GridOrder $buyOrder, GridOrder $sellOrder): self
    {
        $booking = self::computeBooking($buyOrder, $sellOrder);

        // محاسبه زمان اجرا
        $executionTime = $buyOrder->created_at->diffInSeconds($sellOrder->updated_at);

        $columns = array_diff_key($booking, array_flip(['buy_fill_price', 'sell_fill_price']));

        return self::create($columns + [
            'bot_config_id' => $buyOrder->bot_config_id,
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            // DECIMAL(20,0): the fill prices to the whole rial (exact values
            // stay on the orders' avg_fill_price).
            'buy_price' => FeeModel::roundHalfUp($booking['buy_fill_price'], 0),
            'sell_price' => FeeModel::roundHalfUp($booking['sell_fill_price'], 0),
            'amount' => $booking['sell_filled_amount'],
            'execution_time_seconds' => $executionTime,
            'trade_type' => 'grid',
            // grid_orders ستون grid_level ندارد (در فاز ۴ عمداً حذف شد) و هیچ
            // بخشی از UI این مقادیر را نمایش نمی‌دهد؛ بنابراین صریحاً null می‌مانند.
            'grid_level_buy' => null,
            'grid_level_sell' => null,
            'market_conditions' => [
                'btc_price_at_trade' => cache('btc_price'),
                'timestamp' => now()->toISOString(),
                'trend' => self::detectMarketTrend()
            ],
        ]);
    }

    /**
     * The fee-model booking of a buy/sell leg pair (no I/O except the
     * residual warning): used by createFromOrders() for new trades and by
     * `php artisan fees:backfill` to recompute legacy rows the same way.
     *
     * @return array<string,mixed> profit/fee/net columns + per-leg breakdown,
     *         plus 'buy_fill_price' / 'sell_fill_price' (not columns; removed
     *         by the callers before persisting where needed)
     */
    public static function computeBooking(GridOrder $buyOrder, GridOrder $sellOrder, bool $logResidual = true): array
    {
        $feeModel = app(FeeModel::class);
        $bot      = $buyOrder->botConfig ?? $sellOrder->botConfig;

        $buyQty  = self::legQty($buyOrder);
        $sellQty = self::legQty($sellOrder);
        $buyPx   = self::legPrice($buyOrder);
        $sellPx  = self::legPrice($sellOrder);

        $buyFee  = self::legFee($feeModel, $bot, $buyOrder, FeeModel::SIDE_BUY, $buyQty, $buyPx);
        $sellFee = self::legFee($feeModel, $bot, $sellOrder, FeeModel::SIDE_SELL, $sellQty, $sellPx);

        // Value each fee in rial — base fees at the BUY fill price.
        $buyFeeQuote  = $buyFee['currency'] === FeeModel::CURRENCY_BASE ? Money::mul($buyFee['amount'], $buyPx) : $buyFee['amount'];
        $sellFeeQuote = $sellFee['currency'] === FeeModel::CURRENCY_BASE ? Money::mul($sellFee['amount'], $buyPx) : $sellFee['amount'];
        $totalFee     = Money::add($buyFeeQuote, $sellFeeQuote);

        $buyBaseFee  = $buyFee['currency'] === FeeModel::CURRENCY_BASE ? $buyFee['amount'] : '0';
        $sellBaseFee = $sellFee['currency'] === FeeModel::CURRENCY_BASE ? $sellFee['amount'] : '0';
        $residual    = Money::sub(Money::sub(Money::sub($buyQty, $buyBaseFee), $sellQty), $sellBaseFee);

        $amount      = $sellQty;
        $grossProfit = Money::mul(Money::sub($sellPx, $buyPx), $amount);
        $netProfit   = Money::sub($grossProfit, $totalFee);

        $source = $buyFee['source'] === $sellFee['source'] ? $buyFee['source'] : 'mixed';

        $symbol = (string) ($bot?->symbol ?? 'BTCIRT');
        if ($logResidual && Money::compare(Money::abs($residual), QtyPrecision::step($symbol)) >= 0) {
            Log::channel('trading')->warning('COMPLETED_TRADE_BASE_RESIDUAL', [
                'buy_order_id'  => $buyOrder->id,
                'sell_order_id' => $sellOrder->id,
                'buy_qty'       => $buyQty,
                'sell_qty'      => $sellQty,
                'buy_base_fee'  => $buyBaseFee,
                'sell_base_fee' => $sellBaseFee,
                'residual'      => $residual,
                'note'          => 'Cycle left >= one quantity step of base behind (expected: < 1 step). Booked at the buy price.',
            ]);
        }

        // درصد سود ناخالص نسبت به ارزش خرید همان مقدار. مخرج صفر → "0".
        $buyNotional      = Money::mul($buyPx, $amount);
        $profitPercentage = Money::isZero($buyNotional)
            ? '0'
            : Money::mul(Money::div($grossProfit, $buyNotional), '100');

        return [
            'profit'             => $netProfit,
            'fee'                => $totalFee,
            'gross_profit'       => $grossProfit,
            'net_profit'         => $netProfit,
            'profit_percentage'  => $profitPercentage,
            'buy_fee_amount'     => $buyFee['amount'],
            'buy_fee_currency'   => $buyFee['currency'],
            'buy_fee_quote'      => $buyFeeQuote,
            'sell_fee_amount'    => $sellFee['amount'],
            'sell_fee_currency'  => $sellFee['currency'],
            'sell_fee_quote'     => $sellFeeQuote,
            'fee_source'         => $source,
            'buy_filled_amount'  => $buyQty,
            'sell_filled_amount' => $sellQty,
            'base_residual'      => $residual,
            'fee_model_version'  => $feeModel->modelVersion(),
            'buy_fill_price'     => $buyPx,
            'sell_fill_price'    => $sellPx,
        ];
    }

    /** A leg's matched quantity: filled_amount when positive, else amount. */
    private static function legQty(GridOrder $o): string
    {
        $filled = $o->filled_amount !== null ? Money::normalize($o->filled_amount) : '0';
        return Money::isPositive($filled) ? Money::trimZeros($filled) : Money::trimZeros(Money::normalize($o->amount ?? '0'));
    }

    /** A leg's fill price: exact avg_fill_price when known, else the limit price. */
    private static function legPrice(GridOrder $o): string
    {
        $avg = $o->avg_fill_price !== null ? Money::normalize($o->avg_fill_price) : '0';
        return Money::isPositive($avg) ? Money::trimZeros($avg) : Money::trimZeros(Money::normalize($o->price));
    }

    /**
     * A leg's fee: the stored per-order fee (Phase 3) when present, else an
     * exact FeeModel estimate at that leg's rate.
     *
     * @return array{amount:string, currency:string, source:string}
     */
    private static function legFee(FeeModel $fm, ?BotConfig $bot, GridOrder $o, string $side, string $qty, string $px): array
    {
        if ($o->fee_amount !== null && in_array($o->fee_currency, [FeeModel::CURRENCY_BASE, FeeModel::CURRENCY_QUOTE], true)) {
            return [
                'amount'   => Money::trimZeros(Money::normalize($o->fee_amount)),
                'currency' => (string) $o->fee_currency,
                'source'   => $o->fee_source === FeeModel::SOURCE_ACTUAL ? FeeModel::SOURCE_ACTUAL : FeeModel::SOURCE_ESTIMATED,
            ];
        }
        $est = $fm->estimate($side, $qty, $px, $bot, roundUp: false);
        return ['amount' => $est['amount'], 'currency' => $est['currency'], 'source' => FeeModel::SOURCE_ESTIMATED];
    }

    /**
     * تشخیص روند بازار
     */
    private static function detectMarketTrend(): string
    {
        // گرفتن قیمت‌های اخیر از cache یا API
        $currentPrice = cache('btc_price', 0);
        $priceHour = cache('btc_price_1h_ago', $currentPrice);
        
        if ($currentPrice > $priceHour * 1.02) {
            return 'bullish';
        } elseif ($currentPrice < $priceHour * 0.98) {
            return 'bearish';
        }
        
        return 'sideways';
    }

    // ========== Analysis Methods ==========
    
    /**
     * آمار عملکرد معاملات
     */
    public static function getPerformanceStats($botId = null): array
    {
        $query = self::query();
        
        if ($botId) {
            $query->where('bot_config_id', $botId);
        }

        $trades = $query->get();
        $totalTrades = $trades->count();
        
        if ($totalTrades === 0) {
            return [
                'total_trades' => 0,
                'profitable_trades' => 0,
                'win_rate' => 0,
                'total_profit' => 0,
                'avg_profit' => 0,
                'best_trade' => 0,
                'worst_trade' => 0,
                'avg_execution_time' => 0
            ];
        }

        $profitableTrades = $trades->where('profit', '>', 0);
        
        return [
            'total_trades' => $totalTrades,
            'profitable_trades' => $profitableTrades->count(),
            'win_rate' => ($profitableTrades->count() / $totalTrades) * 100,
            'total_profit' => $trades->sum('profit'),
            'avg_profit' => $trades->avg('profit'),
            'best_trade' => $trades->max('profit'),
            'worst_trade' => $trades->min('profit'),
            'avg_execution_time' => $trades->avg('execution_time_seconds'),
            'total_volume' => $trades->sum('volume_toman'),
            'avg_roi' => $trades->avg('roi_percentage')
        ];
    }

    /**
     * گزارش روزانه
     */
    public static function getDailyReport(Carbon $date, $botId = null): array
    {
        $query = self::whereDate('created_at', $date);
        
        if ($botId) {
            $query->where('bot_config_id', $botId);
        }

        $trades = $query->get();
        
        return [
            'date' => $date->format('Y-m-d'),
            'total_trades' => $trades->count(),
            'total_profit' => $trades->sum('profit'),
            'successful_trades' => $trades->where('profit', '>', 0)->count(),
            'failed_trades' => $trades->where('profit', '<', 0)->count(),
            'best_trade' => $trades->max('profit') ?? 0,
            'worst_trade' => $trades->min('profit') ?? 0
        ];
    }

    // ========== Model Events ==========
    protected static function booted(): void
    {
        // هنگام ایجاد معامله، آمار را آپدیت کن
        static::created(function (CompletedTrade $trade) {
            // می‌توان اینجا کش آمار را پاک کرد
            cache()->forget("bot_stats_{$trade->bot_config_id}");
            cache()->forget('daily_profit_' . today()->format('Y-m-d'));
        });
    }

    // ========== Validation Rules ==========
    public static function validationRules(): array
    {
        return [
            'bot_config_id' => 'required|exists:bot_configs,id',
            'buy_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0.00000001',
            'profit' => 'required|numeric',
            'fee' => 'nullable|numeric|min:0',
            'trade_type' => 'nullable|string|in:grid,manual,stop_loss,take_profit'
        ];
    }
}