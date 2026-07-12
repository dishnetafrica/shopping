<?php declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Models\Tenant;
use App\Reports\ReportColumn;
use App\Reports\ReportFilter;
use CloudBSS\Infra\Reporting\TrialBalance;

/**
 * Trial Balance report (Roadmap E7.4) — reads the EXISTING ledger's trial balance
 * (TrialBalance::trialBalance) and exposes it through the report contract. No accounting change; the
 * numbers (and the debit=credit balancing) match the Books screen. Optional account-type filter is
 * applied to the rows the ledger returns.
 */
class TrialBalanceReport extends LedgerReport
{
    public function key(): string
    {
        return 'trial_balance';
    }

    public function title(): string
    {
        return 'Trial Balance';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('account', 'Account', 'text'),
            new ReportColumn('name', 'Name', 'text'),
            new ReportColumn('type', 'Type', 'text'),
            new ReportColumn('debit', 'Debit', 'money'),
            new ReportColumn('credit', 'Credit', 'money'),
        ];
    }

    public function filters(): array
    {
        return [
            new ReportFilter('type', 'Type', 'select', ['ALL', 'asset', 'liability', 'equity', 'income', 'expense']),
        ];
    }

    public function rows(Tenant $t, array $filters = []): array
    {
        if (! $this->ledgerEnabled($t)) return [];
        [$tid, $eid, $cur, $factor] = $this->scope($t);

        $tb = app(TrialBalance::class)->trialBalance($tid, $eid, $cur);
        $wantType = strtolower((string) ($filters['type'] ?? 'all'));

        $rows = [];
        foreach (($tb['rows'] ?? []) as $r) {
            $type = (string) ($r['type'] ?? '');
            if ($wantType !== '' && $wantType !== 'all' && strtolower($type) !== $wantType) continue;
            $rows[] = [
                'account' => (string) ($r['account'] ?? ''),
                'name'    => (string) ($r['name'] ?? ''),
                'type'    => $type,
                'debit'   => $this->major((int) ($r['debit'] ?? 0), $factor),
                'credit'  => $this->major((int) ($r['credit'] ?? 0), $factor),
            ];
        }
        return $rows;
    }
}
