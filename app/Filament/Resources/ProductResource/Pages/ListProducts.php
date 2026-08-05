<?php
namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\Catalogue\ProductImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import products from CSV')
                ->modalDescription('Works with a standard supermarket pricelist export (Product Name, Price_UGX, Cost, Category, Item_Code, Barcode, Image, ...) or simple headers (name, price, stock). Only a product-name column is required.')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->storeFiles(false)
                        ->required(),
                    // v4.37 — this defaulted to ON, and 'replace' is a hard DELETE of every product
                    // row: cost prices, extra photos, OEM numbers, dealer prices and the order-item
                    // links all go with them. Adding to a catalogue is the common case; wiping one is
                    // not, so it now has to be asked for and then typed out.
                    Forms\Components\Toggle::make('replace')
                        ->label('Replace the whole catalogue')
                        ->helperText('Off (recommended): adds new products and updates existing ones by name — nothing is lost. On: permanently deletes every product in this business first, including cost prices and photos.')
                        ->default(false)
                        ->live(),
                    Forms\Components\TextInput::make('confirm')
                        ->label('Type REPLACE to confirm')
                        ->helperText('This cannot be undone.')
                        ->visible(fn (Forms\Get $get) => (bool) $get('replace'))
                        ->required(fn (Forms\Get $get) => (bool) $get('replace'))
                        ->rule('in:REPLACE'),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'];
                    if (is_array($file)) $file = reset($file);
                    $path = is_object($file) && method_exists($file, 'getRealPath')
                        ? $file->getRealPath()
                        : (is_string($file) ? $file : null);

                    if (! $path) {
                        Notification::make()->title('Could not read the uploaded file')->danger()->send();
                        return;
                    }

                    $mode = ($data['replace'] ?? false) ? 'replace' : 'merge';
                    $r = app(ProductImporter::class)->importCsv($path, $mode);

                    if (isset($r['error'])) {
                        Notification::make()->title('Import failed')->body($r['error'])->danger()->send();
                        return;
                    }

                    $body = $mode === 'replace'
                        ? "Loaded {$r['created']} products."
                        : "Created {$r['created']}, updated {$r['updated']}".($r['skipped'] ? ", skipped {$r['skipped']}" : '');

                    Notification::make()->title('Import complete')->body($body)->success()->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
