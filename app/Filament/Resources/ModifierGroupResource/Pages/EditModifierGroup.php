<?php
namespace App\Filament\Resources\ModifierGroupResource\Pages;
use App\Filament\Resources\ModifierGroupResource; use Filament\Resources\Pages\EditRecord; use Filament\Actions;
class EditModifierGroup extends EditRecord {
    protected static string $resource = ModifierGroupResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }
}
