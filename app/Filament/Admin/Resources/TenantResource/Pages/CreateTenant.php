<?php

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Filament\Admin\Resources\TenantResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected ?string $ownerName = null;
    protected ?string $ownerPhone = null;

    /** Pull the owner-login fields out before the Tenant is created. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->ownerName  = $data['owner_name'] ?? null;
        $this->ownerPhone = $data['owner_phone'] ?? null;
        unset($data['owner_name'], $data['owner_phone']);

        return $data;
    }

    /** Once the Tenant exists, create the owner login from those fields. */
    protected function afterCreate(): void
    {
        $user = TenantResource::upsertOwner($this->record, $this->ownerName, $this->ownerPhone);
        if ($user) {
            Notification::make()
                ->title('Owner login created')
                ->body($user->phone . ' can now sign in at /app with a WhatsApp code.')
                ->success()->send();
        }
    }
}
