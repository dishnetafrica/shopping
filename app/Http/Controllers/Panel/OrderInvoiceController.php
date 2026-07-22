<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Invoicing\InvoiceRenderer;
use Illuminate\Http\Request;

/**
 * GET|POST /papi/order-invoice-pdf — a clean, branded, server-side WHOLESALE invoice PDF for an
 * order (dompdf). This is the QUICK, un-numbered preview: the seller can tweak line prices,
 * discount, freight and currency on-screen and the panel posts that exact state (WYSIWYG). For a
 * permanent, numbered invoice see InvoiceController::issue. Both share InvoiceRenderer, so the
 * quick PDF and the issued invoice look identical.
 */
class OrderInvoiceController extends Controller
{
    public function __construct(private InvoiceRenderer $renderer) {}

    public function pdf(Request $r)
    {
        $t = $r->user() ? $r->user()->tenant : null;
        if (! $t) abort(403);
        if (! class_exists(\Dompdf\Dompdf::class)) abort(503, 'PDF engine unavailable');

        $data = $this->renderer->fromRequest($r, $t);
        if (empty($data['lines'])) abort(422, 'Nothing to invoice');

        // Same born-USD normalization the issued invoice gets: on a USD-base shop a LEGACY order's
        // line prices are stored in the local currency (UGX/SSP); without this the quick preview would
        // print those numbers under a "USD" label (USD 2,814,350 for what is really USD 770). No-op when
        // the amounts are already USD or the shop is not USD-base.
        $orderId = (int) $r->input('id', 0);
        $data = \App\Services\Invoicing\InvoiceService::toUsdIfNeeded($t, $data, $orderId > 0 ? $orderId : null);

        try {
            $pdf = $this->renderer->pdf($t, $data);
        } catch (\Throwable $e) {
            \Log::warning('OrderInvoice render failed: ' . $e->getMessage());
            abort(500, 'Could not build the invoice PDF');
        }

        $file = 'Invoice-' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $data['no']) . '.pdf';
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
        ]);
    }
}
