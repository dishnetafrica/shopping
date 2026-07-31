<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Services\Catalog\CatalogRenderer;
use App\Services\Catalog\PriceListRenderer;
use App\Services\Finance\CashbookExport;
use App\Services\Finance\PurchasesExport;
use App\Services\Finance\ReceiptRenderer;
use App\Services\Finance\SupplierStatement;
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

    /**
     * GET /api/v1/doc/delivery-note/{id} — signature-ready delivery note for an order
     * (quantities only, no prices; received-by / delivered-by / authorized-signatory blocks).
     */
    public function deliveryNote(Request $r, int $id)
    {
        $t = $r->user()->tenant;
        $o = \App\Models\Order::where('tenant_id', $t->id)->find($id);
        if (! $o) {
            abort(404);
        }

        // Items: the relation is the source of truth; fall back to the legacy items_json blob
        // (older bot orders) so every order can print a note.
        $items = [];
        try {
            foreach ($o->items()->get() as $it) {
                $unit = '';
                try { $unit = (string) (optional(\App\Models\Product::where('tenant_id', $t->id)->find($it->product_id))->unit_label ?: ''); } catch (\Throwable $e) { }
                $items[] = ['name' => (string) $it->name, 'qty' => (float) $it->qty, 'unit' => $unit];
            }
        } catch (\Throwable $e) {
        }
        if (! $items) {
            $legacy = is_array($o->items_json) ? $o->items_json : (json_decode((string) $o->items_json, true) ?: []);
            foreach ($legacy as $it) {
                if (is_array($it) && ! empty($it['name'])) {
                    $items[] = ['name' => (string) $it['name'], 'qty' => (float) ($it['qty'] ?? 1), 'unit' => (string) ($it['unit'] ?? '')];
                }
            }
        }
        if (! $items) {
            abort(404, 'This order has no line items to print.');
        }

        $doc = app(\App\Services\Sales\DeliveryNoteRenderer::class)->pdf($t, [
            'order_no'      => (string) ($o->order_no ?: ('#' . $o->id)),
            'order_date'    => optional($o->created_at)->format('d M Y') ?? '',
            'delivery_date' => now()->format('d M Y'),
            'customer_name' => (string) ($o->customer_name ?: ''),
            'customer_phone'=> (string) ($o->customer_phone ?: ''),
            'deliver_to'    => (string) ($o->location ?: ''),
            'notes'         => (string) ($o->notes ?: ''),
            'items'         => $items,
        ]);
        if (! $doc) {
            abort(404, 'PDF engine unavailable');
        }

        return $this->stream($doc, $doc['fileName']);
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

    /**
     * GET /api/v1/doc/purchases-export?format=pdf|csv — purchases register across all suppliers.
     * Optional ?from=&to=&currency=&supplier_id= scope it (same filters as the Purchasing screen).
     */
    public function purchasesExport(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        $svc = app(PurchasesExport::class);
        $data = $svc->collect($t, [
            'from' => (string) $r->query('from', ''),
            'to' => (string) $r->query('to', ''),
            'currency' => (string) $r->query('currency', ''),
            'supplier_id' => (int) $r->query('supplier_id', 0),
            'pay' => (string) $r->query('pay', ''),
        ]);
        $stamp = now()->format('Ymd-Hi');
        $tag = ($data['currency'] ? ('-' . $data['currency']) : '') . ($data['supplier'] ? ('-' . preg_replace('/[^A-Za-z0-9]+/', '', $data['supplier'])) : '');

        if (strtolower((string) $r->query('format', 'pdf')) === 'csv') {
            return response($svc->csv($data), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Purchases' . $tag . '-' . $stamp . '.csv"',
            ]);
        }

        $pdf = $svc->pdf($t, $data);
        if (! $pdf) {
            abort(404, 'PDF engine unavailable');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Purchases' . $tag . '-' . $stamp . '.pdf"',
        ]);
    }

    /** GET /api/v1/doc/supplier-statement?id=X — payables statement (charges, payments, running balance owed). */
    public function supplierStatement(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        $svc = app(SupplierStatement::class);
        $data = $svc->collect($t, (int) $r->query('id', 0));
        if (! $data) {
            abort(404, 'Supplier not found');
        }
        $pdf = $svc->pdf($t, $data);
        if (! $pdf) {
            abort(404, 'PDF engine unavailable');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Statement-' . preg_replace('/[^A-Za-z0-9]+/', '', $data['supplier']) . '-' . now()->format('Ymd') . '.pdf"',
        ]);
    }

    /** GET /api/v1/doc/customer-statement?phone=... — branded account statement (invoices vs payments). */
    public function customerStatement(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        $phone = trim((string) $r->query('phone', ''));
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === '') {
            abort(404, 'Customer phone required');
        }
        $c = \App\Models\CustomerProfile::where('tenant_id', $t->id)
            ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$digits])->first();
        if (! $c) {
            abort(404, 'Customer not found');
        }

        $ctx = \App\Capability\Contracts\CapabilityContext::fromUser($r->user());
        $ctx->displayOverride = $r->query('cur');
        $stmt = app(\App\Capability\Shared\CustomerInsight::class)->statement($ctx, $c);

        $rows = array_map(fn ($row) => [
            'when' => $row['date'] ?? '',
            'desc' => $row['detail'] ?? '',
            'charge' => $row['drText'] ?? '',
            'payment' => $row['crText'] ?? '',
            'balance' => $row['balanceText'] ?? '',
        ], $stmt['rows'] ?? []);

        $pdf = app(\App\Services\Finance\CustomerStatement::class)->pdf([
            'business' => (string) ($t->name ?: 'Company'),
            'generated' => now()->format('d M Y, H:i'),
            'customer' => (string) ($c->name ?: $c->phone),
            'meta' => trim(implode(' · ', array_filter(['#' . $c->id, (string) $c->contact_person, (string) $c->phone]))),
            'currency' => (string) ($stmt['currency'] ?? 'USD'),
            'rows' => $rows,
            'closingText' => (string) ($stmt['balanceText'] ?? ''),
        ]);
        if (! $pdf) {
            abort(404, 'PDF engine unavailable');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Statement-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) ($c->name ?: $c->phone)) . '-' . now()->format('Ymd') . '.pdf"',
        ]);
    }

    /** GET /api/v1/doc/products-export?format=csv|zip|photos — export the catalogue. */
    public function productsExport(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        if (! $r->user()->canMenu('products')) {
            abort(403);
        }
        $svc = app(\App\Services\Catalog\CatalogExport::class);
        $format = strtolower((string) $r->query('format', 'csv'));
        $stamp = now()->format('Ymd');
        $base = 'Catalogue-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) ($t->name ?: 'catalog')) . '-' . $stamp;

        if ($format === 'zip') {
            $bytes = $svc->zip($t, true);
            if (! $bytes) { abort(404, 'ZIP engine unavailable'); }

            return response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="' . $base . '.zip"']);
        }
        if ($format === 'photos') {
            $bytes = $svc->photosZip($t);
            if (! $bytes) { abort(404, 'No product photos to export'); }

            return response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="' . $base . '-photos.zip"']);
        }

        // default: CSV
        return response($svc->csv($t, false), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $base . '.csv"',
        ]);
    }

    /** GET /api/v1/doc/spec-sheet — export products with their specifications as a CSV (Asset Lifecycle · S1c-1). */
    public function specSheet(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        if (! $r->user()->canMenu('products')) {
            abort(403);
        }
        $svc = app(\App\Services\Catalog\CatalogExport::class);
        $base = 'Specifications-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) ($t->name ?: 'catalog')) . '-' . now()->format('Ymd');

        return response($svc->specSheetCsv($t), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $base . '.csv"',
        ]);
    }

    /**
     * GET /api/v1/doc/tasks-export?format=pdf|csv — the team's task/job register (V3 Tasks screen).
     * Optional ?from=&to=&status=open|closed&assignee_id= scope it like the on-screen filters.
     */
    public function tasksExport(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) {
            abort(401);
        }
        $svc = app(\App\Services\Ops\TasksExport::class);
        $data = $svc->collect($t, [
            'from' => (string) $r->query('from', ''),
            'to' => (string) $r->query('to', ''),
            'status' => (string) $r->query('status', ''),
            'assignee_id' => (int) $r->query('assignee_id', 0),
        ]);
        $stamp = now()->format('Ymd-Hi');

        if (strtolower((string) $r->query('format', 'pdf')) === 'csv') {
            return response($svc->csv($data), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Tasks-' . $stamp . '.csv"',
            ]);
        }

        $pdf = $svc->pdf($t, $data);
        if (! $pdf) {
            abort(404, 'PDF engine unavailable');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Tasks-' . $stamp . '.pdf"',
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
