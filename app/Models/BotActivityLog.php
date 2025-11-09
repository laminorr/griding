<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotActivityLog extends Model
{
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
    ];

    public function bot()
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }
}
