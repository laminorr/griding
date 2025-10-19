<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GridRunOrder
 * رکورد هر سفارشِ ثبت‌شده در جریان یک اجرای گرید.
 *
 * نکات:
 * - exchange_order_id به‌صورت string نگه می‌داریم تا سرریز/منفی نشود.
 * - price_irt به‌صورت decimal:0 (رشته) کاست می‌شود.
 * - raw_json به‌صورت array کاست می‌شود تا خودکار JSON شود.
 */
class GridRunOrder extends Model
{
    protected $table = 'grid_run_orders';

    protected $fillable = [
        'run_id',
        'client_order_id',
        'exchange_order_id',
        'side',
        'status',
        'price_irt',
        'amount',
        'matched',
        'unmatched',
        'raw_json',
    ];

    protected $casts = [
        // اعداد بزرگ → رشته
        'exchange_order_id' => 'string',
        'price_irt'         => 'decimal:0',

        // مقادیر اعشاری به‌صورت رشته با دقت
        'amount'    => 'decimal:8',
        'matched'   => 'decimal:8',
        'unmatched' => 'decimal:8',

        // آرایه/آبجکت → JSON در دیتابیس
        'raw_json'  => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(GridRun::class, 'run_id');
    }
}
