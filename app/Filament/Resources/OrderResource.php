<?php
namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Rider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Select::make('status')
                    ->options(array_combine(
                        ['New','Confirmed','Packed','Out for delivery','Delivered','Cancelled'],
                        ['New','Confirmed','Packed','Out for delivery','Delivered','Cancelled']
                    ))
                    ->required()
                    ->helperText('Changing the status sends the customer a WhatsApp update automatically.'),
                Forms\Components\Select::make('rider_id')
                    ->label('Rider')
                    ->options(fn () => Rider::where('active', true)->pluck('name', 'id'))
                    ->searchable(),
            ])->columns(2),

            Forms\Components\Section::make('Customer & delivery')->schema([
                Forms\Components\TextInput::make('customer_name'),
                Forms\Components\TextInput::make('customer_phone'),
                Forms\Components\TextInput::make('location')->columnSpanFull(),
                Forms\Components\TextInput::make('payment'),
                Forms\Components\TextInput::make('total')->numeric()->prefix('UGX')->disabled(),
            ])->columns(2),

            Forms\Components\Textarea::make('items_text')->label('Items')->rows(3)->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_no')->label('Order')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('customer_name')->searchable()->description(fn ($record) => $record->customer_phone),
                Tables\Columns\TextColumn::make('items_text')->limit(40)->tooltip(fn ($record) => $record->items_text),
                Tables\Columns\TextColumn::make('total')->money('UGX')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'New' => 'gray', 'Confirmed' => 'info', 'Packed' => 'warning',
                    'Out for delivery' => 'primary', 'Delivered' => 'success', 'Cancelled' => 'danger', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('channel')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(array_combine(
                    ['New','Confirmed','Packed','Out for delivery','Delivered','Cancelled'],
                    ['New','Confirmed','Packed','Out for delivery','Delivered','Cancelled']
                )),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit'  => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
