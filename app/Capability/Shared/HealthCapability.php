<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Models\Invoice;

/** Derives collection-risk signals from live invoice balances (via the model; no recompute of money). */
final class HealthCapability
{
    /** @return array{overdue:float,overdueCount:int,maxDaysLate:int,exposure:float} */
    public function signalsForPhone(CapabilityContext $ctx, string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        $rows = Invoice::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->where('status', '!=', 'void')
            ->get();

        $exposure = 0.0; $overdue = 0.0; $overdueCount = 0; $maxLate = 0;
        $now = now();
        foreach ($rows as $inv) {
            $bal = (float) $inv->balance;
            if ($bal <= 0) {
                continue;
            }
            $exposure += $bal;
            if ($inv->due_at && $inv->due_at->lt($now)) {
                $overdue += $bal;
                $overdueCount++;
                $maxLate = max($maxLate, (int) $inv->due_at->diffInDays($now));
            }
        }

        return [
            'overdue' => round($overdue, 2),
            'overdueCount' => $overdueCount,
            'maxDaysLate' => $maxLate,
            'exposure' => round($exposure, 2),
        ];
    }
}
