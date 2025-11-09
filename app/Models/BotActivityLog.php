<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_config_id',
        'action_type',
        'level',
        'message',
        'details',
        'api_request',
        'api_response',
        'execution_time',
    ];

    protected $casts = [
        'details' => 'array',
        'api_request' => 'array',
        'api_response' => 'array',
        'execution_time' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Action type constants
     */
    const ACTION_CHECK_TRADES_START = 'CHECK_TRADES_START';
    const ACTION_CHECK_TRADES_END = 'CHECK_TRADES_END';
    const ACTION_API_CALL = 'API_CALL';
    const ACTION_ORDER_PLACED = 'ORDER_PLACED';
    const ACTION_ORDER_FILLED = 'ORDER_FILLED';
    const ACTION_ORDER_CANCELLED = 'ORDER_CANCELLED';
    const ACTION_PRICE_CHECK = 'PRICE_CHECK';
    const ACTION_GRID_ADJUST = 'GRID_ADJUST';
    const ACTION_ERROR = 'ERROR';

    /**
     * Level constants
     */
    const LEVEL_INFO = 'INFO';
    const LEVEL_SUCCESS = 'SUCCESS';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    /**
     * Relationship with BotConfig
     */
    public function botConfig(): BelongsTo
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }

    /**
     * Scope to get recent logs
     */
    public function scopeRecent($query, int $limit = 100)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    /**
     * Scope to filter by action type
     */
    public function scopeByActionType($query, string $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    /**
     * Scope to filter by level
     */
    public function scopeByLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope to get errors only
     */
    public function scopeErrors($query)
    {
        return $query->where('level', self::LEVEL_ERROR);
    }

    /**
     * Get formatted time ago string
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }
}
