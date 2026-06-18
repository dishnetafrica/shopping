<?php
namespace App\Filament\Resources\WwMachineResource\Pages;
use App\Filament\Resources\WwMachineResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListWwMachines extends ListRecords { protected static string $resource = WwMachineResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; } }
