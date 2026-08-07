<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\MessageLog;   // fixed 2026-07-18: was App\Models\MessageLog (non-existent) — invoice-send fataled at the logging step
use App\Services\Invoicing\InvoiceRenderer;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\StatementService;
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
    public function __construct(private InvoiceService $svc, private InvoiceRenderer $renderer, private StatementService $stmt) {}

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

        $q = Invoice::with('order:id,order_no')->where('tenant_id', $t->id)->orderByDesc('id');
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
                'order_no' => (string) (optional($i->order)->order_no ?? ''),
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
    /** UCRM-style export of selected invoices (?ids=1,2,3) — CSV always, PDF when dompdf exists. */
    public function export(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) abort(401);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        $q = Invoice::where('tenant_id', $t->id)->orderByDesc('created_at');
        if ($ids) $q->whereIn('id', $ids);
        $invoices = $q->limit(1000)->get();
        if ($invoices->isEmpty()) abort(404, 'No invoices selected');

        if ($request->query('format') === 'pdf') {
            if (! class_exists(\Dompdf\Dompdf::class)) abort(503, 'PDF engine unavailable');
            $rowsHtml = '';
            foreach ($invoices as $i) {
                $rowsHtml .= '<tr><td>'.e($i->invoice_no).'</td><td>'.e($i->customer_name).'</td>'
                    .'<td class="r">'.number_format((float) $i->total, 2).'</td>'
                    .'<td class="r">'.number_format((float) $i->amount_paid, 2).'</td>'
                    .'<td class="r">'.number_format((float) $i->balance, 2).'</td>'
                    .'<td>'.e($i->status).'</td>'
                    .'<td>'.($i->issued_at ? \Illuminate\Support\Carbon::parse($i->issued_at)->format('d M Y') : '').'</td>'
                    .'<td>'.($i->due_at ? \Illuminate\Support\Carbon::parse($i->due_at)->format('d M Y') : '').'</td></tr>';
            }
            $sum = number_format((float) $invoices->sum('total'), 2);
            $due = number_format((float) $invoices->sum('balance'), 2);
            $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}h1{font-size:16px;margin:0 0 2px}
                .sub{color:#666;margin-bottom:12px}table{width:100%;border-collapse:collapse}th{background:#f1f5f9;text-align:left}
                th,td{border:1px solid #e2e8f0;padding:5px 7px}.r{text-align:right}tfoot td{font-weight:bold;background:#f8fafc}</style></head><body>'
                .'<h1>'.e($t->name).' — Invoices</h1><div class="sub">'.count($invoices).' invoice(s) · exported '.now()->format('d M Y H:i').'</div>'
                .'<table><thead><tr><th>Invoice #</th><th>Customer</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Due</th><th>Status</th><th>Created</th><th>Due date</th></tr></thead>'
                .'<tbody>'.$rowsHtml.'</tbody>'
                .'<tfoot><tr><td colspan="2">Totals</td><td class="r">'.$sum.'</td><td></td><td class="r">'.$due.'</td><td colspan="3"></td></tr></tfoot></table></body></html>';
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="Invoices-'.now()->format('Ymd-Hi').'.pdf"',
            ]);
        }

        // CSV (Excel opens it natively; UTF-8 BOM so Arabic names render correctly)
        $out = "\xEF\xBB\xBF".'"Invoice #","Customer","Phone","Currency","Subtotal","Discount","Total","Paid","Balance","Status","Created","Due date"'."\n";
        foreach ($invoices as $i) {
            $csv = fn ($v) => '"'.str_replace('"', '""', (string) $v).'"';
            $out .= implode(',', [
                $csv($i->invoice_no), $csv($i->customer_name), $csv($i->customer_phone), $csv($i->currency),
                (float) $i->subtotal, (float) $i->discount, (float) $i->total, (float) $i->amount_paid,
                (float) $i->balance, $csv($i->status),
                $csv($i->issued_at ? \Illuminate\Support\Carbon::parse($i->issued_at)->format('Y-m-d') : ''),
                $csv($i->due_at ? \Illuminate\Support\Carbon::parse($i->due_at)->format('Y-m-d') : ''),
            ])."\n";
        }

        return response($out, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="Invoices-'.now()->format('Ymd-Hi').'.csv"',
        ]);
    }

    /** Bulk WhatsApp send: loops the proven single-invoice send() per id (capped, paced). */
    public function bulkSend(Request $request)
    {
        $ids = array_slice(array_filter(array_map('intval', (array) $request->input('ids', []))), 0, 25);
        if (! $ids) return response()->json(['ok' => false, 'error' => 'no_ids'], 422);

        $sent = 0; $failed = [];
        foreach ($ids as $id) {
            $request->merge(['id' => $id]);
            try {
                $res = $this->send($request);
                $body = json_decode($res->getContent(), true);
                if (! empty($body['ok'])) $sent++;
                else $failed[] = $id.':'.($body['error'] ?? 'failed');
            } catch (\Throwable $e) {
                $failed[] = $id.':exception';
            }
            usleep(400000);   // pace WhatsApp sends
        }

        return response()->json(['ok' => $sent > 0, 'message' => "Sent {$sent} of ".count($ids)
            .($failed ? ' · failed: '.implode(', ', array_slice($failed, 0, 5)) : ''), 'failed' => $failed]);
    }

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

    /**
     * POST /papi/invoice-pay — record a (partial) payment against an invoice, in the invoice's own
     * currency. Updates paid/balance/status live so the statement is always current.
     */
    public function pay(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        $inv = Invoice::with('order')->where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $inv) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if ($inv->status === 'void') return response()->json(['ok' => false, 'error' => 'void'], 422);

        $amt = round((float) $request->input('amount', 0), 2);
        if ($amt <= 0) return response()->json(['ok' => false, 'error' => 'bad_amount'], 422);
        $cur = strtoupper(trim((string) $request->input('currency', ''))) ?: (string) $inv->currency;
        $discount = round((float) $request->input('discount', 0), 2);   // optional "give X off, close it"

        $orderNo = $inv->order?->order_no;

        // UNIFIED: record the payment in the ONE ledger (CustomerPayment settlement) — so it lands
        // in the cashbook + the order's payments AND updates the invoice/statement — then re-derive
        // the invoice balance from that ledger. Falls back to a direct invoice update only if the
        // invoice somehow has no linked order (can't tie a receipt to an order then).
        if ($orderNo) {
            // E4.2 — record through the ONE payment recorder, so the invoice "Pay" writes the SAME
            // ledger row as the seller "Record Payment" (and re-derives the invoice/statement).
            $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($cur); // one FX source
            if ($rate <= 0) $rate = 1;
            $usd    = \App\Models\OrderCost::toUsd($amt, $rate);
            $method = trim((string) $request->input('method', 'Cash')) ?: 'Cash';
            app(\App\Services\Money\PaymentService::class)->record($t, [
                'customer'       => (string) ($inv->customer_name ?? 'Customer'),
                'phone'          => $inv->customer_phone,
                'order_no'       => $orderNo,
                'invoice_no'     => $inv->invoice_no,
                'kind'           => 'settlement',
                'currency'       => $cur,
                'amount'         => $amt,
                'rate'           => $rate,
                'usd'            => $usd,
                'method'         => $method,
                'reference'      => null,
                'receipt_path'   => null,
                'holder'         => (string) ($u->name ?? ''),
                'holder_user_id' => $u->id,
                'created_by'     => $u->name ?? '',
                // cost_note omitted → PaymentService::describeNote builds the rich cashbook line
                // (Payment from {customer} · Invoice {no} · {method}) from the fields above.
                'discount'       => $discount,   // recorder applies it to the invoice + linked order
            ]);
            $inv->refresh();
        } else {
            // no order to tie a receipt to — update the invoice directly
            $inv->amount_paid = round((float) $inv->amount_paid + $amt, 2);
            // Optional discount: write off the rest via the invoice's own discount field so it closes.
            if ($discount > 0) {
                $left = round(max(0, (float) $inv->total - (float) $inv->amount_paid), 2);
                $dd = min($discount, $left);
                if ($dd > 0) { $inv->discount = round((float) $inv->discount + $dd, 2); $inv->total = round((float) $inv->total - $dd, 2); }
            }
            $inv->balance     = round(max(0, (float) $inv->total - (float) $inv->amount_paid), 2);
            $inv->status      = $inv->balance <= 0.5 ? 'paid' : ((float) $inv->amount_paid > 0 ? 'part_paid' : 'issued');
            try { if ($inv->pdf_path) Storage::disk('public')->delete($inv->pdf_path); } catch (\Throwable $e) {}
            $inv->pdf_path = null; $inv->save();
        }

        return response()->json(['ok' => true, 'paid' => (float) $inv->amount_paid,
            'balance' => (float) $inv->balance, 'status' => $inv->status]);
    }

    /** GET /papi/statement?phone= — a customer's statement of account (JSON). */
    public function statementData(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        return response()->json(['ok' => true, 'statement' => $this->stmt->for($t, (string) $request->query('phone', ''))]);
    }

    /** GET /papi/statement-pdf?phone= — stream the statement PDF. */
    public function statementPdf(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) abort(401);
        if (! class_exists(\Dompdf\Dompdf::class)) abort(503);
        $s = $this->stmt->for($t, (string) $request->query('phone', ''));
        if (empty($s['lines'])) abort(404, 'No invoices for this customer');
        $pdf = $this->renderer->statementPdf($t, $s);
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Statement-' . preg_replace('/[^0-9]/', '', $s['phone']) . '.pdf"',
        ]);
    }

    /** POST /papi/statement-send — WhatsApp the statement PDF to the customer. */
    public function statementSend(Request $request)
    {
        // v4.52 — every reply now carries a human `message` as well as its `error` code. V3 calls this
        // same endpoint from the customer record and shows `message` in the toast; the old codes read
        // as gibberish to whoever pressed the button. The codes are unchanged, so the panel is untouched.
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        if (! class_exists(\Dompdf\Dompdf::class)) return response()->json(['ok' => false, 'error' => 'pdf_unavailable', 'message' => 'The PDF engine is not installed on this server, so no statement can be built.'], 503);

        $s = $this->stmt->for($t, (string) $request->input('phone', ''));
        if (empty($s['lines'])) return response()->json(['ok' => false, 'error' => 'nothing_to_send', 'message' => 'This customer has no invoices yet, so there is nothing to put on a statement.'], 422);
        $phone = preg_replace('/[^0-9+]/', '', (string) ($request->input('phone', '') ?: $s['phone']));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'no_phone', 'message' => 'This customer has no phone number on file.'], 422);

        $gw = app(WhatsAppManager::class)->forTenant($t);
        if (! method_exists($gw, 'sendDocument') || ! $t->whatsapp_instance) {
            return response()->json(['ok' => false, 'error' => 'whatsapp_off', 'message' => 'WhatsApp is not connected, so the statement could not be sent.'], 422);
        }
        try {
            $pdf = $this->renderer->statementPdf($t, $s);
            $owe = StatementService::fmtMap($s['outstanding']);
            // A paid-up customer still gets their statement — asking for one is not the same as owing.
            $caption = $owe === '0'
                ? "🧾 Statement of account — no outstanding balance. Thank you!"
                : "🧾 Statement of account — total outstanding: {$owe}. Please quote the invoice numbers on payment.";
            // Named, so it does not land in the customer's phone as the fourth "Statement.pdf".
            $who = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($s['name'] ?: $s['phone'])) ?: 'Account';
            $file = 'Statement-' . $who . '-' . now()->format('Y-m-d') . '.pdf';
            $gw->sendDocument($t->whatsapp_instance, $phone, base64_encode($pdf), $file, $caption);
            MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'panel',
                "[statement] " . $caption, null, null, ['via' => 'panel', 'kind' => 'statement']);
        } catch (\Throwable $e) {
            \Log::warning('statement send failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'send_failed', 'message' => 'WhatsApp refused the statement — try again shortly.'], 500);
        }
        return response()->json(['ok' => true, 'sent_to' => $phone,
            'message' => 'Statement sent to ' . ($s['name'] ?: $phone) . ' on WhatsApp.']);
    }

    /** POST /papi/invoice-void — void an invoice (kept for the record). Owner/manager only. */
    public function void(Request $request)
    {
        if (\App\Support\Access::blocks($request->user(), 'invoice.void')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
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
