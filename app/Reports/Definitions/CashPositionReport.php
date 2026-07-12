<?php declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Models\Tenant;
use App\Reports\AbstractReport;
use App\Reports\ReportColumn;
use App\Reports\ReportFilter;
use App\Services\Money\CashService;

/**
 * Cash Position report (Roadmap E7.1) — per-currency cash on hand. Reads ONLY the CashService SSOT
 * (the one signed-sum rule), never order_costs directly, so it can never diverge from the cashbook.
 */
class CashPositionReport extends AbstractReport
{
    public function key(): string
    {
        return 'cash_position';
    }

    public function title(): string
    {
        return 'Cash Position';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('currency', 'Currency', 'text'),
            new ReportColumn('on_hand', 'On hand', 'money'),
        ];
    }

    public function filters(): array
    {
        return [
            new ReportFilter('holder', 'Holder', 'text'),
            new ReportFilter('currency', 'Currency', 'select', ['ALL', 'USD', 'UGX', 'SSP']),
        ];
    }

    public function rows(Tenant $t, array $filters = []): array
    {
        $balances = CashService::forTenant($t)->balances($filters); // SSOT — one cash rule
        $want = strtoupper((string) ($filters['currency'] ?? 'ALL'));

        $rows = [];
        foreach ($balances as $currency => $amount) {
            if ($want !== '' && $want !== 'ALL' && $want !== $currency) continue;
            $rows[] = ['currency' => $currency, 'on_hand' => $amount];
        }
        return $rows;
    }
}
