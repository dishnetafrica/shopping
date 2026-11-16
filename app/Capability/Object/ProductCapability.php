<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Process\ProductListCapability;
use App\Capability\Shared\RecommendationCapability;
use App\Capability\Support\Money;
use App\Models\Product;
use App\Models\Warranty;
use App\Services\Catalog\KitService;
use App\Services\Catalog\ProductSpecService;
use App\Services\Catalog\ProductWriteService;
use App\Services\Inventory\CountRecorder;
use App\Services\Inventory\SerialService;
use App\Services\Inventory\StockLedger;

final class ProductCapability implements ObjectCapability
{
    public function __construct(private RecommendationCapability $reco) {}

    public function key(): string { return 'product'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'product'])->meta(['capability' => 'product']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $p = Product::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $price = (float) $p->price;
        $cost = (float) $p->cost_price;
        $margin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;
        $stock = (float) $p->stock;
        $low = $stock <= max(5.0, (float) ($p->moq ?: 0));
        $status = $stock <= 0 ? 'out' : ($low ? 'low' : 'in');
        $cur = $ctx->baseCurrency();

        // Margin that reads honestly when cost is missing or clearly wrong (data entry error).
        if ($cost <= 0) {
            $marginFin = new Financial('margin', 'Margin', '—');
        } elseif (abs($margin) > 500) {
            $marginFin = new Financial('margin', 'Margin', '⚠ check cost', null, 'danger');
        } else {
            $marginFin = new Financial('margin', 'Margin', $margin, null, $margin < 0 ? 'danger' : ($margin < 15 ? 'warn' : 'ok'), 'pct');
        }

        $photos = array_values(array_filter([(string) $p->image_url, (string) $p->gallery_1, (string) $p->gallery_2, (string) $p->gallery_3], fn ($u) => trim((string) $u) !== ''));

        $edit = ['key' => 'edit_product', 'label' => 'Edit', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/update_product', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ProductListCapability::formSchema($ctx->tenantId()),
            // v4.54 — spelled out rather than labelled "Hidden", because the two things staff get
            // wrong here are thinking it deletes the product and thinking it stops them selling it.
            'labels' => [
                'hidden' => 'Hide from customers (stays in stock and reports; still sellable on WhatsApp)',
                'reorder_point' => 'Reorder point — alert on Juba Stock when free-to-sell falls to this level (blank = no alert)',
                'price' => 'Box / carton price (wholesale — for the whole pack)',
                'piece_price' => 'Per-piece price (retail — for one piece; blank = auto from pack size × markup)',
            ],
            'prefill' => ['name' => (string) $p->name, 'category' => (string) $p->category, 'price' => round($price, 2), 'piece_price' => $p->piece_price !== null && (float) $p->piece_price > 0 ? round((float) $p->piece_price, 2) : '', 'cost' => $p->cost_price === null ? '' : round($cost, 2), 'stock' => (int) $stock, 'moq' => $p->moq !== null ? (int) $p->moq : '', 'pack_size' => $p->pack_size !== null ? (int) $p->pack_size : '', 'reorder_point' => $p->reorder_point !== null ? (int) $p->reorder_point : '', 'unit_label' => (string) ($p->unit_label ?? ''), 'hidden' => ($p->hidden ?? false) ? 'yes' : 'no', 'photos' => $photos,
                'billing' => (string) ($p->billing ?? ''), 'service_line' => (string) ($p->service_line ?? ''), 'min_term_months' => $p->min_term_months ?? '']];
        $archive = ['key' => 'archive_product', 'label' => 'Archive', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/archive_product', 'actor' => 'human', 'tone' => 'danger', 'confirm' => 'Archive "' . $p->name . '"? It will be hidden from the catalogue but kept for order history.'];

        // Record a physical count from the product page — the fastest way to give a fast-mover the
        // trusted opening the Juba Stock reorder alert needs. Writes exactly where the stock:count CLI
        // writes (ledger trust_count + stock_openings anchor + products.stock cache) via CountRecorder,
        // so a count typed here and one typed on the command line are the same fact.
        $homeLoc = StockLedger::home($ctx->tenant);
        $recordCount = ['key' => 'record_count', 'label' => 'Record count', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/product/' . $p->id . '/record_count', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['counted' => 'number', 'location' => 'combo:Kampala|Juba', 'note' => 'text'],
            'labels' => [
                'counted'  => 'Counted quantity — what is physically on the shelf right now',
                'location' => 'Where the stock is',
                'note'     => 'Note (optional)',
            ],
            // counted left blank on purpose: a count you type fresh is worth more than one nudged
            // toward the number already on the screen.
            'prefill' => ['counted' => '', 'location' => $homeLoc === StockLedger::LOC_JUBA ? 'Juba' : 'Kampala', 'note' => '']];

        // Asset Lifecycle · Product Specifications (S1b) — show this product's specs on the detail page
        // and offer inline editing. Rendered via the generic 'answers' section, so the V3 front-end
        // needs no change. When the tenant has no spec catalogue, both are empty → the page is unchanged.
        $specSvc     = ProductSpecService::forTenant($ctx->tenant);
        $specRows    = $specSvc->specsFor((int) $p->id);
        $specAnswers = array_map(fn ($s) => [
            'q'     => $s['label'] . (! empty($s['unit']) ? ' (' . $s['unit'] . ')' : ''),
            'value' => ($s['value'] === null || $s['value'] === '') ? '—' : (string) $s['value'],
            'sub'   => $s['group'] ?? null,
        ], $specRows);
        $specSchema = self::specFormSchema($specSvc);
        $specPrefill = [];
        foreach ($specRows as $s) { $specPrefill[$s['key']] = $s['value']; }
        $editSpecs = $specSchema === [] ? null : [
            'key' => 'set_specs', 'label' => 'Edit specifications', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/product/' . $p->id . '/set_specs',
            'actor' => 'human', 'tone' => 'default', 'schema' => $specSchema, 'prefill' => $specPrefill,
        ];

        // Asset Lifecycle · Serialized Inventory (S2c) — show this product's units by status (via the
        // generic 'stats' tiles, no front-end change) and offer inline serial registration. Empty for
        // non-serialized products, so their page is unchanged.
        $serialStats = [];
        if ((bool) $p->serialized) {
            $counts = SerialService::forTenant($ctx->tenant)->counts((int) $p->id);
            $total  = array_sum($counts);
            if ($total > 0) {
                $serialStats[] = ['key' => 'u_total', 'label' => 'Tracked units', 'value' => (string) $total, 'icon' => 'products', 'tone' => 'ok'];
                foreach (['in_stock' => 'In stock', 'reserved' => 'Reserved', 'sold' => 'Sold', 'installed' => 'Installed', 'returned' => 'Returned', 'replaced' => 'Replaced', 'scrapped' => 'Scrapped'] as $k => $lbl) {
                    if (! empty($counts[$k])) {
                        $serialStats[] = ['key' => 'u_' . $k, 'label' => $lbl, 'value' => (string) $counts[$k], 'icon' => 'products',
                            'tone' => $k === 'in_stock' ? 'ok' : (in_array($k, ['returned', 'scrapped'], true) ? 'warn' : 'default')];
                    }
                }
            }
        }
        $addSerials = [
            'key' => 'add_serials', 'label' => 'Add serials', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/product/' . $p->id . '/add_serials',
            'actor' => 'human', 'tone' => 'default', 'schema' => ['serials' => 'text'],
            'labels' => ['serials' => 'Serial numbers — separate with commas or new lines'], 'prefill' => [],
        ];

        // Asset Lifecycle · Warranty (S3b) — a product's DEFAULT warranty policy (manufacturer + company
        // months) shown on the detail page and editable inline, plus a live count of active warranties on
        // this product. All rendered via the generic 'answers'/'stats'/'actions' sections (no front-end
        // change). Kept off ordinary consumer goods: the terms and the editor only appear for serialized
        // products or products that already carry a policy — so a grocery item's page is unchanged.
        $mfrM  = $p->warranty_manufacturer_months;
        $compM = $p->warranty_company_months;
        $warrantyRelevant = (bool) $p->serialized || $mfrM !== null || $compM !== null;

        $warrantyAnswers = [];
        if ($warrantyRelevant) {
            $warrantyAnswers[] = ['q' => 'Manufacturer warranty', 'value' => $mfrM ? $mfrM . ' months' : '—', 'sub' => 'Warranty'];
            $warrantyAnswers[] = ['q' => 'Company warranty', 'value' => $compM ? $compM . ' months' : '—', 'sub' => 'Warranty'];
        }

        $warrantyStats = [];
        $activeWarranties = Warranty::where('tenant_id', $ctx->tenantId())
            ->where('product_id', (int) $p->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('warranty_end')->orWhere('warranty_end', '>=', now()->toDateString());
            })
            ->count();
        if ($activeWarranties > 0) {
            $warrantyStats[] = ['key' => 'w_active', 'label' => 'Active warranties', 'value' => (string) $activeWarranties, 'icon' => 'shield', 'tone' => 'ok'];
        }

        $editWarranty = null;
        if ($warrantyRelevant) {
            $editWarranty = [
                'key' => 'set_warranty', 'label' => 'Set warranty terms', 'method' => 'POST',
                'endpoint' => '/api/v1/workspace/product/' . $p->id . '/set_warranty',
                'actor' => 'human', 'tone' => 'default',
                'schema' => ['warranty_manufacturer_months' => 'number', 'warranty_company_months' => 'number'],
                'labels' => [
                    'warranty_manufacturer_months' => 'Manufacturer warranty (months)',
                    'warranty_company_months'      => 'Company warranty (months)',
                ],
                'prefill' => [
                    'warranty_manufacturer_months' => $mfrM ?? '',
                    'warranty_company_months'      => $compM ?? '',
                ],
            ];
        }

        // Asset Lifecycle · Kits (S4a) — a bundle product lists its parts on the detail page (read-only:
        // kits are built by CSV import). Rendered via the generic 'answers'/'stats' sections, so the
        // front-end needs no change. Empty for ordinary products, so their page is unchanged.
        $kitAnswers = [];
        $kitStats   = [];
        if ((bool) $p->is_kit) {
            $comps = KitService::forTenant($ctx->tenant)->componentsFor((int) $p->id);
            foreach ($comps as $kc) {
                $cp  = $kc->component;
                $qty = (float) $kc->quantity;
                $qtyLabel = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');   // 8 not 8.000, 1.5 stays 1.5
                $kitAnswers[] = [
                    'q'     => (string) ($cp->name ?? ('#' . $kc->component_product_id)),
                    'value' => '×' . $qtyLabel,
                    'sub'   => 'Kit contents',
                ];
            }
            if ($comps->count() > 0) {
                $kitStats[] = ['key' => 'kit_parts', 'label' => 'Bundle of', 'value' => $comps->count() . ' parts', 'icon' => 'products', 'tone' => 'ok'];
            }
        }

        $fins = [
            new Financial('price', 'Price', round($price, 2), $cur),
            new Financial('cost', 'Cost', $p->cost_price === null ? '—' : round($cost, 2), $p->cost_price === null ? null : $cur),
            $marginFin,
        ];
        if ($p->moq !== null) {
            $fins[] = new Financial('moq', 'Min. order (MOQ)', (int) $p->moq . ' ' . (string) ($p->unit_label ?: 'units'));
        }
        if ($p->pack_size !== null) {
            $fins[] = new Financial('pack', 'Pack size', (int) $p->pack_size);
        }
        // Per-piece (retail) price — mirrors what the storefront shows, so the owner sees the single-piece
        // price right here. Uses the explicit piece_price when set, else the derived carton÷pack×markup.
        if ((bool) $ctx->tenant->setting('loose_enabled', false) && $p->pack_size !== null && (int) $p->pack_size > 1 && (float) $price > 0) {
            $pct = (float) $ctx->tenant->setting('loose_markup_pct', 20);
            $pdec = \App\Services\Pricing::decimalsForCurrency((string) $cur);
            $override = $p->piece_price !== null && (float) $p->piece_price > 0 ? (float) $p->piece_price : null;
            $pp = \App\Services\Pricing::piecePrice($override, (float) $price, (int) $p->pack_size, $pct, $pdec);
            $fins[] = [
                'key' => 'piece', 'label' => 'Per piece' . ($override !== null ? ' (set)' : ' (auto)'), 'value' => $pp, 'currency' => (string) $cur, 'tone' => 'ok',
                'foot' => $override !== null
                    ? 'retail — set for this product'
                    : ('retail — auto (carton ÷ ' . (int) $p->pack_size . ' + ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%)'),
            ];
        }

        // v4.64 — where this stock came from. `order_costs` has carried `product_id`, `qty` and
        // `unit_cost` on every Buy stock line since that form was built, and the product record has
        // never shown any of it — so "170 on hand" stood on the page with nothing behind it and no
        // way to ask where it came from or what it cost.
        $buyRows = []; $lastBuy = null; $boughtAll = 0.0;
        try {
            $unit = trim((string) ($p->unit_label ?: '')) ?: 'units';
            foreach (\App\Models\OrderCost::withoutGlobalScopes()
                ->where('tenant_id', $ctx->tenantId())->where('product_id', $p->id)
                ->where('stage', 'purchase')->whereNull('voided_at')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(25)->get() as $oc) {
                $q = (float) ($oc->qty ?? 0);
                if ($q <= 0) { continue; }
                $boughtAll += $q;
                $lastBuy ??= $oc;
                $buyRows[] = ['cells' => [
                    $oc->created_at ? $oc->created_at->format('d M Y') : '—',
                    (string) ($oc->supplier_name ?: '—'),
                    ['value' => \App\Services\Inventory\PurchaseStock::number($q) . ' ' . $unit, 'align' => 'right'],
                    ['value' => \App\Services\Inventory\PurchaseStock::number((float) $oc->unit_cost)
                                . ' ' . strtoupper((string) $oc->currency), 'align' => 'right'],
                ], 'link' => $oc->supplier_id ? '/api/v1/workspace/supplier/' . (int) $oc->supplier_id : null];
            }
        } catch (\Throwable $e) {
        }

        if ($lastBuy) {
            $fins[] = new Financial('last_buy', 'Last bought',
                \App\Services\Inventory\PurchaseStock::number((float) $lastBuy->qty) . ' ' . ($p->unit_label ?: 'units')
                . ' · ' . ($lastBuy->created_at ? $lastBuy->created_at->format('d M') : ''));
        }

        $buyCard = ['kind' => 'table', 'title' => 'Purchase history',
            'sub' => $buyRows
                ? \App\Services\Inventory\PurchaseStock::number($boughtAll) . ' ' . ($p->unit_label ?: 'units') . ' bought, all time'
                : 'nothing recorded against this product yet',
            'columns' => [['label' => 'Date'], ['label' => 'Supplier'], ['label' => 'Qty', 'align' => 'right'], ['label' => 'Unit cost', 'align' => 'right']],
            'rows' => $buyRows,
            'empty' => 'No purchases linked to this product. Use Buy stock on a supplier to link one.',
            'pageSize' => 10];

        return (new WorkspacePayload)
            ->object([
                'type' => 'product', 'id' => (string) $p->id, 'ref' => (string) $p->sku, 'title' => (string) $p->name,
                'subtitle' => trim((string) $p->sku . ' · ' . (string) $p->category), 'status' => $status,
                'image' => (string) ($p->image_url ?? ''), 'gallery' => $photos,
            ])
            ->headline(number_format($stock) . ' ' . (string) ($p->unit_label ?: 'units'), 'on hand', $status === 'out' ? 'danger' : ($status === 'low' ? 'warn' : 'ok'))
            ->financials($fins)
            ->overviewCards([$buyCard])
            ->related($p->category ? $this->reco->topInCategory($ctx, (string) $p->category, 6, (int) $p->id) : [])
            ->stats(array_merge($serialStats, $warrantyStats, $kitStats))
            ->answers(array_merge($specAnswers, $warrantyAnswers, $kitAnswers))
            ->actions(array_values(array_filter([$edit, $recordCount, $archive, $editSpecs, $addSerials, $editWarranty])))
            ->meta(['capability' => 'product', 'ttl' => 60, 'permissions' => ['menu:products']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $p = Product::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $p) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        return match ($action) {
            'update_product' => $this->doUpdate($ctx, $p, $input),
            'archive_product' => $this->doArchive($ctx, $p),
            'record_count' => $this->doRecordCount($ctx, $p, $input),
            'set_specs' => $this->doSetSpecs($ctx, $p, $input),
            'add_serials' => $this->doAddSerials($ctx, $p, $input),
            'set_warranty' => $this->doSetWarranty($ctx, $p, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Split a free-text serials field (commas / new lines / spaces) into a clean, unique list. */
    private static function parseSerials(string $s): array
    {
        $parts = preg_split('/[\s,;]+/', trim($s)) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($x) => $x !== '')));
    }

    /** Coerce a months field to a positive int, or null (blank / zero / non-numeric → no policy). */
    private static function parseMonths($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    /**
     * Set this product's DEFAULT warranty policy (S3b). A plain product-attribute update — the terms a
     * new warranty inherits when a unit is sold/installed (auto-activation lands in S3c). Owner/manager
     * gating is enforced upstream by the workspace command route.
     */
    private function doSetWarranty(CapabilityContext $ctx, Product $p, array $input): array
    {
        $p->warranty_manufacturer_months = self::parseMonths($input['warranty_manufacturer_months'] ?? null);
        $p->warranty_company_months      = self::parseMonths($input['warranty_company_months'] ?? null);
        $p->save();

        return ['ok' => true, 'message' => 'Warranty terms updated.'];
    }

    private function doAddSerials(CapabilityContext $ctx, Product $p, array $input): array
    {
        $serials = self::parseSerials((string) ($input['serials'] ?? ''));
        if ($serials === []) {
            return ['ok' => false, 'error' => 'No serial numbers provided.'];
        }
        $units = SerialService::forTenant($ctx->tenant)->bulkRegister((int) $p->id, $serials);

        return ['ok' => true, 'message' => count($units) . ' serial(s) registered.'];
    }

    /** Build a spec-edit form schema (key => field type) from the tenant's active spec catalogue. */
    private static function specFormSchema(ProductSpecService $svc): array
    {
        $schema = [];
        foreach ($svc->definitions(true) as $def) {
            $schema[$def->key] = match ($def->data_type) {
                'number'  => 'number',
                'enum'    => 'combo:' . implode('|', array_values((array) ($def->options ?? []))),
                'boolean' => 'combo:Yes|No',
                default   => 'text',
            };
        }
        return $schema;
    }

    private function doSetSpecs(CapabilityContext $ctx, Product $p, array $input): array
    {
        $svc = ProductSpecService::forTenant($ctx->tenant);
        $pairs = [];
        foreach ($svc->definitions(true) as $def) {
            if (array_key_exists($def->key, $input)) {
                $pairs[$def->key] = $input[$def->key];
            }
        }
        $svc->setProductSpecs((int) $p->id, $pairs);

        return ['ok' => true, 'message' => 'Specifications updated.'];
    }

    private function doUpdate(CapabilityContext $ctx, Product $p, array $input): array
    {
        app(ProductWriteService::class)->update($ctx->tenant, $p, $input);

        return ['ok' => true, 'message' => 'Product updated.'];
    }

    private function doArchive(CapabilityContext $ctx, Product $p): array
    {
        app(ProductWriteService::class)->archive($ctx->tenant, $p);

        return ['ok' => true, 'message' => 'Product archived.'];
    }

    /** Format a quantity as 8, not 8.000, and keep 1.5 as 1.5. */
    private static function qtyText(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Record a physical count for this one product (menu:products gated upstream by the command route).
     * recount is always on: a panel user retyping a number expects the correction to take, not to be
     * refused because the item was already counted earlier today — the newest count simply wins.
     */
    private function doRecordCount(CapabilityContext $ctx, Product $p, array $input): array
    {
        $raw = $input['counted'] ?? '';
        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            return ['ok' => false, 'error' => 'Enter the counted quantity — 0 or more.'];
        }

        $loc = str_contains(strtolower(trim((string) ($input['location'] ?? ''))), 'juba')
            ? StockLedger::LOC_JUBA : StockLedger::LOC_KAMPALA;
        $by   = trim((string) ($ctx->user->name ?? '')) ?: 'Panel count';
        $note = trim((string) ($input['note'] ?? '')) ?: null;

        $res = CountRecorder::record($ctx->tenant, $p, (float) $raw, $loc, $by, null, true, $note);

        $where = $loc === StockLedger::LOC_JUBA ? 'Juba' : 'Kampala';
        $moved = (float) $res['moved'];
        $msg = 'Counted ' . self::qtyText((float) $res['counted']) . ' in ' . $where
            . ($moved !== 0.0
                ? ' — book adjusted by ' . ($moved > 0 ? '+' : '') . self::qtyText($moved)
                : ' — matches the book');

        return ['ok' => true, 'message' => $msg];
    }
}
