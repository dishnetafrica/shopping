<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\Capability;
use App\Capability\Contracts\CapabilityContext;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Category;
use App\Models\Conversation;
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
        // Sidebar badges are cosmetic and re-fetched on every shell load — cache per tenant for a short
        // window so 9 COUNT() queries don't run on each catalog request. Staleness of ≤45s is harmless.
        try {
            return \Illuminate\Support\Facades\Cache::remember(
                'navcounts:' . $ctx->tenantId(), 45, fn () => $this->computeCounts($ctx)
            );
        } catch (\Throwable $e) {
            // Cache layer unavailable — compute directly; computeCounts is itself
            // fail-soft per key, so this can no longer re-throw and take the nav down.
            return $this->computeCounts($ctx);
        }
    }

    /** @return array<string,int> */
    private function computeCounts(CapabilityContext $ctx): array
    {
        $t = $ctx->tenantId();
        $c = fn ($q) => (int) $q->where('tenant_id', $t)->count();

        // PRODUCTION INCIDENT 2026-07-18: a single throwing count (comm_messages
        // table not yet migrated on the live server) emptied the ENTIRE v3 sidebar,
        // because one exception killed the whole counts map and the catalog request.
        // Every count is now isolated: if its table/model is missing, that ONE badge
        // is omitted and every other menu item renders normally.
        $safe = function (callable $f): ?int {
            try { return (int) $f(); } catch (\Throwable $e) { return null; }
        };

        $counts = [
            'customers'         => $safe(fn () => $c(CustomerProfile::query())),
            'chats'             => $safe(fn () => $c(Conversation::query()->where('unread', '>', 0))),
            'salesOrder'        => $safe(fn () => $c(Order::query())),
            'quotations'        => $safe(fn () => $c(Quotation::query()->whereRaw("lower(coalesce(status,'')) in ('sent','accepted','pending')")->whereNull('order_id'))),
            'invoice'           => $safe(fn () => $c(Invoice::query())),
            'payment'           => $safe(fn () => $c(CustomerPayment::query())),
            'product'           => $safe(fn () => $c(Product::query())),
            'categories'        => $safe(fn () => $c(Category::query())),
            'supplier'          => $safe(fn () => $c(Supplier::query())),
            'purchase.process'  => $safe(fn () => $c(OrderCost::query()->where('stage', 'purchase'))),
            // Requires the Email Center backend (comm_messages) — $safe omits it until migrated.
            'communication'     => $safe(fn () => (int) \App\Models\CommMessage::withoutGlobalScope('tenant')
                                        ->where('tenant_id', $t)->where('status', 'failed')->count()),
        ];

        return array_filter($counts, static fn ($v) => $v !== null);
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
