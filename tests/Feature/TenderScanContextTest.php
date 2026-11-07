<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\Tenant;
use App\Services\Tender\TenderItems;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * P0/WS-1b (tender half) — the scheduled tender scan is a PLATFORM operation, and TendersScanCommand
 * now establishes TenantContext per tenant (set at the top of the loop, clear() in a finally).
 *
 * These are DB-backed (real Eloquent + the real TenderItems service), because the regression the
 * review caught is a data-level one that query-SQL assertions cannot see: with Tender/TenderItem
 * under the BelongsToTenant global scope, a context-less read collapses to `tenant_id IS NULL`, so
 * TenderItems::sync() reports 0 rows even though it just inserted them, dedup always misses, and
 * sourcing is never assigned. The fix is to establish context; these prove the fixed behaviour and
 * reproduce the broken one.
 */
class TenderScanContextTest extends TestCase
{
    use RefreshDatabase;

    private function reportWithTwoItems(): array
    {
        return ['products_required' => [
            ['item' => 'Cement', 'qty' => '40 bags'],
            ['item' => 'Sand', 'qty' => '10 tons'],
        ]];
    }

    public function test_sync_reports_the_real_count_and_is_idempotent_with_context(): void
    {
        $a = Tenant::factory()->create();
        $ctx = app(TenantContext::class);
        $ctx->set($a->id);

        $tender = Tender::create([
            'tenant_id' => $a->id, 'source' => 'forum', 'source_ref' => 'CTX-1',
            'title' => 'Supply of materials', 'status' => 'new', 'report' => $this->reportWithTwoItems(),
        ]);

        $svc = app(TenderItems::class);

        // This is what the scan does inside its per-tenant context: sync() must see the rows it wrote.
        $this->assertSame(2, $svc->sync($a, $tender), 'sync must report the real row count under context');
        $this->assertSame(2, TenderItem::where('tender_id', $tender->id)->count());

        // Re-analysing the same tender must not duplicate lines (the idempotency the board relies on).
        $this->assertSame(2, $svc->sync($a, $tender), 're-sync count stays 2');
        $this->assertSame(2, TenderItem::where('tender_id', $tender->id)->count(), 're-sync must not duplicate rows');

        $ctx->clear();
    }

    public function test_sync_without_context_reports_zero_reproducing_the_regression(): void
    {
        $a = Tenant::factory()->create();
        app(TenantContext::class)->clear();   // a console run with no context established

        // Created with an explicit tenant_id, so the row persists regardless of context...
        $tender = Tender::create([
            'tenant_id' => $a->id, 'source' => 'forum', 'source_ref' => 'CTX-2',
            'title' => 'No-context tender', 'status' => 'new', 'report' => $this->reportWithTwoItems(),
        ]);

        // ...but sync()'s count read is tenant-scoped, so with no context it collapses to
        // `tenant_id IS NULL` and reports 0 even though the rows were inserted. This is the exact
        // regression the review flagged — the reason the scan must establish context.
        $this->assertSame(0, app(TenderItems::class)->sync($a, $tender),
            'without context the scoped count read sees nothing → sync reports 0');
        $this->assertSame(2, TenderItem::withoutGlobalScopes()->where('tender_id', $tender->id)->count(),
            'the rows were physically inserted all the same');
    }

    public function test_dedup_is_tenant_scoped_and_requires_context(): void
    {
        $a = Tenant::factory()->create();
        $ctx = app(TenantContext::class);
        $ctx->set($a->id);

        Tender::create([
            'tenant_id' => $a->id, 'source' => 'forum', 'source_ref' => 'DUP-1',
            'title' => 'Already seen', 'status' => 'new',
        ]);

        // With context the dedup check (TenderScout) correctly sees the existing tender...
        $ctx->set($a->id);
        $this->assertTrue(
            Tender::where('tenant_id', $a->id)->where('source', 'forum')->where('source_ref', 'DUP-1')->exists(),
            'under context, dedup finds the existing tender'
        );

        // ...without it, exists() is false → the scan would re-analyse and create a duplicate.
        $ctx->clear();
        $this->assertFalse(
            Tender::where('tenant_id', $a->id)->where('source', 'forum')->where('source_ref', 'DUP-1')->exists(),
            'without context, dedup misses → duplicate tenders'
        );
    }

    public function test_tender_items_are_isolated_between_tenants(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        TenderItem::create(['tenant_id' => $a->id, 'tender_id' => 1, 'item' => 'A-item', 'status' => 'to_price']);
        TenderItem::create(['tenant_id' => $b->id, 'tender_id' => 2, 'item' => 'B-item', 'status' => 'to_price']);

        $ctx = app(TenantContext::class);

        $ctx->set($a->id);
        $this->assertSame(1, TenderItem::count(), 'tenant A sees only its own line');
        $this->assertSame('A-item', TenderItem::first()->item);

        $ctx->set($b->id);
        $this->assertSame(1, TenderItem::count(), 'tenant B sees only its own line');
        $this->assertSame('B-item', TenderItem::first()->item);

        $ctx->clear();
        $this->assertSame(0, TenderItem::count(), 'no context → no rows (scope collapses to NULL)');
        $this->assertSame(2, TenderItem::withoutGlobalScopes()->count(), 'both rows exist');
    }

    public function test_scan_command_leaves_no_tenant_context_behind(): void
    {
        // tender_enabled defaults false → the loop hits its `continue`, exercising the finally with
        // no network call. A sentinel proves the command actively cleared context, not that it was
        // already null.
        Tenant::factory()->create();
        $ctx = app(TenantContext::class);
        $ctx->set(999999);

        $this->assertSame(0, Artisan::call('tenders:scan'));
        $this->assertNull($ctx->id(), 'the command must not leave a tenant id set after it runs');
    }
}
