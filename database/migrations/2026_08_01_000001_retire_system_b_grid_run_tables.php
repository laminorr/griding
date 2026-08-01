<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P1 — retire "System B" (the grid:run / Grid Runs subsystem).
 *
 * System B wrote to grid_runs / grid_run_orders / grid_events, driven ONLY by
 * the CLI `php artisan grid:run` (GridRunOnce -> GridRunRecorder). It was never
 * wired to bot_configs (grid_runs.bot_id was never set) and the panel has
 * unified on System A (bot_configs / grid_orders). The command, recorder,
 * Filament resource and Eloquent models were removed in the same change.
 *
 * We RENAME the tables to a *_retired suffix instead of dropping them, so the
 * existing rows on the host (MySQL) are preserved and fully recoverable via
 * down(). Each rename is guarded with Schema::hasTable() so this is a safe
 * no-op wherever a table is absent — notably the sqlite test schema, which
 * never created grid_runs / grid_events (see GridRunOnceClientRefTest's scope
 * note). On MySQL/InnoDB, renaming the parent grid_runs table automatically
 * updates the child foreign keys in grid_run_orders / grid_events.
 */
return new class extends Migration {
    /** @var array<string,string> original => retired */
    private array $renames = [
        'grid_run_orders' => 'grid_run_orders_retired',
        'grid_events'     => 'grid_events_retired',
        'grid_runs'       => 'grid_runs_retired',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
