<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class GridOrder extends Model
{
    protected $table = 'grid_orders';

    protected $fillable = [
        'client_order_id','exchange_order_id','status',
        'price_irt','amount','matched','unmatched','raw_json',
        'bot_config_id','price','type','nobitex_order_id','paired_order_id','filled_at',
        'original_amount','filled_amount','remaining_amount','average_fill_price','last_fill_at',
        'role',
        'reconcile_attempts','reconcile_not_found_count','reconcile_last_attempt_at',
    ];

    protected $casts = [
        'amount'             => 'decimal:8',
        'matched'            => 'decimal:8',
        'unmatched'          => 'decimal:8',
        'raw_json'           => 'array',
        // NOTE: `price` and `average_fill_price` are DECIMAL(20,0) columns and
        // are deliberately NOT cast here. They were previously cast to
        // 'integer' (Phase 4 / Phase 9), which coerces the value back to a
        // native PHP int on read — a 32-bit-overflow hazard for ~20-digit IRT
        // values and exactly the type the write-side mutators below exist to
        // avoid. Leaving them uncast keeps the full decimal string intact end
        // to end; upstream callers run through the bcmath Money helper and
        // treat these as decimal strings. (Phase 10, Step 7.)
        'filled_at'          => 'datetime',
        'original_amount'    => 'decimal:8',
        'filled_amount'      => 'decimal:8',
        'remaining_amount'   => 'decimal:8',
        'last_fill_at'       => 'datetime',
        'reconcile_attempts'        => 'integer',
        'reconcile_not_found_count' => 'integer',
        'reconcile_last_attempt_at' => 'datetime',
    ];

    /**
     * Force the price to a string so it is bound as PDO::PARAM_STR.
     *
     * Phase 10, Step 7 — upstream calculations were migrated to the bcmath
     * Money helper and now hand us decimal strings natively, so this mutator no
     * longer *converts* anything in the normal flow. It is retained as a
     * defensive normalization + validation guard because the overflow risk
     * lives in the PDO layer, not in our code: Laravel's Connection::bindValues
     * binds a native PHP int as PDO::PARAM_INT, which truncates a value above
     * the signed 32-bit ceiling on 32-bit / emulated-prepare drivers
     * (e.g. 101500000000 -> -1579215104). Passing a string keeps the binding as
     * PARAM_STR so the full DECIMAL(20,0) value is stored intact.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['price'] = $this->normalizeDecimalString('price', $value);
    }

    public function setAverageFillPriceAttribute($value): void
    {
        // Same rationale as setPriceAttribute. Nullable column, so null passes
        // through untouched.
        $this->attributes['average_fill_price'] = $value === null
            ? null
            : $this->normalizeDecimalString('average_fill_price', $value);
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
                "GridOrder::{$column} expects a scalar decimal value, " . gettype($value) . ' given.'
            );
        }

        if (is_int($value) && abs($value) > PHP_INT_MAX / 2) {
            Log::debug('GRID_ORDER_LARGE_NATIVE_INT_BINDING', [
                'column' => $column,
                'value'  => $value,
                'note'   => 'Native int on a DECIMAL(20,0) column — expected a bcmath string. Possible upstream regression.',
            ]);
        }

        return (string) $value;
    }

    /**
     * Validation and guards
     */
    protected static function booted(): void
    {
        static::saving(function (GridOrder $order) {
            // Validate price is positive and within DECIMAL(20,0) limits
            if (!is_numeric($order->price) || $order->price <= 0) {
                throw new \InvalidArgumentException("Invalid price on GridOrder: {$order->price}");
            }

            // Check doesn't exceed decimal(20,0) limit (10^20 - 1)
            if (strlen((string)$order->price) > 20) {
                throw new \InvalidArgumentException("Price exceeds DECIMAL(20,0) limit: {$order->price}");
            }
        });
    }

    /**
     * Build a deterministic client_order_id for a grid order.
     * Format: grid:{botId}:{SYMBOL}:{side}:{priceIrt}
     * Identity is bot+symbol+side+price — the stable properties of a grid
     * level — not a transient array index, so retries/re-runs of the same
     * level always produce the same id.
     * Max length ≤ 64 chars to fit common exchange limits.
     */
    public static function buildClientOrderId(
        int $botId,
        string $symbol,
        string $side,
        int $priceIrt
    ): string {
        return sprintf(
            'grid:%d:%s:%s:%d',
            $botId,
            strtoupper($symbol),
            strtolower($side),
            $priceIrt
        );
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(GridRun::class, 'run_id');
    }

    public function botConfig(): BelongsTo
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }
}
