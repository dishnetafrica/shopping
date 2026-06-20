<?php
namespace App\Filament\Resources\ModifierGroupResource\Pages;

use App\Filament\Resources\ModifierGroupResource;
use App\Models\ModifierGroup;
use App\Models\Product;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListModifierGroups extends ListRecords
{
    protected static string $resource = ModifierGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('seedAccompaniment')
                ->label('Add accompaniment group')
                ->icon('heroicon-o-sparkles')->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates a required "Choice of accompaniment" (Rice, Naan, Chapati) and attaches it to every Main Course dish. Existing groups are left as-is.')
                ->action(function () {
                    $g = ModifierGroup::firstOrCreate(
                        ['name' => 'Choice of accompaniment'],
                        ['required' => true, 'min_select' => 1, 'max_select' => 1, 'sort' => 0, 'active' => true]
                    );
                    if ($g->options()->count() === 0) {
                        foreach (['Rice', 'Naan', 'Chapati'] as $i => $n) {
                            $g->options()->create(['name' => $n, 'price_delta' => 0, 'sort' => $i, 'active' => true]);
                        }
                    }
                    $ids = Product::where('category', 'Main Course')->pluck('id');
                    $g->products()->syncWithoutDetaching($ids->all());
                    Notification::make()
                        ->title('Accompaniment group ready — attached to ' . $ids->count() . ' dishes')
                        ->success()->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
