<?php
namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\WinworldModule;
use App\Filament\Resources\WwItemResource\Pages;
use App\Models\WwItem;
use App\Services\Winworld\Formula;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WwItemResource extends Resource
{
    use WinworldModule;

    protected static ?string $model = WwItem::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Win World';
    protected static ?string $navigationLabel = 'Items';
    protected static ?string $modelLabel = 'item';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('item_code')->required()->maxLength(60)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('item_name')->required()->maxLength(255)->columnSpan(2),
            Forms\Components\TextInput::make('item_group')->maxLength(80),
            Forms\Components\TextInput::make('width_inch')->numeric()->label('Width (inch)')->live(),
            Forms\Components\TextInput::make('length_inch')->numeric()->label('Length (inch)')->live(),
            Forms\Components\TextInput::make('gauge')->numeric()->live(),
            Forms\Components\Placeholder::make('gram_preview')
                ->label('Gram / pcs (auto)')
                ->content(fn (Forms\Get $get) => number_format(
                    Formula::gramPerPcs((float)$get('width_inch'), (float)$get('length_inch'), (float)$get('gauge')), 4)
                    .'  =  W × L × gauge ÷ 3300'),
            Forms\Components\Select::make('status')->options(['Active'=>'Active','Inactive'=>'Inactive'])->default('Active'),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('item_code')
            ->columns([
                Tables\Columns\TextColumn::make('item_code')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('item_name')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('item_group')->badge()->color('gray')->toggleable(),
                Tables\Columns\TextColumn::make('width_inch')->label('W')->toggleable(),
                Tables\Columns\TextColumn::make('length_inch')->label('L')->toggleable(),
                Tables\Columns\TextColumn::make('gauge')->toggleable(),
                Tables\Columns\TextColumn::make('gram_per_pcs')->label('g/pcs')->placeholder('—')->numeric(decimalPlaces: 4),
                Tables\Columns\IconColumn::make('status')->label('Active')->boolean()
                    ->getStateUsing(fn($record)=>$record->status==='Active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    /** Store the derived gram/pcs whenever an item is saved. */
    public static function withGram(array $data): array
    {
        $data['gram_per_pcs'] = round(Formula::gramPerPcs(
            (float)($data['width_inch'] ?? 0), (float)($data['length_inch'] ?? 0), (float)($data['gauge'] ?? 0)
        ), 4) ?: null;
        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWwItems::route('/'),
            'create' => Pages\CreateWwItem::route('/create'),
            'edit'   => Pages\EditWwItem::route('/{record}/edit'),
        ];
    }
}
