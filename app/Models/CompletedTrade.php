<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;
use App\Support\Money;
use Carbon\Carbon;

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
        'notes'
    ];

    protected $casts = [
        'buy_price' => 'decimal:8',
        'sell_price' => 'decimal:8',
        'amount' => 'decimal:8',
        'profit' => 'decimal:8',
        'fee' => 'decimal:8',
        'gross_profit' => 'decimal:8',
        'net_profit' => 'decimal:8',
        'profit_percentage' => 'decimal:4',
        'execution_time_seconds' => 'integer',
        'slippage' => 'decimal:4',
        'market_conditions' => 'array'
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
    // CRITICAL FIX: Force the large IRT decimal(20,0) value columns to string
    // before they are bound, exactly mirroring GridOrder::setPriceAttribute.
    //
    // Without this guard a raw PHP int (e.g. a price like 101500000000) is bound
    // as PDO::PARAM_INT and can truncate to a signed 32-bit value on the driver
    // (101500000000 -> -1579215104). The completed_trades columns are
    // decimal(20,0) and wide enough; stringifying forces a PARAM_STR binding so
    // the full value is stored intact. Applies to every decimal(20,0) column:
    // buy_price, sell_price, profit, fee.

    public function setBuyPriceAttribute($value): void
    {
        // Convert to string to prevent integer overflow in PDO bindings
        $this->attributes['buy_price'] = (string) $value;
    }

    public function setSellPriceAttribute($value): void
    {
        // Convert to string to prevent integer overflow in PDO bindings
        $this->attributes['sell_price'] = (string) $value;
    }

    public function setProfitAttribute($value): void
    {
        // Convert to string to prevent integer overflow in PDO bindings
        $this->attributes['profit'] = (string) $value;
    }

    public function setFeeAttribute($value): void
    {
        // Convert to string to prevent integer overflow in PDO bindings
        $this->attributes['fee'] = (string) $value;
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
     */
    public static function createFromOrders(GridOrder $buyOrder, GridOrder $sellOrder): self
    {
        // Phase 10, Step 2 — every monetary calculation below now runs through
        // the bcmath Money helper on decimal strings instead of native float/int
        // operators. IRT prices are ~20-digit integers (which truncate on some
        // 32-bit PDO paths) and crypto amounts are decimal:8 (which accumulate
        // IEEE-754 rounding error); doing the arithmetic as exact big-decimals
        // removes both classes of error from these user-facing profit numbers.
        //
        // Phase 9, Step 5 — defensive handling of unequal leg amounts.
        //
        // Today's pairing flow (CheckTradesJob::createPairOrderLocked) creates
        // the continuation order with 'amount' => $filledOrder->amount, so the
        // two legs are always equal in the current codebase. This guard exists
        // so that if a future partial-fill flow (or any precision drift) ever
        // produces unequal legs, the booked trade's numbers are correct for the
        // matched quantity (min of the two) instead of silently using the buy
        // side's amount and mispricing the sell notional / gross profit.
        //
        // The residual (buy_amount - sell_amount) is intentionally NOT tracked
        // here — inventory/residual management is a later concern. We just make
        // the divergence loud via a warning log.

        // Extract raw values as canonical decimal strings. GridOrder->price is a
        // large IRT integer and amount is decimal:8; Money::normalize() coerces
        // either representation into a bcmath-safe string with no (float)/(int)
        // cast, so no precision is lost before the arithmetic even begins.
        $buyPrice   = Money::normalize($buyOrder->price);
        $sellPrice  = Money::normalize($sellOrder->price);
        $buyAmount  = Money::normalize($buyOrder->amount);
        $sellAmount = Money::normalize($sellOrder->amount);

        // Book the trade on the matched quantity (min of the two legs).
        $amount = Money::min($buyAmount, $sellAmount);

        if (Money::compare($buyAmount, $sellAmount) !== 0) {
            Log::channel('trading')->warning('COMPLETED_TRADE_UNEQUAL_LEG_AMOUNTS', [
                'buy_order_id'   => $buyOrder->id,
                'sell_order_id'  => $sellOrder->id,
                'buy_amount'     => $buyAmount,
                'sell_amount'    => $sellAmount,
                'matched_amount' => $amount,
                'residual'       => Money::abs(Money::sub($buyAmount, $sellAmount)),
                'note'           => 'Booking trade on matched quantity; residual is not tracked in this step.',
            ]);
        }

        // سود ناخالص (قبل از کسر کارمزد) = (sellPrice − buyPrice) × amount
        $grossProfit = Money::mul(Money::sub($sellPrice, $buyPrice), $amount);

        // محاسبه کارمزد روی هر دو طرف معامله (خرید و فروش).
        //
        // grid_orders هیچ ستون `fee` ندارد، بنابراین نمی‌توان کارمزد را از روی
        // سفارش‌ها خواند. منبع رسمی کارمزد، fee_bps خودِ ربات است (override در
        // سطح هر ربات) و در صورت نبود، مقدار سراسری config('trading.exchange.fee_bps').
        // fee_bps بر حسب basis point است (35 = 0.35% = 0.0035).
        $feeBps  = $buyOrder->botConfig?->fee_bps ?? config('trading.exchange.fee_bps', 35);
        $feeRate = Money::div((string) $feeBps, '10000'); // bps → نرخ (35 bps = 0.0035)

        $buyNotional  = Money::mul($buyPrice, $amount);
        $sellNotional = Money::mul($sellPrice, $amount);
        $totalFee     = Money::mul($feeRate, Money::add($buyNotional, $sellNotional));

        // سود خالص = سود ناخالص − کارمزد دو طرف
        $netProfit = Money::sub($grossProfit, $totalFee);

        // درصد سود ناخالص نسبت به notional خرید. مخرج صفر (قیمت یا مقدار صفر)
        // به‌جای پرتاب DivisionByZeroError، درصد را برابر "0" قرار می‌دهد.
        $profitPercentage = Money::isZero($buyNotional)
            ? '0'
            : Money::mul(Money::div($grossProfit, $buyNotional), '100');

        // محاسبه زمان اجرا
        $executionTime = $sellOrder->updated_at->diffInSeconds($buyOrder->created_at);

        return self::create([
            'bot_config_id' => $buyOrder->bot_config_id,
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buy_price' => $buyPrice,
            'sell_price' => $sellPrice,
            'amount' => $amount,
            'profit' => $netProfit,
            'fee' => $totalFee,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
            'profit_percentage' => $profitPercentage,
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
            ]
        ]);
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