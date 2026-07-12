<?php declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\AbstractWidget;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Low stock count (Roadmap E10.1) — number of active products at or below the low-stock threshold
 * (setting low_stock_threshold, default 5). Uses the Product on-hand column (the ledger derives it
 * under stock_v2), so it tracks whatever on-hand source the tenant is on.
 */
class LowStockWidget extends AbstractWidget
{
    public function key(): string { return 'low_stock'; }
    public function title(): string { return 'Low stock items'; }

    public function value(Tenant $t, $user): array
    {
        $threshold = (int) $t->setting('low_stock_threshold', 5);
        $count = Product::where('tenant_id', $t->id)
            ->whereNull('archived_at')->where('active', true)
            ->where('stock', '<=', $threshold)
            ->count();
        return ['count' => $count, 'threshold' => $threshold];
    }
}
