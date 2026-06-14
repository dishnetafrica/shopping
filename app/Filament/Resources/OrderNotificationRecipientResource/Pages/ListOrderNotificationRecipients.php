<?php

namespace App\Filament\Resources\OrderNotificationRecipientResource\Pages;

use App\Filament\Resources\OrderNotificationRecipientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrderNotificationRecipients extends ListRecords
{
    protected static string $resource = OrderNotificationRecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Add recipient')];
    }
}
