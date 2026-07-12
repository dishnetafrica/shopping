<?php

namespace App\Services\Invoicing;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Issues permanent, numbered credit notes (CN-2026-0001) — the mirror image of an invoice. A credit
 * note records money the business owes the customer (a return, a price adjustment, an over-payment
 * or goodwill) and, once issued, reduces the customer's outstanding balance on their statement.
 * Numbering is gapless + per-year using an atomic counter row, exactly like InvoiceService.
 */
class CreditNoteService
{
    public function __construct(private InvoiceRenderer $renderer) {}

    /**
     * Issue a credit note from normalised data.
     * $d keys: customer, phone, address, cur, total (positive), reason_code, notes, lines[],
     *          invoice_id, invoice_no, order_id, return_id.
     */
    public function issue(Tenant $t, array $d, string $source = 'panel'): CreditNote
    {
        $cur    = strtoupper((string) ($d['cur'] ?? ($t->setting('currency', 'UGX') ?: 'UGX')));
        $lines  = $this->normaliseLines($d['lines'] ?? [], $d);
        $sub    = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)), 2);
        $total  = isset($d['total']) ? round((float) $d['total'], 2) : $sub;
        if ($total <= 0) $total = $sub;

        $no = $this->nextNumber($t);

        $cn = new CreditNote([
            'tenant_id'      => $t->id,
            'credit_note_no' => $no,
            'invoice_id'     => ($d['invoice_id'] ?? null) ?: null,
            'invoice_no'     => ((string) ($d['invoice_no'] ?? '')) ?: null,
            'order_id'       => ($d['order_id'] ?? null) ?: null,
            'return_id'      => ($d['return_id'] ?? null) ?: null,
            'customer_name'  => ((string) ($d['customer'] ?? '')) ?: null,
            'customer_phone' => preg_replace('/[^0-9+]/', '', (string) ($d['phone'] ?? '')) ?: null,
            'bill_address'   => ((string) ($d['address'] ?? '')) ?: null,
            'currency'       => $cur,
            'subtotal'       => $sub ?: $total,
            'total'          => $total,
            'reason_code'    => (string) ($d['reason_code'] ?? 'return'),
            'title'          => (string) ($d['title'] ?? ($t->setting('credit_note_title', 'CREDIT NOTE') ?: 'CREDIT NOTE')),
            'status'         => 'issued',
            'source'         => $source,
            'notes'          => ((string) ($d['notes'] ?? '')) ?: null,
            'issued_at'      => now(),
        ]);
        $cn->save();

        foreach ($lines as $l) {
            CreditNoteItem::create([
                'credit_note_id' => $cn->id,
                'tenant_id'      => $t->id,
                'name'           => (string) ($l['name'] ?? ''),
                'sku'            => ((string) ($l['sku'] ?? '')) ?: null,
                'qty'            => (float) ($l['qty'] ?? 1),
                'unit_price'     => (float) ($l['unit'] ?? 0),
                'line_total'     => (float) ($l['amount'] ?? 0),
                'uom'            => ((string) ($l['uom'] ?? '')) ?: null,
                'pack'           => ((float) ($l['pack'] ?? 0)) ?: null,
            ]);
        }

        // Render + store the PDF (best-effort — the record stands even if this hiccups).
        try {
            $cn->load('items');
            $pdf  = $this->renderer->creditNotePdf($t, $this->renderer->fromCreditNote($t, $cn));
            $path = "credit-notes/{$t->id}/{$no}.pdf";
            Storage::disk('public')->put($path, $pdf);
            $cn->pdf_path = $path;
            $cn->save();
        } catch (\Throwable $e) {
            \Log::warning('Credit note PDF store failed: ' . $e->getMessage());
        }

        return $cn;
    }

    /**
     * Raise a credit note against an existing invoice (full or partial). Copies the customer + the
     * invoice's currency; a single summary line unless explicit lines are supplied.
     */
    public function issueForInvoice(Tenant $t, Invoice $inv, float $amount, string $reasonCode = 'return', string $notes = '', string $source = 'panel'): CreditNote
    {
        $amount = round($amount, 2);
        if ($amount <= 0) $amount = (float) $inv->balance ?: (float) $inv->total;

        $label = 'Credit against ' . $inv->invoice_no . ' — ' . CreditNote::reasonLabel($reasonCode);

        return $this->issue($t, [
            'customer'    => (string) $inv->customer_name,
            'phone'       => (string) $inv->customer_phone,
            'address'     => (string) $inv->bill_address,
            'cur'         => (string) $inv->currency,
            'total'       => $amount,
            'reason_code' => $reasonCode,
            'notes'       => $notes,
            'invoice_id'  => (int) $inv->id,
            'invoice_no'  => (string) $inv->invoice_no,
            'order_id'    => $inv->order_id ? (int) $inv->order_id : null,
            'lines'       => [[
                'name'   => $label,
                'qty'    => 1,
                'unit'   => $amount,
                'amount' => $amount,
            ]],
        ], $source);
    }

    /** Normalise posted/derived lines to the renderer shape. */
    private function normaliseLines(array $raw, array $d): array
    {
        $lines = [];
        foreach ($raw as $l) {
            $name = trim((string) ($l['name'] ?? ''));
            if ($name === '') continue;
            $qty  = (float) ($l['qty'] ?? 1); if ($qty <= 0) $qty = 1;
            $unit = (float) ($l['unit'] ?? ($l['price'] ?? 0));
            $amt  = isset($l['amount']) ? (float) $l['amount'] : $qty * $unit;
            $lines[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'amount' => $amt,
                        'sku' => trim((string) ($l['sku'] ?? '')), 'uom' => trim((string) ($l['uom'] ?? '')),
                        'pack' => max(0.0, (float) ($l['pack'] ?? 0))];
        }
        if (! $lines) {
            $total = round((float) ($d['total'] ?? 0), 2);
            $lines[] = ['name' => trim((string) ($d['reason'] ?? '')) ?: CreditNote::reasonLabel((string) ($d['reason_code'] ?? 'other')),
                        'qty' => 1, 'unit' => $total, 'amount' => $total, 'sku' => '', 'uom' => '', 'pack' => 0.0];
        }
        return $lines;
    }

    /** Next gapless number for this tenant + current year, e.g. CN-2026-0001. */
    public function nextNumber(Tenant $t): string
    {
        $year   = (int) now()->format('Y');
        $prefix = strtoupper(trim((string) $t->setting('credit_note_prefix', 'CN'))) ?: 'CN';
        $pad    = max(3, (int) $t->setting('invoice_seq_pad', 4));
        $mode   = strtolower((string) $t->setting('invoice_number_mode', 'year')); // year | plain

        $seq = $this->nextSeq($t, $mode === 'plain' ? 0 : $year);
        $seqStr = str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

        return $mode === 'plain' ? "{$prefix}-{$seqStr}" : "{$prefix}-{$year}-{$seqStr}";
    }

    /** Atomically bump and return the counter for (tenant, year). Year 0 = a single running series. */
    private function nextSeq(Tenant $t, int $year): int
    {
        DB::table('credit_note_counters')->insertOrIgnore([
            'tenant_id' => $t->id, 'year' => $year, 'last_seq' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::transaction(function () use ($t, $year) {
            $row = DB::table('credit_note_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->lockForUpdate()->first();
            $seq = ((int) ($row->last_seq ?? 0)) + 1;
            DB::table('credit_note_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->update(['last_seq' => $seq, 'updated_at' => now()]);
            return $seq;
        });
    }
}
