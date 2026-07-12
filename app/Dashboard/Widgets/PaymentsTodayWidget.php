<?php declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\AbstractWidget;
use App\Models\CustomerPayment;
use App\Models\Tenant;

/**
 * Payments received today (Roadmap E10.1) — count + USD total from the unified payment ledger
 * (CustomerPayment, the SSOT from Epic 4). A staff member sees only their own receipts.
 */
class PaymentsTodayWidget extends AbstractWidget
{
    public function key(): string { return 'payments_today'; }
    public function title(): string { return 'Payments today'; }

    public function value(Tenant $t, $user): array
    {
        $q = CustomerPayment::where('tenant_id', $t->id)
            ->where('created_at', '>=', now()->startOfDay());
        if (! $this->isPrivileged($user) && $user) {
            $name = (string) ($user->name ?? '');
            $uid = (int) ($user->id ?? 0);
            $q->where(function ($w) use ($name, $uid) {
                if ($uid > 0) $w->orWhere('holder_user_id', $uid);
                if ($name !== '') $w->orWhere('holder', $name);
            });
        }
        return [
            'count'   => (int) (clone $q)->count(),
            'usd'     => round((float) (clone $q)->sum('usd_amount'), 2),
        ];
    }
}
