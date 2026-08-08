<?php declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Services\Inventory\StockLedger;

/**
 * Roadmap E6.4 (prerequisite) — mirror Kampala catalog stock changes into the ONE stock ledger so
 * the projection stays correct once on-hand reads switch to it (behind stock_v2).
 *
 * A new product's initial stock is an opening IN; a later change to `stock` is a signed ADJUST for
 * the delta. Gated on the stock_ledger flag (default off) and fully best-effort — a ledger hiccup can
 * never break a product save. Note: raw bulk updates (DB::table(...)->update) bypass Eloquent events,
 * so the CSV stock-sync path emits its own movements explicitly (see PanelApiController::stockSync).
 */
class ProductStockObserver
{
    public function created(Product $p): void
    {
        if (! $this->enabled($p)) return;
        $stock = (float) ($p->stock ?? 0);
        if (abs($stock) < 0.0001) return;
        try {
            StockLedger::forTenant($p->tenant)->receive([
                'product_id' => $p->id, 'sku' => $p->sku, 'location' => StockLedger::home($p->tenant),
                'qty' => $stock, 'ref_type' => 'product_create', 'ref_id' => $p->id,
                'note' => 'Opening stock: ' . $p->name,
            ]);
        } catch (\Throwable $e) { /* never break a product create */ }
    }

    public function updated(Product $p): void
    {
        if (! $this->enabled($p)) return;
        if (! $p->wasChanged('stock')) return;
        $old = (float) ($p->getOriginal('stock') ?? 0);
        $new = (float) ($p->stock ?? 0);
        $delta = round($new - $old, 3);
        if (abs($delta) < 0.0001) return;
        try {
            StockLedger::forTenant($p->tenant)->adjust([
                'product_id' => $p->id, 'sku' => $p->sku, 'location' => StockLedger::home($p->tenant),
                'qty' => $delta, 'ref_type' => 'product_edit', 'ref_id' => $p->id,
                'note' => 'Stock adjusted: ' . $p->name,
            ]);
        } catch (\Throwable $e) { /* never break a product edit */ }
    }

    /**
     * Only mirror when this tenant has opted into the stock ledger. The heavy catalogue-import path
     * uses Product::insert() (bulk, no model events), so this observer only fires on individual
     * create/save — one tenant lookup there is negligible, and no per-row caching is needed.
     */
    private function enabled(Product $p): bool
    {
        try {
            return $p->tenant !== null && (bool) $p->tenant->setting('stock_ledger', false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
