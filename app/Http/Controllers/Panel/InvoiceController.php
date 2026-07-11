<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Services\Invoicing\InvoiceRenderer;
use App\Services\Invoicing\InvoiceService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The Invoices register — a proper, numbered invoice ledger (INV-2026-0001), separate from the
 * order number. Issue an invoice from an order (manual button; also auto-issued on delivery),
 * list/search them, re-download the stored PDF, or void one.
 */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $svc, private InvoiceRenderer $renderer) {}

    private function priv($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /panel/invoices — the register screen. */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/invoices.html');
        if (! is_file($path)) abort(500, 'Invoices asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /** GET /papi/invoices — list + totals. */
    public function index(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        $q = Invoice::where('tenant_id', $t->id)->orderByDesc('id');
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('invoice_no', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_name', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_phone', 'ILIKE', "%{$search}%");
            });
        }
        $rows = $q->limit(500)->get();

        $live = $rows->where('status', '!=', 'void');
        return response()->json([
            'ok' => true,
            'stats' => [
                'count'       => $live->count(),
                'invoiced'    => round($live->sum(fn ($i) => (float) $i->total), 2),
                'outstanding' => round($live->sum(fn ($i) => (float) $i->balance), 2),
            ],
            'invoices' => $rows->map(fn (Invoice $i) => [
                'id' => $i->id, 'no' => $i->invoice_no,
                'date' => optional($i->issued_at)->format('d M Y'),
                'customer' => (string) ($i->customer_name ?? ''), 'phone' => (string) ($i->customer_phone ?? ''),
                'currency' => $i->currency, 'total' => (float) $i->total,
                'paid' => (float) $i->amount_paid, 'balance' => (float) $i->balance,
                'status' => $i->status, 'order_id' => $i->order_id,
                'due' => optional($i->due_at)->format('d M Y'),
                'pdf' => $i->pdf_path ? ('/papi/invoice-pdf?id=' . $i->id) : '',
            ])->values(),
        ]);
    }

    /**
     * POST /papi/invoice-issue — issue a permanent numbered invoice.
     * Accepts the same on-screen (WYSIWYG) payload as the quick PDF, plus the order id, so the
     * invoice reflects exactly what the seller sees. Returns the new number + PDF link.
     */
    public function issue(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        if (! class_exists(\Dompdf\Dompdf::class)) return response()->json(['ok' => false, 'error' => 'pdf_unavailable'], 503);

        $data = $this->renderer->fromRequest($request, $t);
        if (empty($data['lines'])) return response()->json(['ok' => false, 'error' => 'nothing_to_invoice'], 422);

        $orderId = (int) $request->input('id', $request->input('row', 0)) ?: null;

        // If this order is already invoiced (and not voided), return that — never double-number.
        if ($orderId) {
            $exist = Invoice::where('tenant_id', $t->id)->where('order_id', $orderId)
                ->where('status', '!=', 'void')->orderByDesc('id')->first();
            if ($exist) {
                return response()->json(['ok' => true, 'id' => $exist->id, 'invoice_no' => $exist->invoice_no,
                    'already' => true, 'pdf' => '/papi/invoice-pdf?id=' . $exist->id]);
            }
        }

        $inv = $this->svc->issue($t, $data, $orderId, 'panel');
        return response()->json(['ok' => true, 'id' => $inv->id, 'invoice_no' => $inv->invoice_no,
            'pdf' => '/papi/invoice-pdf?id=' . $inv->id]);
    }

    /** GET /papi/invoice-show?id= — one invoice + its line items. */
    public function show(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $inv = Invoice::with('items')->where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $inv) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json(['ok' => true, 'invoice' => [
            'id' => $inv->id, 'no' => $inv->invoice_no, 'title' => $inv->title,
            'date' => optional($inv->issued_at)->format('d M Y'), 'due' => optional($inv->due_at)->format('d M Y'),
            'customer' => $inv->customer_name, 'phone' => $inv->customer_phone, 'address' => $inv->bill_address,
            'currency' => $inv->currency, 'subtotal' => (float) $inv->subtotal, 'discount' => (float) $inv->discount,
            'freight' => (float) $inv->freight, 'total' => (float) $inv->total, 'paid' => (float) $inv->amount_paid,
            'balance' => (float) $inv->balance, 'status' => $inv->status, 'order_id' => $inv->order_id,
            'pdf' => $inv->pdf_path ? ('/papi/invoice-pdf?id=' . $inv->id) : '',
            'items' => $inv->items->map(fn ($it) => [
                'name' => $it->name, 'qty' => (float) $it->qty, 'unit_price' => (float) $it->unit_price,
                'line_total' => (float) $it->line_total, 'uom' => $it->uom, 'pack' => $it->pack === null ? null : (float) $it->pack,
            ])->values(),
        ]]);
    }

    /** GET /papi/invoice-pdf?id= — stream the stored PDF (regenerate from the record if missing). */
    public function pdf(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) abort(401);
        $inv = Invoice::with('items')->where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $inv) abort(404);

        $disk = Storage::disk('public');
        if ($inv->pdf_path && $disk->exists($inv->pdf_path)) {
            return $disk->response($inv->pdf_path, $inv->invoice_no . '.pdf', ['Content-Type' => 'application/pdf'], 'inline');
        }
        if (! class_exists(\Dompdf\Dompdf::class)) abort(503);
        try {
            $pdf  = $this->renderer->pdf($t, $this->renderer->fromInvoice($t, $inv));
            $path = "invoices/{$t->id}/{$inv->invoice_no}.pdf";
            $disk->put($path, $pdf);
            $inv->pdf_path = $path; $inv->save();
        } catch (\Throwable $e) {
            \Log::warning('invoice pdf regen failed: ' . $e->getMessage());
            abort(500);
        }
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $inv->invoice_no . '.pdf"',
        ]);
    }

    /** Raw PDF bytes for an invoice — from the stored file, else render + store. */
    private function pdfBytes($t, Invoice $inv): string
    {
        $disk = Storage::disk('public');
        if ($inv->pdf_path && $disk->exists($inv->pdf_path)) return $disk->get($inv->pdf_path);
        $inv->loadMissing('items');
        $pdf = $this->renderer->pdf($t, $this->renderer->fromInvoice($t, $inv));
        try {
            $path = "invoices/{$t->id}/{$inv->invoice_no}.pdf";
            $disk->put($path, $pdf); $inv->pdf_path = $path; $inv->save();
        } catch (\Throwable $e) { /* still return the bytes */ }
        return $pdf;
    }

    /**
     * POST /papi/invoice-send — WhatsApp the invoice PDF to the customer (like a quotation send).
     * Uses the invoice's own phone unless a phone is passed. Never issues/changes the invoice.
     */
    public function send(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $inv = Invoice::with('items')->where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $inv) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if ($inv->status === 'void') return response()->json(['ok' => false, 'error' => 'void'], 422);
        if (! class_exists(\Dompdf\Dompdf::class)) return response()->json(['ok' => false, 'error' => 'pdf_unavailable'], 503);

        $phone = preg_replace('/[^0-9]/', '', (string) ($request->input('phone', '') ?: $inv->customer_phone));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'no_phone'], 422);

        $gw = app(WhatsAppManager::class)->forTenant($t);
        if (! method_exists($gw, 'sendDocument') || ! $t->whatsapp_instance) {
            return response()->json(['ok' => false, 'error' => 'whatsapp_off'], 422);
        }

        try {
            $pdf = $this->pdfBytes($t, $inv);
            $cur = (string) $inv->currency;
            $dec = in_array($cur, ['USD'], true) ? 2 : 0;
            $fmt = fn ($n) => $cur . ' ' . number_format((float) $n, $dec);
            $bal = (float) $inv->balance;
            $caption = "🧾 {$inv->title} {$inv->invoice_no} — Total " . $fmt($inv->total)
                . ($bal > 0.5
                    ? '. Balance due ' . $fmt($bal) . (optional($inv->due_at) ? ' by ' . $inv->due_at->format('d/m/Y') : '') . '. Please quote the invoice number on payment.'
                    : '. Paid in full — thank you for your business.');
            $gw->sendDocument($t->whatsapp_instance, $phone, base64_encode($pdf), $inv->invoice_no . '.pdf', $caption);
            MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'panel',
                "[invoice {$inv->invoice_no}] " . $caption, null, null, ['via' => 'panel', 'kind' => 'invoice', 'invoice_no' => $inv->invoice_no]);
        } catch (\Throwable $e) {
            \Log::warning('invoice send failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'send_failed'], 500);
        }
        return response()->json(['ok' => true, 'sent_to' => $phone]);
    }

    /** POST /papi/invoice-void — void an invoice (kept for the record). Owner/manager only. */
    public function void(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $inv = Invoice::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $inv) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $inv->status = 'void';
        $inv->save();
        // Drop the cached PDF so a re-download shows the VOID state.
        try { if ($inv->pdf_path) Storage::disk('public')->delete($inv->pdf_path); } catch (\Throwable $e) {}
        $inv->pdf_path = null; $inv->save();
        return response()->json(['ok' => true]);
    }
}
