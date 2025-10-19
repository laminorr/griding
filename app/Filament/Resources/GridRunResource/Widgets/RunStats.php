<?php

namespace App\Filament\Resources\GridRunResource\Widgets;

use App\Models\GridRun;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RunStats extends BaseWidget
{
    public ?GridRun $record = null;

    protected function getStats(): array
    {
        $r = $this->record;

        $events  = $r?->events()->count() ?? 0;
        $orders  = $r?->orders()->count() ?? 0;
        $status  = (string) ($r->status ?? '-');
        $sim     = $r?->simulation ? 'Yes' : 'No';

        return [
            Stat::make('Status', $status)
                ->description('Simulation: ' . $sim)
                ->color(match ($status) {
                    'ok' => 'success',
                    'running' => 'warning',
                    'failed' => 'danger',
                    default => 'gray',
                }),

            Stat::make('Events', (string) $events)
                ->description('Timeline items')
                ->color('info'),

            Stat::make('Orders', (string) $orders)
                ->description('Recorded for this run')
                ->color('primary'),
        ];
    }
}
