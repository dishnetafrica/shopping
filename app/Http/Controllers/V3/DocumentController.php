<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Services\Catalog\CatalogRenderer;
use App\Services\Catalog\PriceListRenderer;
use App\Services\Finance\CashbookExport;
use App\Services\Finance\ReceiptRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Thin document-streaming endpoints for v3 downloads that have no legacy-panel route (payment receipt,
 * category price list). Streams the PDF inline with a Content-Disposition header — the same proven
 * mechanism the panel uses for quotations/invoices, so no dependency on the public storage URL/domain.
 * Auth + tenant scoping come from the shared v3 route group (web + auth + SetTenantFromUser).
 */
final class DocumentController extends Controller
{
    /** GET /api/v1/doc/receipt/{id} — branded payment receipt. */
    public function receipt(Request $r, int $id)
    {
        $t = $r->user()->tenant;
        $p = CustomerPayment::where('tenant_id', $t->id)->find($id);
        if (! $p) {
            abort(404);
        }
        $doc = app(ReceiptRenderer::class)->pdf($t, $p);
        if (! $doc) {
            abort(404, 'PDF engine unavailable');
        }

        return $this->stream($doc, 'Receipt-' . ($p->receipt_no ?: $p->id) . '.pdf');
    }

    /** GET /api/v1/doc/price-list — category-grouped price list of active products. */
    public function priceList(Request $r)
    {
        $t = $r->user()->tenant;
        $products = Product::where('tenant_id', $t->id)->where('active', true)
            ->orderBy('category')->orderBy('name')->get();
        if ($products->isEmpty()) {
            abort(404, 'No active products');
        }
        $cur = (string) ($t->setting('currency', 'UGX') ?: 'UGX');
        $grouped = [];
        foreach ($products as $p) {
            $cat = trim((string) $p->category) ?: 'Uncategorised';
            $grouped[$cat][] = ['name' => (string) $p->name, 'unit' => (string) ($p->unit_label ?: ''), 'moq' => $p->moq ? (int) $p->moq : null, 'price' => (float) $p->price];
        }
        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($grouped as $cat => $items) {
            $groups[] = ['category' => $cat, 'items' => $items];
        }
        $doc = app(PriceListRenderer::class)->pdf($t, $groups, $cur);
        if (! $doc) {
            abort(404, 'PDF engine unavailable');
        }

        return $this->stream($doc, $doc['fileName']);
    }

    /**
     * GET /api/v1/doc/catalog — photo-first product catalogue, one category per page.
     * Optional ?category=Name streams just that category (e.g. to WhatsApp one section to a shop).
     */
    public function catalog(Request $r)
    {
        $t = $r->user()->tenant;
        $one = trim((string) $r->query('category', ''));

        $q = Product::where('tenant_id', $t->id)->where('active', true);
        if ($one !== '') {
            $q->whereRaw('LOWER(COALESCE(category, \'\')) = ?', [mb_strtolower($one)]);
        }
        $products = $q->orderBy('category')->orderBy('name')->get();
        if ($products->isEmpty()) {
            abort(404, 'No active products' . ($one !== '' ? ' in ' . $one : ''));
        }

        $cur = (string) ($t->setting('currency', 'UGX') ?: 'UGX');
        $grouped = [];
        foreach ($products as $p) {
            $cat = trim((string) $p->category) ?: 'Uncategorised';
            $grouped[$cat][] = [
                'name' => (string) $p->name,
                'unit' => (string) ($p->unit_label ?: ''),
                'moq' => $p->moq ? (int) $p->moq : null,
                'price' => (float) $p->price,
                'sku' => (string) ($p->sku ?: ''),
                'image' => (string) ($p->image_url ?: ''),
            ];
        }
        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($grouped as $cat => $items) {
            $groups[] = ['category' => $cat, 'items' => $items];
        }

        $doc = app(CatalogRenderer::class)->pdf($t, $groups, $cur, true);
        if (! $doc) {
            abort(404, 'PDF engine unavailable');
        }

        return $this->stream($doc, $doc['fileName']);
    }

    /** @param array{path?:string,b64?:string} $doc */
    /**
     * GET /api/v1/doc/cashbook-export?format=pdf|csv — cash position (by currency AND by person) + every entry.
     * PDF is a printable statement; CSV opens straight in Excel. Scoped to the viewer (staff see only their own).
     */
    public function cashbookExport(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        $svc = app(CashbookExport::class);
        $company = strtolower((string) $r->query('view', '')) === 'company';
        $data = $svc->collect($t, $r->user(), (string) $r->query('from', ''), (string) $r->query('to', ''), (string) $r->query('currency', ''), $company, (string) $r->query('holder', ''));
        $stamp = now()->format('Ymd-Hi');
        $who = ! empty($data['holder']) ? ('-' . preg_replace('/[^A-Za-z0-9]+/', '', $data['holder'])) : '';
        $tag = $who . ($company ? '-Company-USD' : (! empty($data['currency']) ? ('-' . $data['currency']) : ''));

        if (strtolower((string) $r->query('format', 'pdf')) === 'csv') {
            return response($svc->csv($data), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Cashbook' . $tag . '-' . $stamp . '.csv"',
            ]);
        }

        $pdf = $svc->pdf($t, $data);
        if (! $pdf) {
            abort(404, 'PDF engine unavailable');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Cashbook' . $tag . '-' . $stamp . '.pdf"',
        ]);
    }

    private function stream(array $doc, string $filename)
    {
        $disk = Storage::disk('public');
        if (! empty($doc['path']) && $disk->exists($doc['path'])) {
            return $disk->response($doc['path'], $filename, ['Content-Type' => 'application/pdf'], 'inline');
        }
        $bytes = ! empty($doc['b64']) ? base64_decode($doc['b64']) : '';
        if ($bytes === '') {
            abort(404, 'PDF unavailable');
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
