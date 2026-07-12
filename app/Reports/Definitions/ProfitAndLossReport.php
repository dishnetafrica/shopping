<?php declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Models\Tenant;
use App\Reports\ReportColumn;
use CloudBSS\Infra\Reporting\TrialBalance;

/**
 * Profit & Loss report (Roadmap E7.4) — reads the EXISTING ledger's P&L (TrialBalance::profitAndLoss)
 * and exposes it through the report contract. No accounting change; the numbers match the Books
 * screen. Returns empty when the ledger is not enabled for the tenant.
 */
class ProfitAndLossReport extends LedgerReport
{
    public function key(): string
    {
        return 'pnl';
    }

    public function title(): string
    {
        return 'Profit & Loss';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('line', 'Line', 'text'),
            new ReportColumn('amount', 'Amount', 'money'),
        ];
    }

    public function rows(Tenant $t, array $filters = []): array
    {
        if (! $this->ledgerEnabled($t)) return [];
        [$tid, $eid, $cur, $factor] = $this->scope($t);

        $pl = app(TrialBalance::class)->profitAndLoss($tid, $eid, $cur);

        return [
            ['line' => 'Income',     'amount' => $this->major((int) ($pl['income'] ?? 0), $factor)],
            ['line' => 'Expenses',   'amount' => $this->major((int) ($pl['expense'] ?? 0), $factor)],
            ['line' => 'Net profit', 'amount' => $this->major((int) ($pl['net_profit'] ?? 0), $factor)],
        ];
    }
}
