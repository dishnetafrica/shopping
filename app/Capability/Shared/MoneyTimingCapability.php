<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Models\Invoice;
use App\Models\OrderCost;
use App\Services\Money\CashService;

/** Cash-flow reasoning over the frozen CashService + live receivables/payables. Computes no money itself. */
final class MoneyTimingCapability
{
    /** @return array{cash:float,receivable:float,payable:float,runway:?int} */
    public function position(CapabilityContext $ctx): array
    {
        $cash = 0.0;
        try {
            $cash = (float) (new CashService($ctx->tenant))->balance([]);
        } catch (\Throwable $e) {
            $cash = 0.0;
        }

        $receivable = (float) Invoice::where('tenant_id', $ctx->tenantId())
            ->where('status', '!=', 'void')->sum('balance');

        $payable = (float) OrderCost::where('tenant_id', $ctx->tenantId())
            ->where('stage', 'purchase')->where('status', '!=', 'void')
            ->selectRaw('COALESCE(SUM(native_amount - COALESCE(paid_native,0)),0) as owed')
            ->value('owed');

        $dailyBurn = $payable > 0 ? max(1.0, $payable / 30.0) : 0.0;
        $runway = ($cash > 0 && $dailyBurn > 0) ? (int) floor($cash / $dailyBurn) : null;

        return [
            'cash' => round($cash, 2),
            'receivable' => round($receivable, 2),
            'payable' => round(max(0, $payable), 2),
            'runway' => $runway,
        ];
    }
}
