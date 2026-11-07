<?php

namespace Tests\Feature;

use App\Models\PosShift;
use App\Models\TenderItem;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * P0/WS-1a — PosShift and TenderItem now carry the BelongsToTenant global scope.
 *
 * These assert the scope at the QUERY level (compiled SQL + bindings), which is FK-free and
 * deterministic: every query is constrained to the active tenant, and a super-admin context
 * bypasses it (as the admin panel relies on). The full "tenant A can't read tenant B's rows"
 * data proof belongs to the multi-tenant staging run.
 */
class UnscopedModelsTenantScopeTest extends TestCase
{
    public function test_posshift_query_is_scoped_to_the_active_tenant(): void
    {
        app(TenantContext::class)->set(42);
        $q = PosShift::query();
        $this->assertStringContainsString('tenant_id', $q->toSql(), 'PosShift query must filter tenant_id');
        $this->assertContains(42, $q->getBindings(), 'PosShift query must bind the active tenant id');
        app(TenantContext::class)->clear();
    }

    public function test_tenderitem_query_is_scoped_to_the_active_tenant(): void
    {
        app(TenantContext::class)->set(7);
        $q = TenderItem::query();
        $this->assertStringContainsString('tenant_id', $q->toSql(), 'TenderItem query must filter tenant_id');
        $this->assertContains(7, $q->getBindings(), 'TenderItem query must bind the active tenant id');
        app(TenantContext::class)->clear();
    }

    public function test_super_admin_context_bypasses_the_scope(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(42);
        $ctx->asSuperAdmin(true);
        $this->assertStringNotContainsString('tenant_id', PosShift::query()->toSql(), 'super-admin must see all tenants (admin panel)');
        $ctx->asSuperAdmin(false);
        $ctx->clear();
    }
}
