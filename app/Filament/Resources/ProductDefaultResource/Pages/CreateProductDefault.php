<?php

namespace App\Filament\Resources\ProductDefaultResource\Pages;

use App\Filament\Resources\ProductDefaultResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductDefault extends CreateRecord
{
    protected static string $resource = ProductDefaultResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['term'] = ProductDefaultResource::canonical($data['term'] ?? '');
        $data['source'] = 'owner';
        $data['created_by'] = auth()->id();
        return $data;
    }
}
