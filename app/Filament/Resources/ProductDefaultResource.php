<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductDefaultResource\Pages;
use App\Models\Product;
use App\Models\ProductDefault;
use App\Services\Bot\CatalogueMatcher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Smart Defaults — store owner picks the default SKU for a generic word
 * ("rice" -> Rice 5kg) so the bot stops asking. Part of the Default Product
 * Strategy. One default per term per store (enforced by a unique index).
 */
class ProductDefaultResource extends Resource
{
    protected static ?string $model = ProductDefault::class;

    /** Owner workflows live in the Seller Panel now; keep this in /app for super-admins only. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Smart Defaults';
    protected static ?string $modelLabel = 'default';
    protected static ?string $pluralModelLabel = 'Smart Defaults';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->description('When a customer just types this word, the bot adds the SKU you pick — instead of asking every time.')
                ->schema([
                    Forms\Components\TextInput::make('term')
                        ->label('Customer word')
                        ->required()
                        ->helperText('What a customer types, e.g. "rice", "sugar", "oil". Local terms work too (sakar, tel, doodh).')
                        ->maxLength(64),

                    Forms\Components\Select::make('product_id')
                        ->label('Default SKU')
                        ->required()
                        ->searchable()
                        ->options(fn () => Product::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('The exact product to add for that word.'),

                    Forms\Components\Toggle::make('active')->default(true),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('term')
            ->columns([
                Tables\Columns\TextColumn::make('term')->label('Customer word')->searchable()->sortable()->badge(),
                Tables\Columns\TextColumn::make('product.name')->label('Default SKU')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function (ProductDefault $r) {
                        if (! $r->product || ! $r->product->active) return 'broken';
                        if (($r->product->stock ?? 0) <= 0) return 'out of stock';
                        return $r->active ? 'active' : 'paused';
                    })
                    ->colors([
                        'success' => 'active',
                        'gray' => 'paused',
                        'warning' => 'out of stock',
                        'danger' => 'broken',
                    ]),
                Tables\Columns\IconColumn::make('active')->boolean()->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No defaults yet')
            ->emptyStateDescription('Add a default so the bot stops asking which size for common items.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductDefaults::route('/'),
            'create' => Pages\CreateProductDefault::route('/create'),
            'edit' => Pages\EditProductDefault::route('/{record}/edit'),
        ];
    }

    /** Canonicalise the term the same way the bot matcher does, so lookups match. */
    public static function canonical(string $term): string
    {
        return ProductDefault::canonicalTerm($term) ?: trim(mb_strtolower($term));
    }
}
