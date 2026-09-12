<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product transaction ledger for the Juba Stock drill-down.
 *
 * This is the SAME reconciliation as JubaStockCapability, resolved for ONE product and kept at
 * line detail instead of summed. It exists so the drill-down and the list can never disagree: the
 * repo rule is one deriver per fact, so the predicates below are copied from that screen VERBATIM
 * and cross-referenced, and a harness (qa/juba_ledger.php) proves the aggregates this returns match
 * the screen's formula across the tricky cases (void, the date gate, invoice-over-order priority,
 * reserved, a re-count that replaces).
 *
 *   Opening (the LATEST physical count)  +  Bought since  −  Sold since  =  Current (free to sell)
 *   On shelf = Current + Reserved            (reserved = sold on an order still standing here)
 *
 * Every number traces to a document, never to products.stock:
 *   Opening    stock_openings          (latest counted_at per product wins; earlier ones are history)
 *   Purchases  order_costs             (stage=purchase, not voided, product_id set, qty>0)
 *   Sales      invoice_items→invoices  (status≠void; matched to the product by EXACT name/alias,
 *              then uninvoiced orders' items_json, then order_items — each order counted once)
 *
 * Read-only. It derives, never writes.
 *
 * The core — derive() and byName() — is pure over plain arrays so it runs under the sandbox harness
 * with no database. forProduct() is the thin loader that reads the rows and hands them to derive().
 */
final class JubaLedger
{
    /**
     * Order states where the goods are SOLD but have NOT left the building — copied from
     * JubaStockCapability::ON_SHELF_STATUSES. An INVOICE never counts as reserved (billing is the
     * strongest statement that goods left); only an uninvoiced order at a pre-dispatch status does.
     */
    private const ON_SHELF_STATUSES = [
        'new', 'accepted', 'confirmed', 'preparing', 'packed', 'ready',
        'pending', 'processing', 'draft', 'quote', 'quotation', 'on hold', 'awaiting payment',
    ];

    /** Case-folded, whitespace-collapsed — the same normaliser the screen and the matcher use. */
    public static function norm(string $n): string
    {
        return PurchaseNameMatch::key($n);
    }

    /**
     * Load one product's ledger from the database and derive it.
     *
     * @return array{
     *   product: array{id:int,name:string,sku:string,category:string,unit:string,reorder:?int},
     *   opening: array{qty:float,at:?string,by:string,history:array<int,array{qty:float,at:string,by:string}>,new:bool},
     *   summary: array{opening:float,bought:float,sold:float,current:float,reserved:float,onShelf:float},
     *   purchases: array<int,array<string,mixed>>,
     *   sales: array<int,array<string,mixed>>,
     *   ledger: array<int,array<string,mixed>>
     * }
     */
    public static function forProduct(int $tenantId, int $productId): array
    {
        $p = Product::where('tenant_id', $tenantId)->findOrFail($productId);

        // Openings — ALL of the shop's counts, so firstOpen (the era boundary) is the same value the
        // list uses, and every count of THIS product is available as re-count history.
        $openings = [];
        if (Schema::hasTable('stock_openings')) {
            $openings = DB::table('stock_openings')->where('tenant_id', $tenantId)
                ->orderBy('product_id')->orderBy('counted_at')->orderBy('id')
                ->get(['product_id', 'qty', 'counted_at', 'counted_by'])
                ->map(fn ($r) => [
                    'product_id' => (int) $r->product_id,
                    'qty'        => (float) $r->qty,
                    'at'         => (string) $r->counted_at,
                    'by'         => (string) ($r->counted_by ?? ''),
                ])->all();
        }

        // Purchases — this product's own purchase lines (both sides of the count; the gate is applied
        // in derive()). native_amount is what was actually paid for the line.
        $purchases = [];
        try {
            $purchases = DB::table('order_costs')
                ->where('tenant_id', $tenantId)->where('stage', 'purchase')->whereNull('voided_at')
                ->where('product_id', $productId)->where('qty', '>', 0)
                ->orderBy('created_at')->orderBy('id')
                ->get(['id', 'qty', 'unit_cost', 'native_amount', 'currency', 'supplier_name',
                       'order_no', 'created_by', 'created_at'])
                ->map(fn ($r) => [
                    'qty'      => (float) $r->qty,
                    'unit'     => $r->unit_cost === null ? null : (float) $r->unit_cost,
                    'total'    => (float) $r->native_amount,
                    'currency' => strtoupper((string) ($r->currency ?? '')),
                    'supplier' => trim((string) ($r->supplier_name ?? '')),
                    'ref'      => (string) ($r->order_no ?? ''),
                    'user'     => trim((string) ($r->created_by ?? '')),
                    'at'       => (string) $r->created_at,
                ])->all();
        } catch (\Throwable $e) {
        }

        // The tenant-wide name→product map, EXACTLY as the screen builds it, so an invoice line is
        // resolved to this product only when the screen would resolve it here too (and an ambiguous
        // wording claimed by two products resolves to neither).
        $byName = self::byName(
            Product::where('tenant_id', $tenantId)->get(['id', 'name', 'keywords'])
                ->map(fn ($pr) => ['id' => (int) $pr->id, 'name' => (string) $pr->name, 'keywords' => (string) ($pr->keywords ?? '')])
                ->all()
        );

        // Live invoice lines, tenant-wide (we filter to this product inside derive via byName, so an
        // ambiguous/renamed wording is handled identically to the screen).
        $invoiceLines = [];
        try {
            $invoiceLines = DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.tenant_id', $tenantId)
                ->where('invoices.status', '!=', 'void')
                ->get(['invoice_items.name', 'invoice_items.qty', 'invoice_items.unit_price',
                       'invoice_items.line_total', 'invoices.order_id', 'invoices.invoice_no',
                       'invoices.customer_name', 'invoices.customer_phone', 'invoices.currency',
                       'invoices.source', 'invoices.issued_at', 'invoices.created_at'])
                ->map(fn ($r) => [
                    'name'     => (string) $r->name,
                    'qty'      => (float) $r->qty,
                    'unit'     => (float) $r->unit_price,
                    'total'    => (float) $r->line_total,
                    'order_id' => $r->order_id ? (int) $r->order_id : 0,
                    'ref'      => (string) $r->invoice_no,
                    'customer' => trim((string) ($r->customer_name ?? '')) ?: trim((string) ($r->customer_phone ?? '')),
                    'currency' => strtoupper((string) ($r->currency ?? '')),
                    'source'   => (string) ($r->source ?? ''),
                    'at'       => (string) ($r->issued_at ?: $r->created_at),
                ])->all();
        } catch (\Throwable $e) {
        }

        // Orders (items_json first, then order_items) for the uninvoiced remainder.
        $orders = [];
        $orderItems = [];
        try {
            $orders = DB::table('orders')->where('tenant_id', $tenantId)
                ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','canceled','rejected','void')")
                ->get(['id', 'order_no', 'items_json', 'customer_name', 'customer_phone', 'created_at', 'status'])
                ->map(fn ($o) => [
                    'id'       => (int) $o->id,
                    'ref'      => (string) ($o->order_no ?: ('#' . $o->id)),
                    'items'    => is_string($o->items_json) ? (json_decode($o->items_json, true) ?: []) : (is_array($o->items_json) ? $o->items_json : []),
                    'customer' => trim((string) ($o->customer_name ?? '')) ?: trim((string) ($o->customer_phone ?? '')),
                    'at'       => (string) $o->created_at,
                    'status'   => (string) ($o->status ?? ''),
                ])->all();
        } catch (\Throwable $e) {
        }
        try {
            $orderItems = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.tenant_id', $tenantId)
                ->whereRaw("lower(coalesce(orders.status,'')) not in ('cancelled','canceled','rejected','void')")
                ->where('order_items.product_id', $productId)
                ->get(['order_items.qty', 'order_items.order_id', 'orders.order_no',
                       'orders.customer_name', 'orders.customer_phone', 'orders.created_at', 'orders.status'])
                ->map(fn ($r) => [
                    'product_id' => $productId,
                    'qty'        => (float) $r->qty,
                    'order_id'   => (int) $r->order_id,
                    'ref'        => (string) ($r->order_no ?: ('#' . $r->order_id)),
                    'customer'   => trim((string) ($r->customer_name ?? '')) ?: trim((string) ($r->customer_phone ?? '')),
                    'at'         => (string) $r->created_at,
                    'status'     => (string) ($r->status ?? ''),
                ])->all();
        } catch (\Throwable $e) {
        }

        $reorder = Schema::hasColumn('products', 'reorder_point') && $p->reorder_point !== null
            ? (int) $p->reorder_point : null;

        $out = self::derive([
            'productId'    => $productId,
            'openings'     => $openings,
            'purchases'    => $purchases,
            'byName'       => $byName,
            'invoiceLines' => $invoiceLines,
            'orders'       => $orders,
            'orderItems'   => $orderItems,
        ]);

        $out['product'] = [
            'id'       => (int) $p->id,
            'name'     => (string) $p->name,
            'sku'      => (string) ($p->sku ?? ''),
            'category' => (string) ($p->category ?? ''),
            'unit'     => trim((string) ($p->unit_label ?? '')) ?: 'units',
            'reorder'  => $reorder,
        ];

        return $out;
    }

    /**
     * The tenant name→product-id map, built the way JubaStockCapability::soldMap() builds it:
     * a product answers to its own name and to the aliases in keywords (one-per-line when the field
     * has line breaks, else the comma parser, PLUS the whole field as one alias). A key claimed by
     * two different products is AMBIGUOUS and resolves to null — matched to neither, never guessed.
     *
     * @param  array<int,array{id:int,name:string,keywords:string}> $products
     * @return array<string,?int>
     */
    public static function byName(array $products): array
    {
        $byName = [];
        $claim = function (string $raw, int $pid) use (&$byName): void {
            $k = PurchaseNameMatch::key($raw);
            if ($k === '') {
                return;
            }
            $byName[$k] = (array_key_exists($k, $byName) && $byName[$k] !== $pid) ? null : $pid;
        };
        foreach ($products as $pr) {
            $pid = (int) $pr['id'];
            $claim((string) $pr['name'], $pid);
            $kw = (string) ($pr['keywords'] ?? '');
            if ($kw === '') {
                continue;
            }
            if (preg_match('/\R/u', $kw)) {
                foreach (preg_split('/\R/u', $kw) ?: [] as $lineAlias) {
                    $claim($lineAlias, $pid);
                }
            } else {
                foreach (PurchaseNameMatch::aliases($kw) as $alias) {
                    $claim($alias, $pid);
                }
                $claim($kw, $pid);
            }
        }

        return $byName;
    }

    /**
     * Pure derivation for one product. Applies the same rules as the list screen, kept at line
     * detail. See the class docblock for the arithmetic; each gate below names the screen rule it
     * mirrors.
     *
     * @param  array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function derive(array $in): array
    {
        $pid          = (int) $in['productId'];
        $openings     = (array) ($in['openings'] ?? []);
        $purchases    = (array) ($in['purchases'] ?? []);
        $byName       = (array) ($in['byName'] ?? []);
        $invoiceLines = (array) ($in['invoiceLines'] ?? []);
        $orders       = (array) ($in['orders'] ?? []);
        $orderItems   = (array) ($in['orderItems'] ?? []);

        // ---- opening: latest count of THIS product wins; keep the rest as re-count history --------
        $firstOpen = null;
        $mine = [];
        foreach ($openings as $o) {
            $at = (string) $o['at'];
            if ($firstOpen === null || $at < $firstOpen) {
                $firstOpen = $at;
            }
            if ((int) $o['product_id'] === $pid) {
                $mine[] = ['qty' => (float) $o['qty'], 'at' => $at, 'by' => (string) ($o['by'] ?? '')];
            }
        }
        usort($mine, fn ($a, $b) => [$a['at'], 0] <=> [$b['at'], 0]);
        $anchor  = $mine ? end($mine) : null;                 // last count = the anchor
        $history = $mine ? array_slice($mine, 0, count($mine) - 1) : [];
        $isNew   = $anchor === null;
        $openQty = $anchor ? (float) $anchor['qty'] : 0.0;
        $cut     = $anchor ? (string) $anchor['at'] : $firstOpen;   // the gate: everything at/before is inside the count

        // ---- purchases: strictly AFTER the cut is "bought since"; at/before is history -----------
        $bought = 0.0;
        $purchaseRows = [];
        foreach ($purchases as $r) {
            $q  = (float) $r['qty'];
            if ($q <= 0) {
                continue;
            }
            $at = (string) $r['at'];
            $since = ($cut !== null && $at > $cut);
            if ($since) {
                $bought += $q;
            }
            $purchaseRows[] = $r + ['since' => $since];
        }

        // ---- sales: invoice first (each order once), then items_json, then order_items -----------
        $sold = 0.0; $reserved = 0.0;
        $saleRows = [];
        $invoiced = [];   // order_id => true, so the order is not also counted from its own lines
        $held = function (string $status): bool {
            $k = PurchaseNameMatch::key(preg_replace('/[\s_-]+/u', ' ', $status));

            return $k !== '' && in_array($k, self::ON_SHELF_STATUSES, true);
        };

        // The raw per-product opening dates (latest count per product), for the date gate. This is
        // the screen's $since — used both for THIS product and, in the items_json pass, for OTHER
        // products, so an order's order_items is skipped on the same fromJson rule the screen uses:
        // an order is "handled by items_json" when a line of ANY product came off it since that
        // product's own count. Because an order's items_json line and its order_items row share the
        // order's created_at, this never double-counts and never drops this product's total.
        $sinceAll = [];
        foreach ($openings as $o) {
            $pidO = (int) $o['product_id']; $atO = (string) $o['at'];
            if (! isset($sinceAll[$pidO]) || $atO > $sinceAll[$pidO]) { $sinceAll[$pidO] = $atO; }
        }
        $countable = function (int $linePid, string $at) use ($sinceAll, $firstOpen): bool {
            if (isset($sinceAll[$linePid])) { return $at !== '' && $at > $sinceAll[$linePid]; }
            if ($firstOpen !== null) { return ! ($at !== '' && $at <= $firstOpen); }

            return true;
        };

        foreach ($invoiceLines as $r) {
            if ($r['order_id']) {
                $invoiced[(int) $r['order_id']] = true;   // mark BEFORE the product filter — matches the screen
            }
            $q = (float) $r['qty'];
            if ($q <= 0) {
                continue;
            }
            if (($byName[self::norm((string) $r['name'])] ?? null) !== $pid) {
                continue;                                  // this line is not this product (or ambiguous)
            }
            $at = (string) $r['at'];
            $since = ($cut === null) ? true : ($at > $cut);
            if ($since) {
                $sold += $q;
            }
            $saleRows[] = [
                'at' => $at, 'doc' => (string) $r['ref'], 'src' => 'invoice',
                'customer' => (string) ($r['customer'] ?? ''), 'qty' => $q,
                'unit' => (float) ($r['unit'] ?? 0), 'total' => (float) ($r['total'] ?? 0),
                'currency' => (string) ($r['currency'] ?? ''), 'source' => (string) ($r['source'] ?? ''),
                'since' => $since, 'reserved' => false,
            ];
        }

        $fromJson = [];
        foreach ($orders as $o) {
            $oid = (int) $o['id'];
            if (isset($invoiced[$oid])) {
                continue;                                  // already counted on its invoice
            }
            $isHeld = $held((string) $o['status']);
            $at = (string) $o['at'];
            foreach ((array) $o['items'] as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $q = (float) ($it['qty'] ?? 0);
                if ($q <= 0) {
                    continue;
                }
                $linePid = (int) ($it['product_id'] ?? ($it['id'] ?? 0));
                if ($linePid <= 0) {
                    $linePid = (int) (($byName[self::norm((string) ($it['name'] ?? ''))] ?? 0));
                }
                if ($linePid <= 0) {
                    continue;                              // unmatched free-text: no fromJson (screen records it as unmatched)
                }
                $since = $countable($linePid, $at);
                if ($since) {
                    // This order has produced a counted line (any product) — so its order_items are
                    // its duplicate and must be skipped, exactly as the screen's $fromJson does.
                    $fromJson[$oid] = true;
                }
                if ($linePid !== $pid) {
                    continue;                              // a different product; only accumulate/display the target here
                }
                if ($since) {
                    $sold += $q;
                    if ($isHeld) {
                        $reserved += $q;
                    }
                }
                $saleRows[] = [
                    'at' => $at, 'doc' => (string) $o['ref'], 'src' => 'order',
                    'customer' => (string) ($o['customer'] ?? ''), 'qty' => $q,
                    'unit' => (float) ($it['price'] ?? $it['unit_price'] ?? 0),
                    'total' => (float) ($it['line_total'] ?? (($it['price'] ?? 0) * $q)),
                    'currency' => '', 'source' => $isHeld ? 'reserved' : 'order',
                    'since' => $since, 'reserved' => $isHeld && $since,
                ];
            }
        }

        foreach ($orderItems as $r) {
            $oid = (int) $r['order_id'];
            if (isset($invoiced[$oid]) || isset($fromJson[$oid])) {
                continue;
            }
            if ((int) $r['product_id'] !== $pid) {
                continue;
            }
            $q = (float) $r['qty'];
            if ($q <= 0) {
                continue;
            }
            $isHeld = $held((string) $r['status']);
            $at = (string) $r['at'];
            $since = ($cut === null) ? true : ($at > $cut);
            if ($since) {
                $sold += $q;
                if ($isHeld) {
                    $reserved += $q;
                }
            }
            $saleRows[] = [
                'at' => $at, 'doc' => (string) $r['ref'], 'src' => 'order',
                'customer' => (string) ($r['customer'] ?? ''), 'qty' => $q,
                'unit' => 0.0, 'total' => 0.0, 'currency' => '',
                'source' => $isHeld ? 'reserved' : 'order',
                'since' => $since, 'reserved' => $isHeld && $since,
            ];
        }

        $current = round($openQty + $bought - $sold, 3);
        $onShelf = round($current + $reserved, 3);

        // ---- chronological ledger from the anchor forward, running balance ends at current -------
        usort($purchaseRows, fn ($a, $b) => (string) $a['at'] <=> (string) $b['at']);
        usort($saleRows, fn ($a, $b) => (string) $a['at'] <=> (string) $b['at']);
        $events = [];
        if (! $isNew) {
            $events[] = ['at' => (string) $anchor['at'], 'type' => 'count', 'doc' => 'Stock count',
                'party' => (string) $anchor['by'], 'in' => $openQty, 'out' => 0.0, 'anchor' => true];
        }
        foreach ($purchaseRows as $r) {
            if (! ($r['since'] ?? false)) {
                continue;                                  // pre-count purchases are inside the opening
            }
            $events[] = ['at' => (string) $r['at'], 'type' => 'purchase', 'doc' => (string) ($r['ref'] ?: 'Purchase'),
                'party' => (string) ($r['supplier'] ?? ''), 'in' => (float) $r['qty'], 'out' => 0.0,
                'user' => (string) ($r['user'] ?? '')];
        }
        foreach ($saleRows as $r) {
            if (! ($r['since'] ?? false)) {
                continue;
            }
            $events[] = ['at' => (string) $r['at'], 'type' => ($r['reserved'] ?? false) ? 'reserved' : 'sale',
                'doc' => (string) $r['doc'], 'party' => (string) ($r['customer'] ?? ''),
                'in' => 0.0, 'out' => (float) $r['qty']];
        }
        usort($events, fn ($a, $b) => (string) $a['at'] <=> (string) $b['at']);
        $bal = 0.0;
        foreach ($events as &$e) {
            $bal = round($bal + (float) $e['in'] - (float) $e['out'], 3);
            $e['balance'] = $bal;
        }
        unset($e);

        return [
            'opening' => ['qty' => $openQty, 'at' => $anchor['at'] ?? $firstOpen, 'by' => (string) ($anchor['by'] ?? ''),
                'history' => $history, 'new' => $isNew],
            'summary' => ['opening' => round($openQty, 3), 'bought' => round($bought, 3), 'sold' => round($sold, 3),
                'current' => $current, 'reserved' => round($reserved, 3), 'onShelf' => $onShelf],
            'purchases' => $purchaseRows,
            'sales'     => $saleRows,
            'ledger'    => $events,
        ];
    }
}
