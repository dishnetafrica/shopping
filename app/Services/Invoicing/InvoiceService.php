<?php

namespace App\Services\Invoicing;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Issues permanent, numbered invoices. Numbering is gapless and per-year (INV-2026-0001) using an
 * atomic counter row, so two concurrent issues never collide or skip. Money is stored exactly as
 * shown on the document (the invoice's own currency).
 */
class InvoiceService
{
    public function __construct(private InvoiceRenderer $renderer) {}

    /**
     * Issue an invoice from normalised renderer data. Idempotent per order: if a non-void invoice
     * already exists for $orderId, it is returned unchanged (so auto-issue on delivery is safe).
     */
    public function issue(Tenant $t, array $d, ?int $orderId = null, string $source = 'panel'): Invoice
    {
        if ($orderId) {
            $existing = Invoice::where('tenant_id', $t->id)->where('order_id', $orderId)
                ->where('status', '!=', 'void')->orderByDesc('id')->first();
            if ($existing) return $existing;
        }

        // Born-USD: for a USD-base tenant, convert incoming LOCAL-currency amounts (a legacy UGX/SSP
        // order) to USD at the source — so a UGX-priced order never mints another mislabeled invoice.
        // Self-contained (re-prices from the catalogue); no-op when already USD or for non-USD bases.
        $d = self::toUsdIfNeeded($t, $d, $orderId);

        $cur     = (string) ($d['cur'] ?? ($t->setting('currency', 'UGX') ?: 'UGX'));
        $paid    = (float) ($d['paid'] ?? 0);
        $total   = (float) ($d['grand'] ?? 0);
        $balance = round($total - $paid, 2);
        $status  = $balance <= 0.5 ? 'paid' : ($paid > 0 ? 'part_paid' : 'issued');

        $termsDays = (int) ($d['terms_days'] ?? 0);
        $dueAt = ($termsDays > 0 && $balance > 0.5) ? now()->addDays($termsDays)->toDateString() : null;

        $no = $this->nextNumber($t);

        $inv = new Invoice([
            'tenant_id'      => $t->id,
            'invoice_no'     => $no,
            'order_id'       => $orderId,
            'customer_name'  => (string) ($d['customer'] ?? '') ?: null,
            'customer_phone' => preg_replace('/[^0-9+]/', '', (string) ($d['phone'] ?? '')) ?: null,
            'bill_address'   => (string) ($d['address'] ?? '') ?: null,
            'currency'       => $cur,
            'subtotal'       => (float) ($d['subtotal'] ?? 0),
            'discount'       => (float) ($d['discount'] ?? 0),
            'freight'        => (float) ($d['freight'] ?? 0),
            'total'          => $total,
            'amount_paid'    => $paid,
            'balance'        => $balance,
            'title'          => (string) ($d['title'] ?? ($t->setting('invoice_title', 'INVOICE') ?: 'INVOICE')),
            'status'         => $status,
            'source'         => $source,
            'notes'          => (string) ($d['notes'] ?? '') ?: null,
            'issued_at'      => now(),
            'due_at'         => $dueAt,
        ]);
        $inv->save();

        foreach (($d['lines'] ?? []) as $l) {
            InvoiceItem::create([
                'invoice_id' => $inv->id,
                'tenant_id'  => $t->id,
                'name'       => (string) ($l['name'] ?? ''),
                'sku'        => ((string) ($l['sku'] ?? '')) ?: null,
                'qty'        => (float) ($l['qty'] ?? 1),
                'unit_price' => (float) ($l['unit'] ?? 0),
                'line_total' => (float) ($l['amount'] ?? 0),
                'uom'        => ((string) ($l['uom'] ?? '')) ?: null,
                'pack'       => ((float) ($l['pack'] ?? 0)) ?: null,
            ]);
        }

        // Render + store the PDF (best-effort; the record stands even if this hiccups).
        try {
            $inv->load('items');
            $pdf  = $this->renderer->pdf($t, $this->renderer->fromInvoice($t, $inv));
            $path = "invoices/{$t->id}/{$no}.pdf";
            Storage::disk('public')->put($path, $pdf);
            $inv->pdf_path = $path;
            $inv->save();
        } catch (\Throwable $e) {
            \Log::warning('Invoice PDF store failed: ' . $e->getMessage());
        }

        // If the order already has settlement payments, reflect them on the fresh invoice so its
        // paid/balance is unified with the payment ledger from the start.
        if ($orderId) $this->syncPaidFromPayments($inv);

        // BOS spine (Slice 2): record InvoiceIssued for the customer timeline. Guarded + never-throws.
        \App\BOS\Emit::customerEvent($t->id, 'InvoiceIssued', (string) $inv->customer_phone, [
            'invoice_no' => $inv->invoice_no, 'total' => (float) $inv->total,
            'currency' => (string) $inv->currency, 'balance' => (float) $inv->balance,
        ], ['actor' => ['type' => 'system', 'name' => $source]]);

        // v4.25 — and tell the CUSTOMER: their invoice PDF plus, in plain words, what it is for and
        // what is left to pay. Queued, so a slow gateway never delays issuing the invoice, and
        // guarded, so a failure here can never lose an invoice that is already saved.
        try {
            \App\Jobs\NotifyCustomerBilling::dispatch($t->id, 'invoice', (int) $inv->id);
        } catch (\Throwable $e) {
            \Log::warning('customer invoice notice not queued: ' . $e->getMessage());
        }

        return $inv;
    }

    /** Convenience: issue straight from a saved order (used by auto-issue on delivery). */
    public function issueForOrder(Tenant $t, Order $order, string $source = 'auto'): Invoice
    {
        return $this->issue($t, $this->renderer->fromOrder($t, $order), (int) $order->id, $source);
    }

    /**
     * Born-USD conversion for USD-base shops: an invoice must show USD, but a LEGACY order (created
     * before the shop switched to USD-native) stored its line prices in the local currency (UGX/SSP).
     * The issuer stamps the tenant base ("USD") on those local numbers, so without this step the PDF
     * reads "USD 2,814,350" for what is really USD 770. Here we detect the TRUE currency of the incoming
     * amounts — re-price the lines against the USD catalogue and compare the document total to the
     * catalogue total at each convertible rate (USD, UGX, SSP) — and, when they are in a local currency,
     * convert every money field to USD by DIVIDING at that currency's own rate (so a negotiated price is
     * preserved, not overwritten by the catalogue). Falls back to the linked order's transaction currency
     * when the catalogue is inconclusive. When the currency can't be determined confidently, it leaves the
     * data untouched (never a risky divide).
     *
     * Runs for any USD-base tenant (or one carrying the historical `invoices_usd_normalized` flag). It is
     * a strict NO-OP when the amounts are already USD (grand ≈ catalogue total), so correct/native orders
     * are never touched; non-USD-base shops (UGX/SSP operating currency) return unchanged.
     *
     * public static so the QUICK/WYSIWYG preview path (OrderInvoiceController::pdf) can apply the exact
     * same normalization before rendering, keeping the un-numbered preview and the issued invoice identical.
     */
    public static function toUsdIfNeeded(Tenant $t, array $d, ?int $orderId): array
    {
        $baseUsd = \App\Services\Money\SalesBaseCurrency::baseCurrency($t) === 'USD';
        if (! $baseUsd && ! $t->setting('invoices_usd_normalized')) return $d;

        $grand = (float) ($d['grand'] ?? 0);
        if ($grand <= 0) return $d;

        // Rates for every convertible local currency (units per 1 USD). We compare against these
        // EXPLICITLY rather than SalesBaseCurrency::nativeCurrency(), which for a USD-native tenant
        // resolves to USD (rate 1) and would make a UGX order look like it's "already USD".
        $fx      = \App\Services\Money\Fx::forTenant($t);
        $rateUgx = (float) ($fx->ratePerUsd('UGX') ?: 3655.0);
        $rateSsp = (float) ($fx->ratePerUsd('SSP') ?: 0);

        // Re-price the lines from the catalogue (which is in USD) — used ONLY to detect the currency.
        $byName = [];
        foreach (\App\Models\Product::where('tenant_id', $t->id)->get(['name', 'base_price', 'price']) as $p) {
            $bp = (float) ($p->price ?: $p->base_price ?: 0);   // price-drift fix: invoices re-price at the SELLING price
            if ($bp > 0) { $byName[mb_strtolower(trim((string) $p->name))] = $bp; }
        }
        $catBase = 0.0; $matched = 0;
        foreach (($d['lines'] ?? []) as $l) {
            $qty = (float) ($l['qty'] ?? 0);
            $bp  = $byName[mb_strtolower(trim((string) ($l['name'] ?? '')))] ?? null;
            if ($bp !== null && $qty > 0) { $catBase += $bp * $qty; $matched++; }
        }

        // 1) Catalogue signal — which multiple of the USD catalogue total does the document total match?
        $srcCur = null;
        if ($matched > 0 && $catBase > 0) {
            if (abs($grand - $catBase) <= 0.12 * $catBase) {
                $srcCur = 'USD';                                             // already USD → no-op below
            } elseif ($rateUgx > 0 && abs($grand - $catBase * $rateUgx) <= 0.12 * $catBase * $rateUgx) {
                $srcCur = 'UGX';
            } elseif ($rateSsp > 0 && abs($grand - $catBase * $rateSsp) <= 0.12 * $catBase * $rateSsp) {
                $srcCur = 'SSP';
            }
        }
        // 2) Linked-order currency (rateable, and correct for SSP) when the catalogue is inconclusive.
        if ($srcCur === null && $orderId) {
            try {
                $o = \App\Models\Order::where('tenant_id', $t->id)->find($orderId);
                if ($o) {
                    $c = strtoupper((string) ($o->sell_currency ?? ''));
                    if (in_array($c, ['USD', 'UGX', 'SSP'], true)) { $srcCur = $c; }
                }
            } catch (\Throwable $e) { /* leave undetermined */ }
        }

        if ($srcCur === null) { return $d; }        // can't tell → don't risk a wrong conversion
        if ($srcCur === 'USD') { $d['cur'] = 'USD'; $d['dec'] = 2; return $d; }   // already USD

        // Local currency → convert the whole document to USD at that currency's own rate. We DIVIDE
        // the stored amounts (via Fx::toUsd) rather than substituting catalogue prices, so any
        // negotiated/discounted line price the customer was actually quoted is preserved.
        $cv = fn ($v) => round($fx->toUsd((float) $v, $srcCur), 2);
        foreach (['subtotal', 'discount', 'freight', 'grand', 'paid'] as $k) {
            if (isset($d[$k])) { $d[$k] = $cv($d[$k]); }
        }
        if (! empty($d['lines']) && is_array($d['lines'])) {
            $d['lines'] = array_map(function ($l) use ($cv) {
                if (isset($l['unit']))   { $l['unit']   = $cv($l['unit']); }
                if (isset($l['amount'])) { $l['amount'] = $cv($l['amount']); }
                return $l;
            }, $d['lines']);
        }
        $d['cur'] = 'USD';
        $d['dec'] = 2;

        return $d;
    }

    /**
     * Re-derive an invoice's paid/balance from the ONE payment ledger the team uses (CustomerPayment,
     * the settlement receipts), so a payment recorded anywhere — the seller "Record Payment", the
     * invoice "Pay" button, the cashbook — updates the invoice + statement in the same breath.
     * Each payment is normalised to USD (usd_amount); we express it in the invoice's currency.
     */
    public function syncPaidFromPayments(Invoice $inv): void
    {
        try {
            $t = Tenant::find($inv->tenant_id);
            if (! $t) return;
            $inv->loadMissing('order');
            $orderNo = $inv->order?->order_no;

            $pays = CustomerPayment::where('tenant_id', $inv->tenant_id)
                ->where(function ($w) use ($inv, $orderNo) {
                    $w->where('invoice_no', $inv->invoice_no);
                    if ($orderNo) $w->orWhere('order_no', $orderNo);
                })
                ->where('kind', 'settlement')   // advances aren't applied to a balance until settled
                ->get();

            // The invoice `currency` TAG is unreliable — the issuer stamps the tenant base (e.g. USD)
            // even on local-currency (UGX) amounts. Reconcile against the TRUE transaction currency,
            // derived from the linked order, so a USD payment settles a UGX-denominated invoice via FX
            // instead of naively subtracting 165 USD-units from a 611,325 UGX total (which never clears it).
            $cur = \App\Services\Money\SalesBaseCurrency::txnCurrency($inv, $t);
            // E3.4 — one FX source (App\Services\Money\Fx): express each USD-normalised payment in
            // the invoice currency. Equivalent to the previous inline rate_*_per_usd read.
            $fx = \App\Services\Money\Fx::forTenant($t);

            $paid = 0.0;
            foreach ($pays as $p) {
                if (strtoupper((string) $p->currency) === $cur) {
                    $paid += (float) $p->amount;                 // same currency → exact
                } elseif ($cur === 'USD') {
                    $paid += (float) $p->usd_amount;             // any → USD (already normalised)
                } else {
                    $paid += (float) $p->usd_amount * $fx->ratePerUsd($cur); // USD → invoice currency
                }
            }
            $paid = round($paid, 2);

            $inv->amount_paid = $paid;
            $inv->balance     = round(max(0, (float) $inv->total - $paid), 2);
            $inv->status      = $inv->balance <= 0.5 ? 'paid' : ($paid > 0 ? 'part_paid' : 'issued');
            // drop the cached PDF so a re-download shows the new balance
            if ($inv->pdf_path) { try { Storage::disk('public')->delete($inv->pdf_path); } catch (\Throwable $e) {} $inv->pdf_path = null; }
            $inv->save();
        } catch (\Throwable $e) {
            \Log::warning('syncPaidFromPayments failed: ' . $e->getMessage());
        }
    }

    /** Find + sync the invoice tied to a payment (by invoice_no, else the order's invoice). */
    public function syncByPayment($t, ?string $invoiceNo, ?string $orderNo): void
    {
        try {
            $inv = null;
            if ($invoiceNo) $inv = Invoice::where('tenant_id', $t->id)->where('invoice_no', $invoiceNo)->first();
            if (! $inv && $orderNo) {
                $order = Order::where('tenant_id', $t->id)->where('order_no', $orderNo)->first();
                if ($order) $inv = Invoice::where('tenant_id', $t->id)->where('order_id', $order->id)
                    ->where('status', '!=', 'void')->orderByDesc('id')->first();
            }
            if ($inv) $this->syncPaidFromPayments($inv);
        } catch (\Throwable $e) { /* never break the payment path */ }
    }

    /** Next gapless number for this tenant + current year, e.g. INV-2026-0001. */
    public function nextNumber(Tenant $t): string
    {
        $year   = (int) now()->format('Y');
        $prefix = strtoupper(trim((string) $t->setting('invoice_prefix', 'INV'))) ?: 'INV';
        $pad    = max(3, (int) $t->setting('invoice_seq_pad', 4));
        $mode   = strtolower((string) $t->setting('invoice_number_mode', 'year')); // year | plain

        $seq = $this->nextSeq($t, $mode === 'plain' ? 0 : $year);
        $seqStr = str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

        return $mode === 'plain'
            ? "{$prefix}-{$seqStr}"
            : "{$prefix}-{$year}-{$seqStr}";
    }

    /** Atomically bump and return the counter for (tenant, year). Year 0 = a single running series. */
    private function nextSeq(Tenant $t, int $year): int
    {
        // Ensure the row exists first, so the lock below always has something to lock (no first-insert race).
        DB::table('invoice_counters')->insertOrIgnore([
            'tenant_id' => $t->id, 'year' => $year, 'last_seq' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::transaction(function () use ($t, $year) {
            $row = DB::table('invoice_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->lockForUpdate()->first();
            $seq = ((int) ($row->last_seq ?? 0)) + 1;
            DB::table('invoice_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->update(['last_seq' => $seq, 'updated_at' => now()]);
            return $seq;
        });
    }
}
