<?php

namespace App\Filament\Resources\ProductDefaultResource\Pages;

use App\Filament\Resources\ProductDefaultResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductDefault extends EditRecord
{
    protected static string $resource = ProductDefaultResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['term'] = ProductDefaultResource::canonical($data['term'] ?? '');
        return $data;
    }
}
