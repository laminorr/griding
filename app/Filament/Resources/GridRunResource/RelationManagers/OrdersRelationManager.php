<?php

namespace App\Filament\Resources\GridRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';
    protected static ?string $title = 'Orders';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('side')
                    ->label('Side')
                    ->badge()
                    ->color(fn ($state) => $state === 'buy' ? 'success' : 'danger'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'Active'   => 'warning',
                        'Filled'   => 'success',
                        'Canceled' => 'gray',
                        default    => 'gray',
                    }),

                TextColumn::make('price_irt')
                    ->label('Price (IRT)')
                    ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((int) $state) : (string) $state)
                    ->alignRight(),

                TextColumn::make('amount')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state) => is_numeric($state)
                        ? rtrim(rtrim((string) $state, '0'), '.')
                        : (string) $state)
                    ->alignRight(),

                TextColumn::make('client_order_id')->label('Client ID')->copyable()->wrap(),
                TextColumn::make('exchange_order_id')->label('Exch. ID')->copyable()->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->poll('5s');
    }
}
