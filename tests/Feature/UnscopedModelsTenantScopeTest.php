<?php

namespace Tests\Feature;

use App\Models\PosShift;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * P0/WS-1a — PosShift now carries the BelongsToTenant global scope.
 *
 * PosShift (a POS till session — financial) is accessed only from the panel, where request
 * middleware has already set the tenant context, so adding the scope cannot starve a context-less
 * reader (unlike the tender-scan path, whose models + command fix live in their own PR). These
 * assert the scope at the QUERY level (compiled SQL + bindings), which is FK-free and deterministic:
 * every query is constrained to the active tenant, and a super-admin context bypasses it (as the
 * admin panel relies on). The full "tenant A can't read tenant B's rows" data proof belongs to the
 * multi-tenant staging run.
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
