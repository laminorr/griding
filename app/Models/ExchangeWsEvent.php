<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One event from the Nobitex PRIVATE WebSocket channels (the user's own order
 * and trade events), recorded by nobitex:ws-private. Observe-only: written by
 * App\Services\ExchangeWsEventRecorder via insertOrIgnore, never read to trade.
 */
class ExchangeWsEvent extends Model
{
    public const CHANNEL_ORDERS = 'orders';
    public const CHANNEL_TRADES = 'trades';

    public const RETENTION_DAYS = 30;

    protected $table = 'exchange_ws_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'nobitex_order_id'      => 'integer',
        'trade_id'              => 'integer',
        'event_time_ms'         => 'integer',
        'payload'               => 'array',
        'matched_grid_order_id' => 'integer',
        'received_at'           => 'datetime',
    ];

    /**
     * Delete rows received more than $days ago. Scheduled daily inline
     * (routes/console.php); never throws — returns null on failure.
     */
    public static function pruneOlderThan(int $days = self::RETENTION_DAYS): ?int
    {
        try {
            return static::query()
                ->where('received_at', '<', now()->subDays($days))
                ->delete();
        } catch (Throwable $e) {
            try {
                Log::channel('nobitex')->warning('WS_PRIVATE_PRUNE_FAILED', ['error' => $e->getMessage()]);
            } catch (Throwable) {
                // Never take down the scheduler tick.
            }

            return null;
        }
    }
}
