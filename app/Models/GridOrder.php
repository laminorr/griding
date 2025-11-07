<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GridOrder extends Model
{
    protected $table = 'grid_orders';

    protected $fillable = [
        'run_id','client_order_id','exchange_order_id','side','status',
        'price_irt','amount','matched','unmatched','raw_json',
        'bot_config_id','price','type','nobitex_order_id','paired_order_id','filled_at',
    ];

    protected $casts = [
        'amount'    => 'decimal:8',
        'matched'   => 'decimal:8',
        'unmatched' => 'decimal:8',
        'raw_json'  => 'array',
        'price'     => 'integer',
        'filled_at' => 'datetime',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(GridRun::class, 'run_id');
    }

    public function botConfig(): BelongsTo
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }
}
