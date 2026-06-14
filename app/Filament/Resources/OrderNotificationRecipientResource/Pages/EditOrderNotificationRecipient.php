<?php

namespace App\Filament\Resources\OrderNotificationRecipientResource\Pages;

use App\Filament\Resources\OrderNotificationRecipientResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrderNotificationRecipient extends EditRecord
{
    protected static string $resource = OrderNotificationRecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
