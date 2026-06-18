<?php
namespace App\Filament\Resources\WwMaterialResource\Pages;
use App\Filament\Resources\WwMaterialResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListWwMaterials extends ListRecords { protected static string $resource = WwMaterialResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; } }
