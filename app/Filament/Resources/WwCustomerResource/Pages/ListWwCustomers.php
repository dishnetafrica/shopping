<?php
namespace App\Filament\Resources\WwCustomerResource\Pages;
use App\Filament\Resources\WwCustomerResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListWwCustomers extends ListRecords { protected static string $resource = WwCustomerResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; } }
