<?php

namespace Tests\Feature;

use App\Models\AiCampaignDraft;
use App\Models\Tender;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * P0/WS-1b — Tender and AiCampaignDraft gain the BelongsToTenant global scope. Their panel access
 * already filters tenant_id; the two cross-tenant scheduled sweeps (TendersRemindCommand,
 * AiCampaignSendCommand) opt out via withoutGlobalScopes() because they set no tenant context.
 * These assert both halves at the query level (FK-free, deterministic).
 */
class TenderAiCampaignScopeTest extends TestCase
{
    public function test_tender_query_is_scoped_to_active_tenant(): void
    {
        app(TenantContext::class)->set(42);
        $q = Tender::query();
        $this->assertStringContainsString('tenant_id', $q->toSql());
        $this->assertContains(42, $q->getBindings());
        app(TenantContext::class)->clear();
    }

    public function test_aicampaigndraft_query_is_scoped_to_active_tenant(): void
    {
        app(TenantContext::class)->set(7);
        $q = AiCampaignDraft::query();
        $this->assertStringContainsString('tenant_id', $q->toSql());
        $this->assertContains(7, $q->getBindings());
        app(TenantContext::class)->clear();
    }

    public function test_console_sweeps_opt_out_of_the_scope(): void
    {
        // Even with a tenant bound, withoutGlobalScopes() (the console-sweep pattern) must NOT
        // constrain by tenant_id — otherwise the cross-tenant reminder/sender reads nothing.
        app(TenantContext::class)->set(99);
        $this->assertStringNotContainsString('tenant_id', Tender::withoutGlobalScopes()->toSql());
        $this->assertStringNotContainsString('tenant_id', AiCampaignDraft::withoutGlobalScopes()->toSql());
        app(TenantContext::class)->clear();
    }

    public function test_super_admin_bypasses_the_scope(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(42);
        $ctx->asSuperAdmin(true);
        $this->assertStringNotContainsString('tenant_id', Tender::query()->toSql());
        $ctx->asSuperAdmin(false);
        $ctx->clear();
    }
}
