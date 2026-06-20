<?php
namespace App\Filament\Resources;

use App\Filament\Concerns\VerticalGate;
use App\Filament\Resources\ModifierGroupResource\Pages;
use App\Models\ModifierGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Item Options — lets a restaurant owner define choice groups (e.g. "Choice of
 * accompaniment: Rice / Naan / Chapati") and attach them to dishes. The bot and the
 * storefront read these to ask the customer before the dish is added. Hidden for grocery
 * tenants. Visibility is driven by the tenant's vertical (restaurant by default; snacks
 * via a feature_item_options override). Legacy tenants with restaurant_mode=true are
 * inferred as restaurant, so this stays byte-compatible for them.
 */
class ModifierGroupResource extends Resource
{
    use VerticalGate;

    protected static string $verticalFeature = 'item_options';

    protected static ?string $model = ModifierGroup::class;
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Item Options';
    protected static ?string $modelLabel = 'option group';
    protected static ?string $pluralModelLabel = 'Item Options';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()->maxLength(120)
                        ->helperText('What the customer sees, e.g. "Choice of accompaniment".'),
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Toggle::make('required')->default(true)->inline(false)
                            ->helperText('Must be chosen before the dish is added.'),
                        Forms\Components\TextInput::make('min_select')->label('Min choices')
                            ->numeric()->default(1)->minValue(0),
                        Forms\Components\TextInput::make('max_select')->label('Max choices')
                            ->numeric()->default(1)->minValue(1),
                    ]),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('sort')->label('Sort order')->numeric()->default(0),
                        Forms\Components\Toggle::make('active')->default(true)->inline(false),
                    ]),
                ]),

            Forms\Components\Section::make('Options')
                ->description('Each choice. Price change 0 = included free; a positive amount is a surcharge.')
                ->schema([
                    Forms\Components\Repeater::make('options')
                        ->relationship()
                        ->schema([
                            Forms\Components\TextInput::make('name')->required()->maxLength(120)->columnSpan(2),
                            Forms\Components\TextInput::make('price_delta')->label('Price change')
                                ->numeric()->default(0)->step('0.01'),
                            Forms\Components\Toggle::make('active')->default(true)->inline(false),
                        ])
                        ->columns(4)
                        ->orderColumn('sort')
                        ->reorderable()
                        ->defaultItems(1)
                        ->addActionLabel('Add option')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null),
                ]),

            Forms\Components\Section::make('Applies to dishes')
                ->schema([
                    Forms\Components\Select::make('products')
                        ->relationship('products', 'name')
                        ->multiple()->preload()->searchable()
                        ->helperText('Dishes that will show this choice.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\IconColumn::make('required')->boolean(),
                Tables\Columns\TextColumn::make('options')->label('Options')
                    ->getStateUsing(fn ($record) => $record->options()->count())->badge(),
                Tables\Columns\TextColumn::make('dishes')->label('Dishes')
                    ->getStateUsing(fn ($record) => $record->products()->count())->badge()->color('gray'),
                Tables\Columns\TextColumn::make('sort')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListModifierGroups::route('/'),
            'create' => Pages\CreateModifierGroup::route('/create'),
            'edit'   => Pages\EditModifierGroup::route('/{record}/edit'),
        ];
    }
}
