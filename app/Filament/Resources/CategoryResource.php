<?php
namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\TextInput::make('image_url')
                ->label('Image URL')
                ->url()
                ->maxLength(500)
                ->helperText('Product ni image URL (auto-fill thai, ya khud paste karo).')
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('image_preview')
                ->label('Preview')
                ->content(fn ($record) => $record && $record->image_url
                    ? new \Illuminate\Support\HtmlString("<img src='" . e($record->image_url) . "' style='height:90px;border-radius:8px;object-fit:contain;background:#f5f5f5;padding:4px'>")
                    : '—')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('sort')->numeric()->default(0)->label('Sort order'),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')->label('Image')->square()->size(46),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('products_count')->label('Products')
                    ->getStateUsing(fn ($record) => Product::where('category', $record->name)->count())
                    ->badge()->color('gray'),
                Tables\Columns\TextColumn::make('sort')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit'   => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
