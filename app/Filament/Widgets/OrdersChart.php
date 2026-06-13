<?php
namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrdersChart extends ChartWidget
{
    protected static ?string $heading = 'Orders this month';
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $start = now()->startOfMonth();
        $labels = []; $data = [];
        for ($i = 1; $i <= now()->day; $i++) {
            $day = $start->copy()->day($i);
            $labels[] = (string) $i;
            $data[] = Order::whereDate('created_at', $day->toDateString())->count();
        }
        return [
            'datasets' => [['label' => 'Orders', 'data' => $data, 'fill' => 'start']],
            'labels' => $labels,
        ];
    }

    protected function getType(): string { return 'line'; }
}
