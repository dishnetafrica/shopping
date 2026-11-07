<?php

namespace Tests\Feature;

use App\Models\AiCampaignDraft;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * P0/WS-1b — Tender, TenderItem and AiCampaignDraft gain the BelongsToTenant global scope.
 *
 * TenderItem travels with Tender here (not in WS-1a) because the scheduled tender scan reads both
 * in one loop iteration under one context, so their scope and the TendersScanCommand per-tenant
 * context fix are one atomic change (see TenderScanContextTest for the data-level proof).
 *
 * Panel access to these models already filters tenant_id. The scheduled commands are PLATFORM
 * operations: the tender scan establishes context per tenant (TendersScanCommand); the two
 * cross-tenant sweeps (TendersRemindCommand's deadline sweep, AiCampaignSendCommand) read their
 * index via withoutGlobalScopes() with an explicit tenant filter. These assert the scope at the
 * query level (FK-free, deterministic); TenderScanContextTest asserts the data-level behaviour.
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

    public function test_tenderitem_query_is_scoped_to_active_tenant(): void
    {
        app(TenantContext::class)->set(13);
        $q = TenderItem::query();
        $this->assertStringContainsString('tenant_id', $q->toSql());
        $this->assertContains(13, $q->getBindings());
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
