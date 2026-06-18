<?php
namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\WinworldModule;
use App\Filament\Resources\WwMaterialResource\Pages;
use App\Models\WwMaterial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WwMaterialResource extends Resource
{
    use WinworldModule;

    protected static ?string $model = WwMaterial::class;
    protected static ?string $navigationIcon = 'heroicon-o-beaker';
    protected static ?string $navigationGroup = 'Win World';
    protected static ?string $navigationLabel = 'Materials';
    protected static ?string $modelLabel = 'material';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('material_code')->maxLength(60),
            Forms\Components\TextInput::make('material_name')->required()->maxLength(255)->columnSpan(2),
            Forms\Components\Select::make('type')->options([
                'resin'=>'Resin','masterbatch'=>'Masterbatch','additive'=>'Additive','colour'=>'Colour',
            ]),
            Forms\Components\TextInput::make('uom')->default('kg')->maxLength(12),
            Forms\Components\Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('material_name')
            ->columns([
                Tables\Columns\TextColumn::make('material_code')->toggleable(),
                Tables\Columns\TextColumn::make('material_name')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('type')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('uom')->label('UOM'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWwMaterials::route('/'),
            'create' => Pages\CreateWwMaterial::route('/create'),
            'edit'   => Pages\EditWwMaterial::route('/{record}/edit'),
        ];
    }
}
