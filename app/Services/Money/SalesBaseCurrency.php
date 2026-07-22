<?php

namespace App\Services\Money;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Base-currency ledger for SALES documents (orders, invoices, quotations).
 *
 * Brings the sales side to the same standard purchases (order_costs) + payments already meet: every
 * financial document carries its TRANSACTION currency, the EXCHANGE RATE used, and the BASE-currency
 * (USD) amount. We compute and store rate_to_usd + usd_amount WITHOUT re-denominating the document —
 * the original transaction amount/currency are preserved; usd_amount is the authoritative base figure
 * that reports and the v3 display consolidate on (so history is never re-priced at today's rate).
 *
 * The base currency is the tenant's configured `currency` (USD here) — NOT hard-coded.
 */
final class SalesBaseCurrency
{
    /** Currencies Fx can rate. Anything else is treated as already-base (rate 1). */
    private const RATEABLE = ['USD', 'UGX', 'SSP'];

    /** The tenant's base (accounting/reporting) currency. */
    public static function baseCurrency(Tenant $t): string
    {
        $c = strtoupper(trim((string) ($t->setting('currency', 'USD') ?: 'USD')));

        return $c !== '' ? $c : 'USD';
    }

    /**
     * The engine's LOCAL operating currency — the currency untagged sales amounts are actually stored
     * in. On a converted-basis USD tenant the engine stores local (UGX); on a native-basis tenant the
     * amounts are already the base. Mirrors CapabilityContext::nativeCurrency().
     */
    public static function nativeCurrency(Tenant $t): string
    {
        $base = self::baseCurrency($t);
        $basis = strtolower(trim((string) $t->setting('currency_basis', '')));
        if ($basis === 'native') {
            return in_array($base, self::RATEABLE, true) ? $base : 'UGX';
        }

        // SAFEGUARD: a USD base means the catalogue/amounts ARE USD — it is native, never a UGX
        // display over a UGX base. Treating a USD shop's untagged amounts as UGX divides every
        // order total by the FX rate (a $990 order read as $0.27). Only a UGX base stores UGX locally.
        return $base === 'USD' ? 'USD' : $base;   // converted basis: UGX base => UGX; USD base => USD
    }

    /**
     * The TRUE transaction currency of a sales document — what its stored amount is really in.
     *   - Order:     its own sell_currency when set & rateable, else the local operating currency.
     *   - Invoice:   NEVER the invoice.currency tag (the issuer stamps it with the tenant base) — derive
     *                from the linked order's sell_currency, else the local operating currency.
     *   - Quotation: its own currency when set & rateable, else the local operating currency.
     */
    public static function txnCurrency(object $doc, Tenant $t): string
    {
        $native = self::nativeCurrency($t);
        $rateable = fn ($c) => in_array(strtoupper((string) $c), self::RATEABLE, true);

        if ($doc instanceof Order) {
            $c = strtoupper((string) ($doc->sell_currency ?? ''));

            return $rateable($c) ? $c : $native;
        }
        if ($doc instanceof Invoice) {
            // Once a tenant's invoices have been normalized to store their TRUE currency (the
            // `invoices_usd_normalized` flag is set atomically with that migration), the invoice's own
            // `currency` tag is authoritative — trust it. Tenants without that flag keep the
            // order-derived fallback below, because their invoice.currency tag is still unreliable.
            if ($t->setting('invoices_usd_normalized')) {
                $c = strtoupper((string) ($doc->currency ?? ''));
                if ($rateable($c)) { return $c; }
            }
            try {
                if ($doc->order_id) {
                    $o = Order::where('tenant_id', $t->id)->find($doc->order_id);
                    if ($o) {
                        $c = strtoupper((string) ($o->sell_currency ?? ''));
                        if ($rateable($c)) { return $c; }
                    }
                }
            } catch (\Throwable $e) {
                // Never let a currency lookup break a read path — fall back to the local currency.
            }

            return $native;
        }
        if ($doc instanceof Quotation) {
            $c = strtoupper((string) ($doc->currency ?? ''));

            return $rateable($c) ? $c : $native;
        }

        return $native;
    }

    /** The document's own date, for effective-dated FX (issued docs keep their historical rate). */
    private static function docDate(object $doc): ?string
    {
        $d = $doc->issued_at ?? $doc->created_at ?? null;

        return $d ? Carbon::parse($d)->format('Y-m-d') : null;
    }

    /**
     * Compute {currency, rate_to_usd, usd_amount} for a document WITHOUT writing (so a backfill can
     * preview). rate_to_usd = native units per 1 USD; usd_amount = the base (USD) equivalent of `total`.
     */
    public static function compute(object $doc, Tenant $t): array
    {
        $cur = self::txnCurrency($doc, $t);
        $date = self::docDate($doc);
        $total = (float) ($doc->total ?? 0);
        $fx = Fx::forTenant($t);
        $rate = $fx->ratePerUsd($cur, $date);
        if ($rate <= 0) { $rate = 1.0; }
        $usd = ($cur === 'USD') ? round($total, 2) : $fx->toUsd($total, $cur, $date);

        return ['currency' => $cur, 'rate_to_usd' => round($rate, 6), 'usd_amount' => round($usd, 2)];
    }

    /**
     * Stamp rate_to_usd + usd_amount onto a document in memory (caller saves). Does NOT touch the
     * transaction amount or currency tag — purely additive. Returns true if it set the fields.
     * Skips when usd_amount is already present unless $force.
     */
    public static function stamp(object $doc, Tenant $t, bool $force = false): bool
    {
        $has = isset($doc->usd_amount) && $doc->usd_amount !== null && (float) $doc->usd_amount > 0;
        if ($has && ! $force) { return false; }
        if ((float) ($doc->total ?? 0) <= 0) { return false; }

        $c = self::compute($doc, $t);
        $doc->rate_to_usd = $c['rate_to_usd'];
        $doc->usd_amount = $c['usd_amount'];

        return true;
    }
}
