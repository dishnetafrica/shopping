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
        // POS discount control: limit % + whether a manager PIN is configured (never the PIN itself).
        $discLimit = (float) ($t->setting('pos_discount_limit', 5) ?: 5);
        $pinSet = trim((string) $t->setting('pos_manager_pin', '')) !== '' ? '1' : '';
        $html = str_replace(
            ['{{TENANT}}', '{{CURRENCY}}', '{{OPERATOR}}', '{{ADDRESS}}', '{{PHONE}}', '{{TIN}}', '{{DISCLIMIT}}', '{{PINSET}}'],
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
                e((string) $discLimit),
                $pinSet,
            ],
            file_get_contents($path)
        );

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /panel/pos-display — the CUSTOMER-facing second screen (v4.1). Opened from the till's
     * 🖥 button into its own window (drag to the second monitor, F11). No polling, no sockets:
     * the till broadcasts every change via localStorage and this page listens to the browser's
     * cross-window 'storage' events — same machine, same origin, effectively zero latency.
     */
    public function display(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/pos-display.html');
        if (! is_file($path)) {
            abort(500, 'Display asset missing.');
        }
        $t = $user->tenant;
        $cur = strtoupper((string) \App\Services\Money\SalesBaseCurrency::nativeCurrency($t));
        $html = str_replace(
            ['{{TENANT}}', '{{CURRENCY}}', '{{LOGO}}', '{{TAGLINE}}'],
            [
                e((string) ($t->name ?? 'CloudBSS')),
                e($cur),
                e(trim((string) $t->setting('logo', ''))),
                e(trim((string) ($t->setting('tagline', '') ?: 'Welcome — thank you for shopping with us'))),
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
            'manager_pin'      => 'nullable|string|max:20',
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

        // Manager authorisation for big discounts — enforced SERVER-side (a till UI can be
        // bypassed; this cannot). Zero-setup default: no PIN configured → no gate. Checked
        // AFTER the duplicate fast-path above so replays of already-created sales always
        // resolve even if the owner changes the PIN later.
        $pin = trim((string) $t->setting('pos_manager_pin', ''));
        if ($pin !== '' && $discount > 0 && $sub > 0) {
            $limitPct = (float) $t->setting('pos_discount_limit', 5);
            if (($discount / $sub) * 100 > $limitPct
                && ! hash_equals($pin, trim((string) ($data['manager_pin'] ?? '')))) {
                return response()->json(['ok' => false, 'need_pin' => true, 'error' => 'This discount needs the manager PIN.'], 422);
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

    /* ------------- customer on the sale (v4.3) ------------- */

    /**
     * GET /papi/pos-customers?q= — phone/name lookup for the till's customer picker.
     * Empty q → the most recently active customers (fast pick for regulars). Attaching a
     * customer to a sale needs NO create step: CustomerMasterService files/refreshes the
     * CustomerProfile from the order automatically; /papi/customer-save adds richer details.
     */
    public function customers(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $q = trim((string) $request->query('q', ''));
        $query = \App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id);
        // v4.60 — a deliberate split. With no search term this returns the eight most recent
        // profiles, and thousands of discovered businesses would bury the people who actually come
        // to the counter. When the cashier IS searching, a discovered business must be findable:
        // the order path matches on phone and would otherwise adopt or duplicate the row anyway,
        // and a sale is the one event that turns a discovered business into a customer.
        if ($q === '') {
            \App\Services\Crm\BusinessStage::scope($query);
        }
        if ($q !== '') {
            $digits = preg_replace('/[^0-9]/', '', $q);
            $like = '%' . mb_strtolower($q) . '%';
            $query->where(function ($w) use ($digits, $like) {
                $w->whereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', [$like]);
                if (strlen($digits) >= 4) {
                    $w->orWhere('phone', 'LIKE', '%' . $digits . '%');
                }
            });
        }
        $rows = $query->orderByDesc('updated_at')->limit(8)
            ->get(['phone', 'name', 'address'])
            ->map(fn ($c) => ['phone' => (string) $c->phone, 'name' => (string) ($c->name ?? ''), 'address' => (string) ($c->address ?? '')]);

        return response()->json(['ok' => true, 'customers' => $rows]);
    }

    /* ------------- quotations at the counter (v4.2): cart → numbered quote PDF ------------- */

    public function quoteCreate(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $data = $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.name'     => 'required|string|max:200',
            'items.*.price'    => 'required|numeric|min:0',
            'items.*.qty'      => 'required|numeric|min:0.001',
            'customer_name'    => 'nullable|string|max:120',
            'customer_phone'   => 'nullable|string|max:40',
        ]);

        $svc = app(\App\Services\Bot\QuotationService::class);
        if (! $svc->available()) {
            return response()->json(['ok' => false, 'error' => 'PDF engine unavailable.'], 422);
        }

        // Same document pipeline as the WhatsApp bot (QuotationService) — the counter and the
        // bot produce IDENTICAL numbered quotes, PDFs, and customer-timeline events.
        $lines = [];
        $sub = 0.0;
        foreach ($data['items'] as $l) {
            $sum = round((float) $l['price'] * (float) $l['qty'], 2);
            $sub += $sum;
            $lines[] = ['matched' => true, 'name' => (string) $l['name'], 'qty' => (float) $l['qty'],
                        'price' => (float) $l['price'], 'sum' => $sum];
        }
        $quote = [
            'lines'    => $lines,
            'currency' => strtoupper((string) \App\Services\Money\SalesBaseCurrency::nativeCurrency($t)),
            'total'    => round($sub, 2),
        ];
        $name = trim((string) ($data['customer_name'] ?? ''));
        $phone = trim((string) ($data['customer_phone'] ?? ''));

        $doc = $svc->generate($t, $phone, $name !== '' ? $name : 'Walk-in', $quote);
        if (! $doc) {
            return response()->json(['ok' => false, 'error' => 'Could not build the quotation PDF.'], 500);
        }
        $q = $svc->persist($t, $phone, $name !== '' ? $name : 'Walk-in', $quote, $doc, 'pos');

        return response()->json([
            'ok' => true,
            'id' => $q?->id,
            'quote_no' => (string) $doc['no'],
            'total' => (float) $doc['total'],
            'currency' => (string) $doc['currency'],
            'pdf_url' => $q ? ('/papi/quotation-pdf?id=' . $q->id) : (string) ($doc['url'] ?? ''),
        ]);
    }

    public function quotes(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $rows = \App\Models\Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->orderByDesc('id')->limit(15)
            ->get(['id', 'quote_no', 'customer_name', 'customer_phone', 'total', 'currency', 'status', 'source', 'created_at'])
            ->map(fn ($q) => [
                'id' => $q->id, 'quote_no' => (string) $q->quote_no,
                'customer' => (string) ($q->customer_name ?? ''), 'phone' => (string) ($q->customer_phone ?? ''),
                'total' => (float) $q->total, 'currency' => (string) $q->currency,
                'status' => (string) $q->status, 'source' => (string) $q->source,
                'date' => optional($q->created_at)->format('Y-m-d H:i'),
                'pdf_url' => '/papi/quotation-pdf?id=' . $q->id,
            ]);

        return response()->json(['ok' => true, 'quotes' => $rows]);
    }

    /** Load a quotation's lines back into the till cart (quote → sale at the counter). */
    public function quoteLoad(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $q = \App\Models\Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $q) {
            return response()->json(['ok' => false, 'error' => 'Quotation not found.'], 404);
        }
        $lines = [];
        $skipped = [];
        foreach (\App\Models\QuotationItem::withoutGlobalScopes()->where('tenant_id', $t->id)->where('quotation_id', $q->id)->get() as $it) {
            // The cart needs a product_id (checkout validates it) — resolve by exact name.
            $pid = (int) (Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $it->name)])->value('id') ?? 0);
            if ($pid > 0) {
                $lines[] = ['product_id' => $pid, 'name' => (string) $it->name,
                            'price' => (float) $it->unit_price, 'qty' => (float) $it->qty];
            } else {
                $skipped[] = (string) $it->name;
            }
        }

        return response()->json([
            'ok' => true, 'quote_no' => (string) $q->quote_no,
            'customer' => (string) ($q->customer_name ?? ''), 'phone' => (string) ($q->customer_phone ?? ''),
            'lines' => $lines, 'skipped' => $skipped,
        ]);
    }

    /* ------------- returns (v4.0): find receipt → select lines → stock back + credit note ------------- */

    /**
     * Per-product quantities already returned against an order (from structured returns).
     * v4.88 (ADR-019) — delegates to ReturnPolicy so the till and the panel share ONE cap rule
     * and ONE line-identity rule. Two doors computing "how much is left to return" from two
     * copies of the same arithmetic is how a customer ends up returning 60 of 50 cartons.
     */
    private function returnedMap(int $orderId, int $tenantId): array
    {
        try {
            return \App\Services\Sales\ReturnPolicy::returnedMap(
                \App\Models\ReturnRecord::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('order_id', $orderId)->get()
            );
        } catch (\Throwable $e) { /* legacy rows without items_json simply don't restrict */ }

        return [];
    }

    public function findSale(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['ok' => false, 'error' => 'Type a receipt number or phone.'], 422);
        }

        $base = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('created_at', '>=', now()->subDays(60));
        $digits = preg_replace('/[^0-9]/', '', $q);
        $orders = (clone $base)->where('order_no', $q)->orderByDesc('id')->limit(5)->get();
        if ($orders->isEmpty() && ctype_digit($q)) {
            $orders = (clone $base)->where('id', (int) $q)->limit(1)->get();
        }
        if ($orders->isEmpty() && strlen($digits) >= 6) {
            $orders = (clone $base)->where('customer_phone', 'LIKE', '%' . substr($digits, -9) . '%')
                ->orderByDesc('id')->limit(5)->get();
        }
        if ($orders->isEmpty()) {
            return response()->json(['ok' => true, 'sales' => []]);
        }

        $sales = [];
        foreach ($orders as $o) {
            $returned = $this->returnedMap($o->id, $t->id);
            $lines = [];
            foreach (\App\Services\Inventory\OrderStockIssuer::lines($o) as $ln) {
                $key = \App\Services\Sales\ReturnPolicy::key($ln);   // v4.88 — one identity rule
                $lines[] = [
                    'product_id' => $ln['product_id'], 'name' => $ln['name'], 'qty' => $ln['qty'],
                    'returned' => round((float) ($returned[$key] ?? 0), 3),
                ];
            }
            // Unit prices come from the order's own lines (items_json / OrderItems), never today's list.
            $prices = [];
            foreach ((array) $o->items_json as $l) {
                if (is_array($l)) {
                    $k = \App\Services\Sales\ReturnPolicy::key($l);   // v4.88 — one identity rule
                    $prices[$k] = (float) ($l['price'] ?? 0);
                }
            }
            foreach (OrderItem::where('order_id', $o->id)->get() as $it) {
                $prices[(int) $it->product_id] = (float) $it->price;
            }
            foreach ($lines as &$ln) {
                $k = \App\Services\Sales\ReturnPolicy::key($ln);   // v4.88 — one identity rule
                $ln['price'] = round((float) ($prices[$k] ?? 0), 2);
            }
            unset($ln);
            $sales[] = [
                'id' => $o->id, 'order_no' => (string) $o->order_no,
                'date' => optional($o->created_at)->format('Y-m-d H:i'),
                'customer' => (string) ($o->customer_name ?? ''), 'phone' => (string) ($o->customer_phone ?? ''),
                'total' => (float) $o->total, 'channel' => (string) $o->channel, 'lines' => $lines,
            ];
        }

        return response()->json(['ok' => true, 'sales' => $sales]);
    }

    public function processReturn(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $data = $request->validate([
            'order_id'        => 'required|integer',
            'lines'           => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|integer',
            'lines.*.name'    => 'required|string|max:200',
            'lines.*.price'   => 'required|numeric|min:0',
            'lines.*.qty'     => 'required|numeric|min:0.001',
            'refund_method'   => 'required|string|in:cash,momo,store_credit',
            'reason'          => 'nullable|string|max:300',
            'phone'           => 'nullable|string|max:40',
            'manager_pin'     => 'nullable|string|max:20',
        ]);

        $o = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $data['order_id']);
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // EVERY return needs the manager PIN when one is configured — returns are the classic
        // till-fraud vector, and they move real cash out of the drawer.
        $pin = trim((string) $t->setting('pos_manager_pin', ''));
        if ($pin !== '' && ! hash_equals($pin, trim((string) ($data['manager_pin'] ?? '')))) {
            return response()->json(['ok' => false, 'need_pin' => true, 'error' => 'Returns need the manager PIN.'], 422);
        }

        // Validate each line against what was SOLD minus what was ALREADY returned.
        $sold = [];
        foreach (\App\Services\Inventory\OrderStockIssuer::lines($o) as $ln) {
            $key = \App\Services\Sales\ReturnPolicy::key($ln);   // v4.88 — one identity rule
            $sold[$key] = ($sold[$key] ?? 0) + $ln['qty'];
        }
        $returned = $this->returnedMap($o->id, $t->id);
        $amount = 0.0;
        $clean = [];
        foreach ($data['lines'] as $l) {
            $key = \App\Services\Sales\ReturnPolicy::key($l);   // v4.88 — one identity rule
            $max = (float) ($sold[$key] ?? 0) - (float) ($returned[$key] ?? 0);
            if ((float) $l['qty'] > $max + 0.0001) {
                return response()->json(['ok' => false, 'error' => 'Cannot return ' . $l['qty'] . ' × ' . $l['name'] . ' — only ' . max(0, $max) . ' left to return on this sale.'], 422);
            }
            $clean[] = ['product_id' => (int) ($l['product_id'] ?? 0), 'name' => (string) $l['name'],
                        'price' => round((float) $l['price'], 2), 'qty' => (float) $l['qty']];
            $amount += (float) $l['price'] * (float) $l['qty'];
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return response()->json(['ok' => false, 'error' => 'Nothing to refund.'], 422);
        }

        $phone = preg_replace('/[^0-9]/', '', (string) ($data['phone'] ?? '')) ?: preg_replace('/[^0-9]/', '', (string) ($o->customer_phone ?? ''));
        if ($data['refund_method'] === 'store_credit' && $phone === '') {
            return response()->json(['ok' => false, 'error' => 'Store credit needs the customer phone number.'], 422);
        }

        try {
            $ret = \App\Models\ReturnRecord::create([
                'tenant_id'      => $t->id,
                'order_id'       => $o->id,
                'order_no'       => (string) $o->order_no,
                'customer_phone' => $phone,
                'customer_name'  => (string) ($o->customer_name ?? ''),
                'items_text'     => collect($clean)->map(fn ($l) => rtrim(rtrim(number_format($l['qty'], 2), '0'), '.') . 'x ' . $l['name'])->implode(', '),
                'items_json'     => $clean,
                'amount'         => $amount,
                // v4.89 — the till stays ONE step on purpose: the customer is standing here with the
                // goods, so recording and receiving are the same moment. Stated explicitly rather
                // than leaning on the column default, so the intent survives a schema change.
                'status'         => \App\Services\Sales\ReturnPolicy::COMPLETED,
                'received_at'    => now(),
                'received_by'    => (string) ($u->name ?? ''),
                // 'credit' feeds the EXISTING store-credit-per-phone map; cash/momo are pure refunds.
                'resolution'     => $data['refund_method'] === 'store_credit' ? 'credit' : 'refund',
                'refund_method'  => $data['refund_method'],
                'reason'         => trim((string) ($data['reason'] ?? '')) ?: null,
                'user_id'        => $u->id,
                'operator'       => (string) ($u->name ?? ''),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('pos-return record failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Could not save the return.'], 500);
        }

        // The ACCOUNTING reverse: a numbered credit note (statement-visible, PDF stored),
        // linked to this return and to the sale's invoice when one exists. Best-effort:
        // the return record stands even if CN issuing hiccups (logged for follow-up).
        $cnNo = null;
        try {
            $inv = \App\Models\Invoice::withoutGlobalScopes()->where('tenant_id', $t->id)->where('order_id', $o->id)->orderByDesc('id')->first();
            $cn = app(\App\Services\Invoicing\CreditNoteService::class)->issue($t, [
                'customer'    => (string) ($o->customer_name ?? ''),
                'phone'       => $phone,
                'cur'         => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
                'total'       => $amount,
                'reason_code' => 'return',
                'notes'       => 'POS return on ' . $o->order_no . ($data['reason'] ?? null ? ' — ' . $data['reason'] : ''),
                'invoice_id'  => $inv?->id,
                'invoice_no'  => $inv?->invoice_no,
                'order_id'    => $o->id,
                'return_id'   => $ret->id,
                'lines'       => array_map(fn ($l) => [
                    'name' => $l['name'], 'qty' => $l['qty'], 'unit' => $l['price'],
                    'amount' => round($l['price'] * $l['qty'], 2),
                ], $clean),
            ], 'pos');
            $cnNo = (string) $cn->credit_note_no;
            $ret->credit_note_no = $cnNo;
            $ret->save();
        } catch (\Throwable $e) {
            \Log::warning('pos-return credit note failed: ' . $e->getMessage());
        }

        // Stock back on the shelf — SAME ledger the sale used, so Trust sees returns too.
        // Symmetric with the sale path: when the ledger is off, the sale didn't move stock
        // and neither does the return.
        $restocked = 0;
        try {
            if (\App\Services\Inventory\OrderStockIssuer::active($t)) {
                $sl = \App\Services\Inventory\StockLedger::forTenant($t);
                foreach ($clean as $l) {
                    if ($l['product_id'] > 0) {
                        $sl->receive([
                            'product_id' => $l['product_id'], 'sku' => null,
                            'location'   => \App\Services\Inventory\StockLedger::home($t),
                            'qty'        => $l['qty'],
                            'ref_type'   => 'pos_return', 'ref_id' => $ret->id,
                            'note'       => 'POS return ' . ($cnNo ?: ('#' . $ret->id)) . ' on ' . $o->order_no . ' — ' . $l['name'],
                            'created_by' => (string) ($u->name ?? 'pos'),
                        ]);
                        $restocked++;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('pos-return restock failed: ' . $e->getMessage());
        }

        // Cash/momo leaves the drawer — mirror it into the legacy cashbook (best-effort; the
        // credit note above is the authoritative accounting document either way).
        if (in_array($data['refund_method'], ['cash', 'momo'], true)) {
            try {
                \App\Models\LedgerEntry::create([
                    'type' => 'out', 'category' => 'refund', 'order_id' => $o->id,
                    'amount' => $amount, 'currency' => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
                    'method' => $data['refund_method'], 'received_by' => (string) ($u->name ?? ''),
                    'note' => 'POS return ' . ($cnNo ?: ('#' . $ret->id)) . ' on ' . $o->order_no,
                ]);
            } catch (\Throwable $e) { /* cashbook mirror only */ }
        }

        return response()->json([
            'ok' => true, 'return_id' => $ret->id, 'credit_note_no' => $cnNo,
            'amount' => $amount, 'refund_method' => $data['refund_method'],
            'order_no' => (string) $o->order_no, 'restocked_lines' => $restocked,
            'customer' => (string) ($o->customer_name ?? ''), 'phone' => $phone,
        ]);
    }

    /* ------------- shifts (v3.9): float in → sales → denomination count → variance ------------- */

    private function currentShift(Request $request): ?\App\Models\PosShift
    {
        $u = $request->user();

        return \App\Models\PosShift::where('tenant_id', $u->tenant_id)
            ->where('user_id', $u->id)->whereNull('closed_at')
            ->orderByDesc('id')->first();
    }

    /** Cash THIS operator took for POS sales since the shift opened (from the unified payment ledger). */
    private function shiftCash(\App\Models\PosShift $s, $t, $u): float
    {
        try {
            $posNos = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('channel', 'pos')->where('created_at', '>=', $s->opened_at)
                ->pluck('order_no')->filter()->values();
            if (! count($posNos)) {
                return 0.0;
            }

            $in = (float) \App\Models\CustomerPayment::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('method', 'cash')
                ->where('holder_user_id', $u->id)
                ->where('created_at', '>=', $s->opened_at)
                ->whereIn('order_no', $posNos)
                ->sum('amount');
            // Cash refunds THIS operator paid out of the drawer during the shift (v4.0 returns).
            $out = 0.0;
            try {
                $out = (float) \App\Models\ReturnRecord::withoutGlobalScopes()->where('tenant_id', $t->id)
                    ->where('user_id', $u->id)
                    ->where('refund_method', 'cash')
                    ->where('created_at', '>=', $s->opened_at)
                    ->sum('amount');
            } catch (\Throwable $e) { /* pre-migration tenants: no refund column yet */ }

            return round($in - $out, 2);
        } catch (\Throwable $e) {
            \Log::warning('pos shiftCash failed: ' . $e->getMessage());

            return 0.0;
        }
    }

    public function shift(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $s = $this->currentShift($request);
        if (! $s) {
            return response()->json(['ok' => true, 'open' => false]);
        }
        $cash = $this->shiftCash($s, $t, $u);

        return response()->json([
            'ok' => true, 'open' => true, 'id' => $s->id,
            'opened_at' => $s->opened_at?->format('Y-m-d H:i'),
            'float' => (float) $s->opening_float,
            'cash_sales' => round($cash, 2),
            'expected' => round((float) $s->opening_float + $cash, 2),
        ]);
    }

    public function shiftOpen(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        if ($this->currentShift($request)) {
            return response()->json(['ok' => false, 'error' => 'You already have an open shift — close it first.'], 422);
        }
        $data = $request->validate(['float' => 'nullable|numeric|min:0']);
        $s = \App\Models\PosShift::create([
            'tenant_id' => $t->id, 'user_id' => $u->id,
            'operator' => (string) ($u->name ?? ''),
            'opened_at' => now(),
            'opening_float' => round((float) ($data['float'] ?? 0), 2),
            'currency' => strtoupper((string) \App\Services\Money\SalesBaseCurrency::nativeCurrency($t)),
        ]);

        return response()->json(['ok' => true, 'id' => $s->id, 'opened_at' => $s->opened_at->format('Y-m-d H:i'), 'float' => (float) $s->opening_float]);
    }

    public function shiftClose(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $s = $this->currentShift($request);
        if (! $s) {
            return response()->json(['ok' => false, 'error' => 'No open shift.'], 422);
        }
        $data = $request->validate([
            'denominations'   => 'nullable|array',       // {"1000": 15, "500": 20}
            'denominations.*' => 'numeric|min:0',
            'other'           => 'nullable|numeric|min:0', // coins / odd notes as one amount
            'note'            => 'nullable|string|max:300',
        ]);

        // The SERVER does the arithmetic — the slip's counted total can never disagree
        // with what was typed, and the denomination sheet is stored for audit.
        $counted = round((float) ($data['other'] ?? 0), 2);
        $denoms = [];
        foreach ((array) ($data['denominations'] ?? []) as $note => $cnt) {
            $noteVal = (float) $note;
            $cnt = (float) $cnt;
            if ($noteVal <= 0 || $cnt <= 0) {
                continue;
            }
            $denoms[(string) $note] = $cnt;
            $counted += $noteVal * $cnt;
        }
        if ((float) ($data['other'] ?? 0) > 0) {
            $denoms['other'] = round((float) $data['other'], 2);
        }
        $counted = round($counted, 2);

        $cash = $this->shiftCash($s, $t, $u);
        $expected = round((float) $s->opening_float + $cash, 2);
        $variance = round($counted - $expected, 2);

        $s->fill([
            'closed_at' => now(),
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'variance' => $variance,
            'denominations' => $denoms ?: null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ])->save();

        return response()->json([
            'ok' => true, 'id' => $s->id,
            'opened_at' => $s->opened_at?->format('Y-m-d H:i'),
            'closed_at' => $s->closed_at?->format('Y-m-d H:i'),
            'float' => (float) $s->opening_float,
            'cash_sales' => round($cash, 2),
            'expected' => $expected, 'counted' => $counted, 'variance' => $variance,
            'denominations' => $denoms,
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
