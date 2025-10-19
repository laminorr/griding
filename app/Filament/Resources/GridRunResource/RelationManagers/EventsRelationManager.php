<?php

namespace App\Filament\Resources\GridRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';
    protected static ?string $title = 'Timeline (Events)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                TextColumn::make('ts')
                    ->label('Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'RunStarted'              => 'info',
                        'PlanCreated', 'PlanAttached' => 'gray',
                        'DiffComputed', 'DiffAttached' => 'gray',
                        'OrderPlaceRequested'     => 'warning',
                        'OrderRecorded'           => 'success',
                        'SummaryAttached'         => 'primary',
                        'RunFinished'             => 'success',
                        'RunFailed', 'OrderRejected' => 'danger',
                        default                   => 'gray',
                    })
                    ->sortable()
                    ->searchable(),

                IconColumn::make('severity')
                    ->label('Lvl')
                    ->icon(fn ($state) => match ($state) {
                        'error' => 'heroicon-o-x-circle',
                        'warn'  => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-information-circle',
                    })
                    ->color(fn ($state) => match ($state) {
                        'error' => 'danger',
                        'warn'  => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('payload_json')
                    ->label('Payload')
                    ->formatStateUsing(function ($state) {
                        // $state ممکنه آرایه باشه (cast شده). امن نمایش بده:
                        if (is_array($state)) {
                            $s = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            return mb_strimwidth($s, 0, 140, '…');
                        }
                        if (is_object($state)) {
                            $s = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            return mb_strimwidth($s, 0, 140, '…');
                        }
                        return (string) $state;
                    })
                    ->tooltip(function ($state) {
                        if (is_array($state) || is_object($state)) {
                            return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        }
                        return (string) $state;
                    })
                    ->toggleable(),
            ])
            ->defaultSort('ts', 'desc')
            ->paginated(false)
            ->poll('5s');
    }
}
