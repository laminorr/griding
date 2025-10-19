<?php

namespace App\Filament\Resources\GridRunResource\Pages;

use App\Filament\Resources\GridRunResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGridRun extends EditRecord
{
    protected static string $resource = GridRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
