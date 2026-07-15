<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Services\Money\SalesBaseCurrency;

/**
 * Forward-stamps the base-currency ledger fields (rate_to_usd + usd_amount) onto sales documents
 * (orders, invoices, quotations) as they are saved — so every new document is spec-compliant without
 * touching the frozen creation code. Purely additive: it never changes the transaction amount or the
 * currency tag, and never throws into the save path.
 */
final class SalesBaseCurrencyObserver
{
    public function saving($doc): void
    {
        try {
            $missing = ! isset($doc->usd_amount) || $doc->usd_amount === null || (float) $doc->usd_amount <= 0;
            $changed = method_exists($doc, 'isDirty')
                && ($doc->isDirty('total') || $doc->isDirty('sell_currency') || $doc->isDirty('currency'));
            if (! $missing && ! $changed) { return; }

            $t = $doc->tenant_id ? Tenant::find($doc->tenant_id) : null;
            if (! $t) { return; }

            SalesBaseCurrency::stamp($doc, $t, true);
        } catch (\Throwable $e) {
            // The base-currency stamp is best-effort — never break a sales save because of it.
        }
    }
}
