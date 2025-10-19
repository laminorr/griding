<?php

namespace App\Filament\Resources\GridRunResource\Pages;

use App\Filament\Resources\GridRunResource;
use Filament\Resources\Pages\ListRecords;

class ListGridRuns extends ListRecords
{
    protected static string $resource = GridRunResource::class;

    protected function getHeaderActions(): array
    {
        // فعلاً دکمه Create لازم نداریم
        return [];
    }
}