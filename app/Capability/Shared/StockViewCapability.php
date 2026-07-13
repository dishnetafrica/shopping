<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Models\Product;
use Illuminate\Support\Collection;

/** Low-stock / reorder view over the catalog stock cache (kept in sync by the frozen StockLedger). */
final class StockViewCapability
{
    public function lowStock(CapabilityContext $ctx, int $limit = 100): Collection
    {
        return Product::where('tenant_id', $ctx->tenantId())
            ->whereNull('archived_at')
            ->where('stock', '<=', 20)
            ->orderBy('stock')->limit($limit)->get()
            ->filter(fn ($p) => (float) $p->stock <= max(5.0, (float) ($p->moq ?: 0)))
            ->values();
    }

    public function reorderCount(CapabilityContext $ctx): int
    {
        return $this->lowStock($ctx, 500)->count();
    }
}
