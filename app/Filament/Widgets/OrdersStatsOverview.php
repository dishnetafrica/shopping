<?php
namespace App\Filament\Widgets;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        return [
            Stat::make('Total orders', Order::count())->color('primary'),
            Stat::make('Pending', Order::whereIn('status', ['New', 'Confirmed', 'Packed'])->count())->color('warning'),
            Stat::make('Out for delivery', Order::where('status', 'Out for delivery')->count())->color('info'),
            Stat::make('Delivered', Order::where('status', 'Delivered')->count())->color('success'),
            Stat::make('Cancelled', Order::where('status', 'Cancelled')->count())->color('danger'),
            Stat::make("Today's orders", Order::whereDate('created_at', today())->count()),
            Stat::make('Products', Product::count()),
            Stat::make('Customers', Conversation::count()),
        ];
    }
}
