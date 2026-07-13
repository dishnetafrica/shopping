<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\Capability;
use App\Capability\Contracts\CapabilityContext;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Category;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Supplier;

/**
 * Cheap per-entity counts for the sidebar badges (Customers 500, Products 2.0k, ...).
 * Read-only COUNT queries, tenant-scoped, resolved once per session when the shell loads its catalog.
 * Not a screen — it exposes counts() for the catalog endpoint to merge into the nav.
 */
final class NavCountsCapability implements Capability
{
    public function key(): string { return '_nav'; }

    public function permission(): string { return ''; }

    /** @return array<string,int> map of nav key => count */
    public function counts(CapabilityContext $ctx): array
    {
        $t = $ctx->tenantId();
        $c = fn ($q) => (int) $q->where('tenant_id', $t)->count();

        return array_filter([
            'customers'        => $c(CustomerProfile::query()),
            'salesOrder'       => $c(Order::query()),
            'quotations'       => $c(Quotation::query()),
            'invoice'          => $c(Invoice::query()),
            'payment'          => $c(CustomerPayment::query()),
            'product'          => $c(Product::query()),
            'categories'       => $c(Category::query()),
            'supplier'         => $c(Supplier::query()),
            'purchase.process' => $c(OrderCost::query()->where('stage', 'purchase')),
        ], fn ($n) => $n > 0);
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->meta(['capability' => '_nav', 'counts' => $this->counts($ctx)]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'no_command'];
    }
}
