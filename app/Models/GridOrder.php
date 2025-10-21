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
        'price'     => 'decimal:0',
        'filled_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(GridRun::class, 'run_id');
    }

    public function botConfig(): BelongsTo
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }
}
