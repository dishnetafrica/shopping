<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Services\Invoicing\CreditNoteService;
use App\Services\Invoicing\InvoiceRenderer;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The Credit Notes register — a proper, numbered "we owe you" ledger (CN-2026-0001), the mirror of
 * the Invoices register. Raise a credit note against an invoice (return, price adjustment, over-
 * payment, goodwill) or standalone; it reduces the customer's outstanding on their statement.
 */
class CreditNoteController extends Controller
{
    public function __construct(private CreditNoteService $svc, private InvoiceRenderer $renderer) {}

    private function priv($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /panel/credit-notes — the register screen. */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/credit-notes.html');
        if (! is_file($path)) abort(500, 'Credit notes asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /** GET /papi/credit-notes — list + totals. */
    public function index(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        $q = CreditNote::where('tenant_id', $t->id)->orderByDesc('id');
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('credit_note_no', 'ILIKE', "%{$search}%")
                  ->orWhere('invoice_no', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_name', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_phone', 'ILIKE', "%{$search}%");
            });
        }
        $rows = $q->limit(500)->get();
        $live = $rows->where('status', '!=', 'void');

        return response()->json([
            'ok' => true,
            'stats' => [
                'count'    => $live->count(),
                'credited' => round($live->sum(fn ($c) => (float) $c->total), 2),
            ],
            'credit_notes' => $rows->map(fn (CreditNote $c) => [
                'id' => $c->id, 'no' => $c->credit_note_no,
                'date' => optional($c->issued_at)->format('d M Y'),
                'customer' => (string) ($c->customer_name ?? ''), 'phone' => (string) ($c->customer_phone ?? ''),
                'currency' => $c->currency, 'total' => (float) $c->total,
                'reason' => CreditNote::reasonLabel((string) $c->reason_code),
                'against' => (string) ($c->invoice_no ?? ''),
                'status' => $c->status,
                'pdf' => $c->pdf_path ? ('/papi/credit-note-pdf?id=' . $c->id) : '',
            ])->values(),
        ]);
    }

    /**
     * POST /papi/credit-note-issue — raise a numbered credit note.
     * Two modes: against an invoice (pass invoice_id + amount + reason), or standalone
     * (pass customer/phone/currency/amount/reason, or explicit lines).
     */
    public function issue(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        if (! class_exists(\Dompdf\Dompdf::class)) return response()->json(['ok' => false, 'error' => 'pdf_unavailable'], 503);

        $reason = (string) $request->input('reason_code', 'return');
        $notes  = trim((string) $request->input('notes', ''));
        $amount = round((float) $request->input('amount', 0), 2);

        $invoiceId = (int) $request->input('invoice_id', 0) ?: null;
        if ($invoiceId) {
            $inv = Invoice::where('tenant_id', $t->id)->find($invoiceId);
            if (! $inv) return response()->json(['ok' => false, 'error' => 'invoice_not_found'], 404);
            if ($amount <= 0) $amount = (float) $inv->balance ?: (float) $inv->total;
            $cn = $this->svc->issueForInvoice($t, $inv, $amount, $reason, $notes, 'panel');
            return response()->json(['ok' => true, 'id' => $cn->id, 'credit_note_no' => $cn->credit_note_no,
                'pdf' => '/papi/credit-note-pdf?id=' . $cn->id]);
        }

        // Standalone credit note.
        $lines = $request->input('lines');
        if (is_string($lines)) $lines = json_decode($lines, true);
        $lines = is_array($lines) ? $lines : [];
        if ($amount <= 0 && ! $lines) return response()->json(['ok' => false, 'error' => 'bad_amount'], 422);

        $cn = $this->svc->issue($t, [
            'customer'    => trim((string) $request->input('customer', '')),
            'phone'       => trim((string) $request->input('phone', '')),
            'address'     => trim((string) $request->input('address', '')),
            'cur'         => strtoupper(trim((string) $request->input('currency', ''))) ?: null,
            'total'       => $amount,
            'reason_code' => $reason,
            'reason'      => trim((string) $request->input('reason', '')),
            'notes'       => $notes,
            'order_id'    => (int) $request->input('order_id', 0) ?: null,
            'return_id'   => (int) $request->input('return_id', 0) ?: null,
            'lines'       => $lines,
        ], 'panel');

        return response()->json(['ok' => true, 'id' => $cn->id, 'credit_note_no' => $cn->credit_note_no,
            'pdf' => '/papi/credit-note-pdf?id=' . $cn->id]);
    }

    /** GET /papi/credit-note-show?id= — one credit note + its lines. */
    public function show(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $cn = CreditNote::with('items')->where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $cn) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json(['ok' => true, 'credit_note' => [
            'id' => $cn->id, 'no' => $cn->credit_note_no, 'title' => $cn->title,
            'date' => optional($cn->issued_at)->format('d M Y'),
            'customer' => $cn->customer_name, 'phone' => $cn->customer_phone, 'address' => $cn->bill_address,
            'currency' => $cn->currency, 'subtotal' => (float) $cn->subtotal, 'total' => (float) $cn->total,
            'reason' => CreditNote::reasonLabel((string) $cn->reason_code), 'reason_code' => $cn->reason_code,
            'against' => (string) ($cn->invoice_no ?? ''), 'status' => $cn->status, 'notes' => $cn->notes,
            'pdf' => $cn->pdf_path ? ('/papi/credit-note-pdf?id=' . $cn->id) : '',
            'items' => $cn->items->map(fn ($it) => [
                'name' => $it->name, 'qty' => (float) $it->qty, 'unit_price' => (float) $it->unit_price,
                'line_total' => (float) $it->line_total, 'uom' => $it->uom, 'pack' => $it->pack === null ? null : (float) $it->pack,
            ])->values(),
        ]]);
    }

    /** GET /papi/credit-note-pdf?id= — stream the stored PDF (regenerate from the record if missing). */
    public function pdf(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) abort(401);
        $cn = CreditNote::with('items')->where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $cn) abort(404);

        $disk = Storage::disk('public');
        if ($cn->pdf_path && $disk->exists($cn->pdf_path)) {
            return $disk->response($cn->pdf_path, $cn->credit_note_no . '.pdf', ['Content-Type' => 'application/pdf'], 'inline');
        }
        if (! class_exists(\Dompdf\Dompdf::class)) abort(503);
        try {
            $pdf  = $this->renderer->creditNotePdf($t, $this->renderer->fromCreditNote($t, $cn));
            $path = "credit-notes/{$t->id}/{$cn->credit_note_no}.pdf";
            $disk->put($path, $pdf);
            $cn->pdf_path = $path; $cn->save();
        } catch (\Throwable $e) {
            \Log::warning('credit note pdf regen failed: ' . $e->getMessage());
            abort(500);
        }
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $cn->credit_note_no . '.pdf"',
        ]);
    }

    /** Raw PDF bytes — from the stored file, else render + store. */
    private function pdfBytes($t, CreditNote $cn): string
    {
        $disk = Storage::disk('public');
        if ($cn->pdf_path && $disk->exists($cn->pdf_path)) return $disk->get($cn->pdf_path);
        $cn->loadMissing('items');
        $pdf = $this->renderer->creditNotePdf($t, $this->renderer->fromCreditNote($t, $cn));
        try {
            $path = "credit-notes/{$t->id}/{$cn->credit_note_no}.pdf";
            $disk->put($path, $pdf); $cn->pdf_path = $path; $cn->save();
        } catch (\Throwable $e) { /* still return the bytes */ }
        return $pdf;
    }

    /** POST /papi/credit-note-send — WhatsApp the credit note PDF to the customer. */
    public function send(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $cn = CreditNote::with('items')->where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $cn) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if ($cn->status === 'void') return response()->json(['ok' => false, 'error' => 'void'], 422);
        if (! class_exists(\Dompdf\Dompdf::class)) return response()->json(['ok' => false, 'error' => 'pdf_unavailable'], 503);

        $phone = preg_replace('/[^0-9]/', '', (string) ($request->input('phone', '') ?: $cn->customer_phone));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'no_phone'], 422);

        $gw = app(WhatsAppManager::class)->forTenant($t);
        if (! method_exists($gw, 'sendDocument') || ! $t->whatsapp_instance) {
            return response()->json(['ok' => false, 'error' => 'whatsapp_off'], 422);
        }

        try {
            $pdf = $this->pdfBytes($t, $cn);
            $cur = (string) $cn->currency;
            $dec = in_array($cur, ['USD'], true) ? 2 : 0;
            $amt = $cur . ' ' . number_format((float) $cn->total, $dec);
            $caption = "🧾 {$cn->title} {$cn->credit_note_no} — {$amt} credited to your account"
                . ($cn->invoice_no ? " against invoice {$cn->invoice_no}" : '')
                . '. This reduces your outstanding balance. Thank you.';
            $gw->sendDocument($t->whatsapp_instance, $phone, base64_encode($pdf), $cn->credit_note_no . '.pdf', $caption);
            MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'panel',
                "[credit note {$cn->credit_note_no}] " . $caption, null, null,
                ['via' => 'panel', 'kind' => 'credit_note', 'credit_note_no' => $cn->credit_note_no]);
        } catch (\Throwable $e) {
            \Log::warning('credit note send failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'send_failed'], 500);
        }
        return response()->json(['ok' => true, 'sent_to' => $phone]);
    }

    /** POST /papi/credit-note-void — void a credit note (kept for the record). Owner/manager only. */
    public function void(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $cn = CreditNote::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $cn) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $cn->status = 'void';
        $cn->save();
        try { if ($cn->pdf_path) Storage::disk('public')->delete($cn->pdf_path); } catch (\Throwable $e) {}
        $cn->pdf_path = null; $cn->save();
        return response()->json(['ok' => true]);
    }

    /**
     * GET /papi/credit-note-invoices?phone= — invoices for a customer, so the "new credit note"
     * form can offer the right invoice to credit. If no phone, returns recent invoices.
     */
    public function invoiceOptions(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $q = Invoice::where('tenant_id', $t->id)->where('status', '!=', 'void')->orderByDesc('id');
        $phone = preg_replace('/[^0-9]/', '', (string) $request->query('phone', ''));
        if ($phone !== '') $q->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$phone]);
        $rows = $q->limit(100)->get();
        return response()->json(['ok' => true, 'invoices' => $rows->map(fn (Invoice $i) => [
            'id' => $i->id, 'no' => $i->invoice_no, 'customer' => (string) ($i->customer_name ?? ''),
            'phone' => (string) ($i->customer_phone ?? ''), 'currency' => $i->currency,
            'total' => (float) $i->total, 'balance' => (float) $i->balance,
        ])->values()]);
    }
}
