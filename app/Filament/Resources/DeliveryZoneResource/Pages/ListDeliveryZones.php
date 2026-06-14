<?php
namespace App\Filament\Resources\DeliveryZoneResource\Pages;
use App\Filament\Resources\DeliveryZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListDeliveryZones extends ListRecords {
    protected static string $resource = DeliveryZoneResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()->label('Add zone')]; }
}
