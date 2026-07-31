<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * POS — the supermarket till (2026-07-30). One fast screen: scan/search → cart → checkout.
 *
 * Deliberately thin: checkout creates a REAL Order exactly like the proven walk-in recipe
 * (status Delivered + channel pos), so the existing observer chain does all the heavy lifting —
 * OrderStockIssuer moves stock in the ledger (which also feeds the Trust engine), AutoIssueInvoice
 * numbers the invoice, CustomerMasterService files the customer. Payments are recorded by the
 * FRONT-END calling the existing /papi/record-payment (currency-aware, per-person cashbook,
 * invoice paid status) — no duplicated money logic here.
 *
 *   GET  /panel/pos            the screen
 *   GET  /papi/pos-products    search by name / SKU / barcode (?q=, ?category=)
 *   POST /papi/pos-checkout    create the sale (items, customer, discount, method)
 *   GET  /papi/pos-today       today's POS tally + recent sales
 */
class PosController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/pos.html');
        if (! is_file($path)) {
            abort(500, 'POS asset missing.');
        }
        $t = $user->tenant;
        // The LOCAL operating currency — what product prices (and therefore POS totals) are stored
        // in. A POS order carries no sell_currency, so its txnCurrency resolves to this same value,
        // which means the follow-up /papi/record-payment (no `cur` param) books the payment in the
        // exact currency the till displayed. One currency end-to-end, no conversion surprises.
        $cur = strtoupper((string) \App\Services\Money\SalesBaseCurrency::nativeCurrency($t));
        $html = str_replace(
            ['{{TENANT}}', '{{CURRENCY}}', '{{OPERATOR}}', '{{ADDRESS}}', '{{PHONE}}', '{{TIN}}'],
            [
                e((string) ($t->name ?? 'CloudBSS')),
                e($cur),
                e((string) ($user->name ?? '')),
                e(trim(implode(', ', array_filter([
                    (string) $t->setting('address', ''),
                    (string) $t->setting('city', ''),
                ])))),
                e(trim((string) ($t->setting('public_phone', '') ?: $t->whatsapp_number))),
                e(trim((string) $t->setting('tin', ''))),
            ],
            file_get_contents($path)
        );

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function products(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $q = trim((string) $request->query('q', ''));
        $cat = trim((string) $request->query('category', ''));
        // ?all=1 → the till downloads the whole active catalogue ONCE and keeps it in
        // localStorage, so scanning and selling keep working when the internet drops.
        $all = (string) $request->query('all', '') === '1';

        $query = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('active', true)->where('price', '>', 0);
        if ($cat !== '') {
            $query->whereRaw("LOWER(COALESCE(category, '')) = ?", [mb_strtolower($cat)]);
        }
        if ($q !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->whereRaw('LOWER(name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$like])
                  ->orWhere('barcode', $q);
            });
        }
        $items = $query->orderBy('display_order')->orderBy('name')->limit($all ? 1000 : 40)
            ->get(['id', 'name', 'price', 'sku', 'barcode', 'unit_label', 'stock', 'category', 'image_url']);

        // Exact scan hit: barcode or SKU matches the query verbatim → the till adds it instantly.
        $exact = null;
        if ($q !== '') {
            foreach ($items as $p) {
                if (strcasecmp((string) $p->barcode, $q) === 0 || strcasecmp((string) $p->sku, $q) === 0) {
                    $exact = $p->id;
                    break;
                }
            }
        }

        $cats = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('active', true)->where('price', '>', 0)
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->limit(24)->pluck('category');

        return response()->json(['ok' => true, 'items' => $items, 'exact' => $exact, 'categories' => $cats]);
    }

    public function checkout(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $data = $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.name'     => 'required|string|max:200',
            'items.*.price'    => 'required|numeric|min:0',
            'items.*.qty'      => 'required|numeric|min:0.001',
            'customer_name'    => 'nullable|string|max:120',
            'customer_phone'   => 'nullable|string|max:40',
            'discount'         => 'nullable|numeric|min:0',
            'method'           => 'required|string|in:cash,momo,card,credit,split',
            'payments'         => 'nullable|array',            // split tender: [{method, amount}]
            'payments.*.method' => 'required_with:payments|string|in:cash,momo,card',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'note'             => 'nullable|string|max:300',
            // Offline-queue idempotency: the till generates one ref per sale; retries with the
            // same ref can NEVER create a second order (orders.idempotency_key is unique).
            'client_ref'       => 'nullable|string|max:100',
        ]);

        $sub = 0.0;
        foreach ($data['items'] as $l) {
            $sub += (float) $l['price'] * (float) $l['qty'];
        }
        $discount = min((float) ($data['discount'] ?? 0), $sub);
        $total = round($sub - $discount, 2);

        $method = (string) $data['method'];
        $name = trim((string) ($data['customer_name'] ?? ''));
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        $tendered = 0.0;
        foreach ((array) ($data['payments'] ?? []) as $p) {
            $tendered += (float) $p['amount'];
        }
        // A sale that is not fully paid NOW (pure credit, or a split that leaves a balance)
        // must belong to a named customer — otherwise the debt has no owner.
        $short = ($method === 'credit') || ($method === 'split' && $tendered + 0.01 < $total);
        if ($short && $phone === '' && $name === '') {
            return response()->json(['ok' => false, 'error' => 'Credit sale needs the customer name or phone.'], 422);
        }

        $ref = trim((string) ($data['client_ref'] ?? ''));
        $idem = $ref !== '' ? ('pos:' . $t->id . ':' . $ref) : null;
        if ($idem !== null) {
            // Fast path for offline-queue retries: the sale already exists → return it, and tell
            // the till how much is already recorded so it never double-books a payment.
            $dup = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->where('idempotency_key', $idem)->first();
            if ($dup) {
                return response()->json([
                    'ok' => true, 'duplicate' => true, 'id' => $dup->id,
                    'order_no' => (string) $dup->order_no, 'total' => (float) $dup->total,
                    'paid' => (float) $dup->amount_paid,
                    'record_payment' => $method !== 'credit' && (float) $dup->amount_paid <= 0,
                ]);
            }
        }

        $itemsText = collect($data['items'])
            ->map(fn ($l) => rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') . 'x ' . $l['name'])
            ->implode(', ');
        $noteBits = array_filter([
            $discount > 0 ? 'POS discount ' . $discount : null,
            trim((string) ($data['note'] ?? '')) ?: null,
        ]);

        try {
            // The proven walk-in recipe: Delivered + channel pos → observers issue stock,
            // auto-invoice, and file the customer. (Same as the legacy Filament POS.)
            $order = Order::create([
                'tenant_id'      => $t->id,
                'idempotency_key' => $idem,
                'customer_name'  => $name !== '' ? $name : 'Walk-in',
                'customer_phone' => $phone !== '' ? $phone : null,
                'items_text'     => $itemsText,
                'items_json'     => $data['items'],
                'total'          => $total,
                'payment'        => $method,
                'status'         => 'Delivered',
                'channel'        => 'pos',
                'notes'          => $noteBits ? implode(' · ', $noteBits) : null,
            ]);
            foreach ($data['items'] as $l) {
                OrderItem::create([
                    'tenant_id'  => $t->id,
                    'order_id'   => $order->id,
                    'product_id' => (int) $l['product_id'],
                    'name'       => (string) $l['name'],
                    'price'      => (float) $l['price'],
                    'qty'        => (float) $l['qty'],
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Race on the unique idempotency key (two sync attempts at once) → same answer as
            // the fast path above: return the existing sale, never a duplicate.
            if ($idem !== null && str_contains((string) $e->getMessage(), 'idempotency')) {
                $dup = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->where('idempotency_key', $idem)->first();
                if ($dup) {
                    return response()->json([
                        'ok' => true, 'duplicate' => true, 'id' => $dup->id,
                        'order_no' => (string) $dup->order_no, 'total' => (float) $dup->total,
                        'paid' => (float) $dup->amount_paid,
                        'record_payment' => $method !== 'credit' && (float) $dup->amount_paid <= 0,
                    ]);
                }
            }
            \Log::warning('pos-checkout failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Could not save the sale.'], 500);
        } catch (\Throwable $e) {
            \Log::warning('pos-checkout failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Could not save the sale.'], 500);
        }

        return response()->json([
            'ok'        => true,
            'duplicate' => false,
            'id'        => $order->id,
            'order_no'  => (string) $order->order_no,
            'total'     => $total,
            'discount'  => $discount,
            'subtotal'  => round($sub, 2),
            'paid'      => 0.0,
            // cash/momo/card/split: the screen records each tender via /papi/record-payment
            // (order id + amount + method) so the cashbook and invoice stay correct.
            'record_payment' => $method !== 'credit',
        ]);
    }

    public function today(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        // items_json rides along so the drawer can REPRINT any receipt of the day.
        $rows = Order::where('tenant_id', $t->id)->where('channel', 'pos')
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('id')->limit(15)
            ->get(['id', 'order_no', 'customer_name', 'total', 'payment', 'created_at', 'items_json', 'notes']);
        $sum = Order::where('tenant_id', $t->id)->where('channel', 'pos')
            ->whereDate('created_at', now()->toDateString())
            ->where('status', '!=', 'Cancelled')->sum('total');
        $count = Order::where('tenant_id', $t->id)->where('channel', 'pos')
            ->whereDate('created_at', now()->toDateString())
            ->where('status', '!=', 'Cancelled')->count();

        return response()->json(['ok' => true, 'total' => (float) $sum, 'count' => $count, 'recent' => $rows]);
    }
}
