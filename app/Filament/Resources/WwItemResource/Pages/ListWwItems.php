<?php
namespace App\Filament\Resources\WwItemResource\Pages;
use App\Filament\Resources\WwItemResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListWwItems extends ListRecords { protected static string $resource = WwItemResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; } }
