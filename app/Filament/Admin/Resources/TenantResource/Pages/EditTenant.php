<?php

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Filament\Admin\Resources\TenantResource;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected ?string $ownerName = null;
    protected ?string $ownerPhone = null;

    /** Prefill the owner-login fields from the existing owner user. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $owner = TenantResource::ownerOf($this->record);
        $data['owner_name']  = $owner?->name;
        $data['owner_phone'] = $owner?->phone;

        return $data;
    }

    /** Pull the owner-login fields out before the Tenant is saved. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->ownerName  = $data['owner_name'] ?? null;
        $this->ownerPhone = $data['owner_phone'] ?? null;
        unset($data['owner_name'], $data['owner_phone']);

        return $data;
    }

    /** Create/update the owner login from those fields. */
    protected function afterSave(): void
    {
        TenantResource::upsertOwner($this->record, $this->ownerName, $this->ownerPhone);
    }
}
