<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Services\Catalog\PriceListRenderer;
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

    /** @param array{path?:string,b64?:string} $doc */
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
