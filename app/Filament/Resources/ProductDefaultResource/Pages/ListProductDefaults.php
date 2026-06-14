<?php

namespace App\Filament\Resources\ProductDefaultResource\Pages;

use App\Filament\Resources\ProductDefaultResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductDefaults extends ListRecords
{
    protected static string $resource = ProductDefaultResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
