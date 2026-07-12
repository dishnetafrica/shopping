<?php declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\AbstractWidget;
use App\Models\Tenant;
use App\Reports\Definitions\ProfitAndLossReport;

/**
 * Net profit (Roadmap E10.1) — reads the P&L report view (E7.4), which reads the frozen ledger's
 * profitAndLoss. Returns null when the ledger is not enabled, so the widget can be hidden.
 */
class NetProfitWidget extends AbstractWidget
{
    public function key(): string { return 'net_profit'; }
    public function title(): string { return 'Net profit'; }

    public function value(Tenant $t, $user): array
    {
        $rows = (new ProfitAndLossReport())->rows($t); // [] when the ledger is disabled
        if (! $rows) return ['enabled' => false, 'net_profit' => null];

        $net = null;
        foreach ($rows as $r) {
            if (($r['line'] ?? '') === 'Net profit') $net = $r['amount'];
        }
        return ['enabled' => true, 'net_profit' => $net];
    }
}
