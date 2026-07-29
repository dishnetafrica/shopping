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
        // Cash on hand across ALL currencies, valued in USD (cost basis) — the same figure the Books
        // screen and /papi/finance-overview report and the audit reconciled to. The frozen CashService
        // intentionally returns per-currency NATIVE balances (its "one rule"), and its no-arg balance()
        // defaults to the USD wallet ONLY — which under-reported a multi-currency trader's real cash by
        // ignoring UGX + SSP holdings. Totalling to USD is a reporting concern, so we do it here in the
        // capability layer rather than changing CashService. IN_STAGES reused so the sign rule stays shared.
        $cash = 0.0;
        try {
            foreach (OrderCost::where('tenant_id', $ctx->tenantId())
                        ->whereNotIn('status', ['void', 'rejected'])
                        ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
                        ->get(['stage', 'usd_amount']) as $c) {
                $cash += (in_array((string) $c->stage, CashService::IN_STAGES, true) ? 1 : -1) * (float) $c->usd_amount;
            }
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
