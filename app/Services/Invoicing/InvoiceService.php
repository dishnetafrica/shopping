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

        return $inv;
    }

    /** Convenience: issue straight from a saved order (used by auto-issue on delivery). */
    public function issueForOrder(Tenant $t, Order $order, string $source = 'auto'): Invoice
    {
        return $this->issue($t, $this->renderer->fromOrder($t, $order), (int) $order->id, $source);
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

            $cur = strtoupper((string) $inv->currency);
            $ratePerUsd = fn (string $c) => (float) $t->setting('rate_' . strtolower($c) . '_per_usd',
                $c === 'UGX' ? 3655 : ($c === 'SSP' ? 5180 : 1)) ?: 1;

            $paid = 0.0;
            foreach ($pays as $p) {
                if (strtoupper((string) $p->currency) === $cur) {
                    $paid += (float) $p->amount;                 // same currency → exact
                } elseif ($cur === 'USD') {
                    $paid += (float) $p->usd_amount;             // any → USD (already normalised)
                } else {
                    $paid += (float) $p->usd_amount * $ratePerUsd($cur); // USD → invoice currency
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
