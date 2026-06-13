<?php
namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rebuild')
                ->label('Rebuild from products')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Adds any product categories that are not yet in this list. Existing ones are kept.')
                ->action(function () {
                    $have = Category::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
                    $cats = Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
                    $new = 0;
                    foreach ($cats as $c) {
                        if ($c !== '' && ! in_array(mb_strtolower($c), $have, true)) {
                            Category::create(['name' => $c]);
                            $have[] = mb_strtolower($c);
                            $new++;
                        }
                    }
                    Notification::make()->title("Added {$new} categories")->success()->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
