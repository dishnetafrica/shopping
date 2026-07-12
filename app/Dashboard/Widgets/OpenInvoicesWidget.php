<?php declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\AbstractWidget;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Money\Fx;
use Illuminate\Support\Facades\DB;

/**
 * Open (unpaid) invoices (Roadmap E10.1) — count + outstanding balance in USD via the Fx SSOT.
 * Grouped by currency so the USD conversion is done once per currency, not per invoice.
 */
class OpenInvoicesWidget extends AbstractWidget
{
    public function key(): string { return 'open_invoices'; }
    public function title(): string { return 'Open invoices'; }

    public function value(Tenant $t, $user): array
    {
        $rows = Invoice::where('tenant_id', $t->id)
            ->where('status', 'issued')->where('balance', '>', 0)
            ->select('currency', DB::raw('SUM(balance) as bal'), DB::raw('COUNT(*) as c'))
            ->groupBy('currency')->get();

        $fx = Fx::forTenant($t);
        $count = 0; $usd = 0.0;
        foreach ($rows as $r) {
            $count += (int) $r->c;
            $usd += $fx->toUsd((float) $r->bal, (string) ($r->currency ?: 'USD'));
        }
        return ['count' => $count, 'balance_usd' => round($usd, 2)];
    }
}
