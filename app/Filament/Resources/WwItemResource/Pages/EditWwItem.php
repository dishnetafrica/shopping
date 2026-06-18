<?php
namespace App\Filament\Resources\WwItemResource\Pages;
use App\Filament\Resources\WwItemResource; use Filament\Resources\Pages\EditRecord;
class EditWwItem extends EditRecord {
    protected static string $resource = WwItemResource::class;
    protected function mutateFormDataBeforeSave(array $data): array { return WwItemResource::withGram($data); }
}
