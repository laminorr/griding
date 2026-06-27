<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GridOrder extends Model
{
    protected $table = 'grid_orders';

    protected $fillable = [
        'client_order_id','exchange_order_id','status',
        'price_irt','amount','matched','unmatched','raw_json',
        'bot_config_id','price','type','nobitex_order_id','paired_order_id','filled_at',
        'original_amount','filled_amount','remaining_amount','average_fill_price','last_fill_at',
        'role',
    ];

    protected $casts = [
        'amount'             => 'decimal:8',
        'matched'            => 'decimal:8',
        'unmatched'          => 'decimal:8',
        'raw_json'           => 'array',
        'price'              => 'integer',
        'filled_at'          => 'datetime',
        'original_amount'    => 'decimal:8',
        'filled_amount'      => 'decimal:8',
        'remaining_amount'   => 'decimal:8',
        'average_fill_price' => 'integer',
        'last_fill_at'       => 'datetime',
    ];

    /**
     * CRITICAL FIX: Force price to string before save to prevent PDO binding issues
     */
    public function setPriceAttribute($value): void
    {
        // Convert to string to prevent integer overflow in PDO bindings
        $this->attributes['price'] = (string) $value;
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
