<?php
namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\PanelCurrency;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $cur = PanelCurrency::code();
        $revenueToday = (float) Order::whereDate('created_at', today())
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->sum('total');

        // Live kitchen queue = anything not yet finished.
        $inKitchen = Order::whereIn('status', ['New', 'Accepted', 'Preparing'])->count();

        return [
            Stat::make("Today's revenue", $cur . ' ' . number_format($revenueToday))->color('success'),
            Stat::make("Today's orders", Order::whereDate('created_at', today())->count())->color('primary'),
            Stat::make('In kitchen', $inKitchen)->color('warning')->description('New + Accepted + Preparing'),
            Stat::make('Ready', Order::where('status', 'Ready')->count())->color('success'),
            Stat::make('Dispatched', Order::whereIn('status', ['Dispatched', 'Out for delivery'])->count())->color('info'),
            Stat::make('Delivered', Order::where('status', 'Delivered')->count())->color('success'),
            Stat::make('Cancelled / Rejected', Order::whereIn('status', ['Cancelled', 'Rejected'])->count())->color('danger'),
            Stat::make('Products', Product::count()),
        ];
    }
}
