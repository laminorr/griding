<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GridRun extends Model
{
    use HasFactory;

    protected $table = 'grid_runs';

    protected $fillable = [
        'bot_id',
        'trace_id',
        'symbol',
        'mode',
        'levels',
        'step_pct',
        'budget_irt',
        'simulation',
        'status',
        'error_code',
        'error_message',
        'plan_json',
        'diff_json',
        'summary_json',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'levels'       => 'integer',
        'step_pct'     => 'decimal:3',
        'budget_irt'   => 'integer',
        'simulation'   => 'boolean',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'plan_json'    => 'array',
        'diff_json'    => 'array',
        'summary_json' => 'array',
    ];

    // برای نمایش JSONهای خوش‌فرم در صفحه‌ی View
    protected $appends = [
        'plan_json_pretty',
        'diff_json_pretty',
        'summary_json_pretty',
    ];

    // ---------- Relations ----------
    public function events(): HasMany
    {
        return $this->hasMany(GridEvent::class, 'run_id')->orderByDesc('ts');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(GridRunOrder::class, 'run_id')->orderByDesc('id');
    }

    // ---------- Pretty JSON Accessors ----------
    private function prettyJson(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    public function getPlanJsonPrettyAttribute(): ?string
    {
        return $this->prettyJson($this->plan_json);
    }

    public function getDiffJsonPrettyAttribute(): ?string
    {
        return $this->prettyJson($this->diff_json);
    }

    public function getSummaryJsonPrettyAttribute(): ?string
    {
        return $this->prettyJson($this->summary_json);
    }

    // ---------- Defaults ----------
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->trace_id)) {
                $model->trace_id = (string) Str::uuid();
            }
            if (empty($model->status)) {
                $model->status = 'running';
            }
            if (empty($model->started_at)) {
                $model->started_at = now();
            }
        });
    }
}
