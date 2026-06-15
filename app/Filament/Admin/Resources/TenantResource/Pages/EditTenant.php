<?php

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Filament\Admin\Resources\TenantResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected ?string $ownerName = null;
    protected ?string $ownerPhone = null;

    /** Import-products button on the edit page header (imports into THIS business). */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importProducts')
                ->label('Import products')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->modalHeading(fn () => 'Import products into ' . $this->record->name)
                ->modalDescription('Upload a pricelist CSV (name, price, category, keywords, stock — or a standard POS export). Imports straight into this business.')
                ->form([
                    Forms\Components\FileUpload::make('file')->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->storeFiles(false)->required(),
                    Forms\Components\Toggle::make('replace')->label('Replace the whole catalogue')
                        ->helperText('On: clears this business’s products first, then loads the file. Off: adds/updates by name.')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    $r = TenantResource::importCsvForTenant($this->record, $data['file'], (bool) ($data['replace'] ?? true));
                    if (isset($r['error'])) {
                        Notification::make()->title('Import failed')->body($r['error'])->danger()->send();
                        return;
                    }
                    $body = ! empty($r['updated'])
                        ? "Created {$r['created']}, updated {$r['updated']}"
                        : "Loaded {$r['created']} products.";
                    Notification::make()->title('Import complete — ' . $this->record->name)->body($body)->success()->send();
                }),
        ];
    }

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
