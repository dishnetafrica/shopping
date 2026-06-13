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
                ->modalDescription('First row must be headers. Required column: name. Optional: price, stock, category, keywords, sku, barcode, base_price, active. Existing products with the same name are updated.')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->storeFiles(false)
                        ->required(),
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

                    $r = app(ProductImporter::class)->importCsv($path);
                    if (isset($r['error'])) {
                        Notification::make()->title('Import failed')->body($r['error'])->danger()->send();
                        return;
                    }

                    $body = "Created {$r['created']}, updated {$r['updated']}"
                          . ($r['skipped'] ? ", skipped {$r['skipped']}" : '');
                    if (! empty($r['errors'])) $body .= "\n".implode("\n", $r['errors']);

                    Notification::make()
                        ->title('Import complete')
                        ->body($body)
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
