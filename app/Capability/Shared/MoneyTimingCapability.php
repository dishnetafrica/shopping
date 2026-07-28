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

        // Receivable + payable must be expressed in USD (the Finance screen labels them USD). Previously
        // these summed the raw LOCAL-currency amounts, so a UGX total (e.g. 1,490,848) showed as
        // "$1,490,848" instead of ~$408. Convert each document to USD at its own currency's rate.
        $fx = \App\Services\Money\Fx::forTenant($ctx->tenant);
        $receivable = 0.0;
        foreach (Invoice::where('tenant_id', $ctx->tenantId())->where('status', '!=', 'void')
                     ->where('balance', '>', 0)->get() as $inv) {
            $cur  = \App\Services\Money\SalesBaseCurrency::txnCurrency($inv, $ctx->tenant);
            $rate = strtoupper((string) $cur) === 'USD' ? 1.0 : ((float) $fx->ratePerUsd($cur) ?: 1.0);
            $receivable += (float) $inv->balance / ($rate ?: 1.0);
        }

        // Net of supplier payments (which live as separate order_cost rows and never touch paid_native).
        // Shared with the dashboard so every "you owe" figure matches the supplier statement.
        $payable = \App\Services\Finance\PayablesCalculator::totalUsd($ctx->tenantId());

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
