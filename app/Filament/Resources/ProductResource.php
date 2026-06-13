<?php
namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                Forms\Components\TextInput::make('category')
                    ->datalist(fn () => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all())
                    ->helperText('Pick an existing one or type a new category.'),
                Forms\Components\TextInput::make('sku')->label('Item code / SKU'),
                Forms\Components\TextInput::make('price')->numeric()->prefix('UGX')->required(),
                Forms\Components\TextInput::make('base_price')->numeric()->prefix('UGX')->label('Cost')->helperText('Your buying price (for margin).'),
                Forms\Components\TextInput::make('stock')->numeric()->default(0),
                Forms\Components\TextInput::make('barcode'),
                Forms\Components\Toggle::make('active')->default(true),
            ])->columns(3),

            Forms\Components\Section::make('Search & image')->schema([
                Forms\Components\Textarea::make('keywords')
                    ->helperText('Words customers might use (incl. local terms, e.g. "cheeni atta jeera"). The bot searches these.')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('image_url')->label('Image link (paste a URL)')->columnSpanFull(),
                Forms\Components\FileUpload::make('image_upload')->label('…or upload a photo')
                    ->image()->disk('public')->directory('products')->visibility('public')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')->label('')->circular()
                    ->getStateUsing(fn ($record) => static::imageUrl($record->image_url)),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('category')->badge()->toggleable()->sortable(),
                Tables\Columns\TextColumn::make('price')->money('UGX')->sortable(),
                Tables\Columns\TextColumn::make('stock')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn () => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()])
            ->paginated([25, 50, 100, 'all']);
    }

    /** Resolve a stored value that may be an external URL or a public-disk path. */
    public static function imageUrl(?string $value): ?string
    {
        if (! $value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
