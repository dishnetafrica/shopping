<?php declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Reports\AbstractReport;
use App\Reports\ReportColumn;
use App\Reports\ReportFilter;
use App\Services\Money\Fx;

/**
 * Sales report (Roadmap E7.3) — the SSOT replacement for the legacy invoiced.html, which converted
 * to USD with a hard-coded rate (3750). This reads REAL invoices and converts every currency to USD
 * through the Fx service (effective-dated, one FX source), so the totals match the rest of the app.
 */
class SalesReport extends AbstractReport
{
    public function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Sales (Invoiced)';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('invoice_no', 'Invoice', 'text'),
            new ReportColumn('customer', 'Customer', 'text'),
            new ReportColumn('currency', 'Currency', 'text'),
            new ReportColumn('total', 'Total', 'money'),
            new ReportColumn('total_usd', 'Total (USD)', 'money'),
            new ReportColumn('balance_usd', 'Balance (USD)', 'money'),
            new ReportColumn('status', 'Status', 'text'),
            new ReportColumn('issued_at', 'Issued', 'date'),
        ];
    }

    public function filters(): array
    {
        return [
            new ReportFilter('status', 'Status', 'select', ['ALL', 'issued', 'paid', 'partial', 'void']),
        ];
    }

    public function rows(Tenant $t, array $filters = []): array
    {
        $q = Invoice::where('tenant_id', $t->id);
        $status = strtolower((string) ($filters['status'] ?? 'all'));
        if ($status !== '' && $status !== 'all') $q->where('status', $status);

        $invoices = $q->orderByDesc('issued_at')->orderByDesc('id')->limit(1000)->get();
        $fx = Fx::forTenant($t); // one FX source — never a hard-coded rate

        $rows = [];
        foreach ($invoices as $inv) {
            $cur = (string) ($inv->currency ?: 'USD');
            $rows[] = [
                'invoice_no'  => (string) $inv->invoice_no,
                'customer'    => (string) ($inv->customer_name ?? ''),
                'currency'    => $cur,
                'total'       => round((float) $inv->total, 2),
                'total_usd'   => $fx->toUsd((float) $inv->total, $cur),
                'balance_usd' => $fx->toUsd((float) $inv->balance, $cur),
                'status'      => (string) $inv->status,
                'issued_at'   => optional($inv->issued_at)->format('Y-m-d'),
            ];
        }
        return $rows;
    }
}
