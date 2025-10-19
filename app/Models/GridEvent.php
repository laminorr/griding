<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GridEvent extends Model
{
    protected $table = 'grid_events';

    protected $fillable = [
        'run_id','type','severity','payload_json','ts',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'ts'           => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(GridRun::class, 'run_id');
    }
}
