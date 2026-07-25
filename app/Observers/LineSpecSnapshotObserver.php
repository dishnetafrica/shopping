<?php declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Services\Catalog\ProductSpecService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Asset Lifecycle · S1c-3 — snapshot a document line's product specifications when the line is created.
 *
 * One central choke-point for BOTH invoice_items and quotation_items, so no document-creation path is
 * edited. On creating, it resolves the line's product (by SKU within the tenant, else by exact name)
 * and freezes a compact spec summary onto specs_summary. Fully guarded: a failure can never block the
 * document. The snapshot is deliberately frozen — a document keeps the specs as they were when issued.
 */
class LineSpecSnapshotObserver
{
    public function creating(Model $item): void
    {
        try {
            if (! empty($item->specs_summary)) {
                return; // caller supplied one explicitly — respect it
            }
            $tenantId = (int) ($item->tenant_id ?: app(TenantContext::class)->id());
            if ($tenantId <= 0) {
                return;
            }
            $sku  = trim((string) ($item->sku ?? ''));
            $name = trim((string) ($item->name ?? ''));

            $base = Product::withoutGlobalScopes()->where('tenant_id', $tenantId);
            $product = null;
            if ($sku !== '') {
                $product = (clone $base)->where('sku', $sku)->first();
            }
            if (! $product && $name !== '') {
                $product = (clone $base)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            }
            if (! $product) {
                return;
            }

            $summary = ProductSpecService::summaryForProduct($tenantId, (int) $product->id);
            if ($summary !== '') {
                $item->specs_summary = $summary;
            }
        } catch (\Throwable $e) {
            // Never block document creation because of a spec snapshot.
            \Log::warning('spec snapshot failed: ' . $e->getMessage());
        }
    }
}
