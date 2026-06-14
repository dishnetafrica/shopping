<?php
namespace App\Filament\Resources\DeliveryZoneResource\Pages;
use App\Filament\Resources\DeliveryZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
class EditDeliveryZone extends EditRecord {
    protected static string $resource = DeliveryZoneResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }
}
