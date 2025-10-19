<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GridRunResource\Pages;
use App\Filament\Resources\GridRunResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\GridRunResource\RelationManagers\OrdersRelationManager;
use App\Models\GridRun;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class GridRunResource extends Resource
{
    protected static ?string $model = GridRun::class;

    protected static ?string $navigationIcon  = 'heroicon-o-rocket-launch';
    protected static ?string $navigationGroup = 'Monitoring';
    protected static ?string $navigationLabel = 'Grid Runs';
    protected static ?string $modelLabel      = 'Grid Run';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->grow(false),

                TextColumn::make('trace_id')
                    ->label('Trace')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),

                TextColumn::make('symbol')->label('Symbol')->badge(),

                TextColumn::make('mode')
                    ->label('Mode')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'buy'  => 'success',
                        'sell' => 'danger',
                        'both' => 'primary',
                        default => 'gray',
                    }),

                TextColumn::make('levels')->label('Lvls')->sortable()->grow(false),

                TextColumn::make('step_pct')
                    ->label('Step %')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 3, '.', ''), '0'), '.')),

                TextColumn::make('budget_irt')
                    ->label('Budget (IRT)')
                    ->formatStateUsing(fn ($state) => number_format((int) $state)),

                IconColumn::make('simulation')->label('Sim')->boolean(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ok'      => 'success',
                        'running' => 'warning',
                        'failed'  => 'danger',
                        default   => 'gray',
                    }),

                // شمارنده‌ها (حتماً اسم پارامتر = $record)
                TextColumn::make('events_count')
                    ->label('Evts')
                    ->state(fn (GridRun $record) => $record->events()->count())
                    ->toggleable(),

                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->state(fn (GridRun $record) => $record->orders()->count())
                    ->toggleable(),

                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('finished_at')->label('Finished')->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('simulation')->label('Simulation'),
                Tables\Filters\SelectFilter::make('status')->options([
                    'running' => 'Running',
                    'ok'      => 'Ok',
                    'failed'  => 'Failed',
                ]),
                Tables\Filters\SelectFilter::make('symbol')->options([
                    'BTCIRT'  => 'BTCIRT',
                    'ETHIRT'  => 'ETHIRT',
                    'USDTIRT' => 'USDTIRT',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGridRuns::route('/'),
            'view'  => Pages\ViewGridRun::route('/{record}'),
        ];
    }
}
