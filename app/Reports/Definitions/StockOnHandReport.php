<?php declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Models\Tenant;
use App\Reports\AbstractReport;
use App\Reports\ReportColumn;
use App\Reports\ReportFilter;
use App\Services\Inventory\StockLedger;

/**
 * Stock On-hand report (Roadmap E7.1) — on-hand per SKU per location. Reads ONLY the StockLedger
 * projection (Σ signed movements), never products.stock or consignment tables directly.
 */
class StockOnHandReport extends AbstractReport
{
    public function key(): string
    {
        return 'stock_on_hand';
    }

    public function title(): string
    {
        return 'Stock On-hand';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('product_id', 'Product', 'number'),
            new ReportColumn('sku', 'SKU', 'text'),
            new ReportColumn('location', 'Location', 'text'),
            new ReportColumn('qty', 'On hand', 'number'),
        ];
    }

    public function filters(): array
    {
        return [
            new ReportFilter('location', 'Location', 'select', ['ALL', StockLedger::LOC_KAMPALA, StockLedger::LOC_JUBA]),
        ];
    }

    public function rows(Tenant $t, array $filters = []): array
    {
        $f = [];
        $loc = strtoupper((string) ($filters['location'] ?? 'ALL'));
        if ($loc !== '' && $loc !== 'ALL') $f['location'] = $filters['location'];

        // SSOT — the stock ledger projection (on-hand = Σ signed movements per SKU/location)
        return StockLedger::forTenant($t)->onHand($f);
    }
}
