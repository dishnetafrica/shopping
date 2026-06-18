<?php
namespace App\Filament\Resources\WwItemResource\Pages;
use App\Filament\Resources\WwItemResource; use Filament\Resources\Pages\CreateRecord;
class CreateWwItem extends CreateRecord {
    protected static string $resource = WwItemResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array { return WwItemResource::withGram($data); }
}
