<?php
namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * NOTE: this file previously declared the WRONG namespace
 * (App\Filament\Resources) which collided with the seller-panel OrderResource
 * and fatally crashed the app on boot. It is corrected here to its own
 * namespace and hidden from the admin menu (orders are managed in the seller
 * panel). Safe to delete entirely if you prefer.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 30;

    public static function shouldRegisterNavigation(): bool
    {
        return false; // managed in the seller panel; kept here only to avoid the old collision
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_no')->label('Order')->searchable(),
                Tables\Columns\TextColumn::make('customer_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('total')->money('UGX'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
        ];
    }
}
