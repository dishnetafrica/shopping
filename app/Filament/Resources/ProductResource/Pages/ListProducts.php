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
                    Forms\Components\Toggle::make('replace')
                        ->label('Replace the whole catalogue')
                        ->helperText('On: clears this business\'s products first, then loads the file (recommended for a full pricelist). Off: adds/updates by name (slower, for small edits).')
                        ->default(true),
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

                    $mode = ($data['replace'] ?? true) ? 'replace' : 'merge';
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
