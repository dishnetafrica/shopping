<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Constitution v1.0 · P0 Security — proves the BelongsToTenant global scope actually ISOLATES tenants
 * at the database level (the structural TenantIsolationTest only proves the trait is present).
 *
 * Seeds two tenants, then verifies that with tenant A active you can never read tenant B's rows, that
 * a new row is stamped with the active tenant, and that super-admin context deliberately sees all.
 *
 * Requires the migration set to run on the sqlite test connection (phpunit.xml). If that harness is not
 * yet green, this test surfaces it loudly — the structural guard runs independently and needs no DB.
 */
class TenantIsolationDbTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Switch the active tenant (and optionally super-admin) for the assertions that follow. */
    private function actAs(?int $tenantId, bool $super = false): void
    {
        $c = $this->ctx();
        $c->asSuperAdmin($super);
        $c->set($tenantId);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => strtoupper($slug) . ' Trading',
            'slug' => $slug . '-' . substr(md5($slug . microtime()), 0, 8),
        ]);
    }

    private function seedOrder(int $tenantId, string $no): Order
    {
        return Order::create([
            'tenant_id' => $tenantId, 'order_no' => $no, 'customer_name' => 'Cust ' . $no,
            'customer_phone' => '2119000', 'total' => 100, 'status' => 'pending', 'channel' => 'whatsapp',
        ]);
    }

    private function seedInvoice(int $tenantId, string $no): Invoice
    {
        return Invoice::create([
            'tenant_id' => $tenantId, 'invoice_no' => $no, 'customer_name' => 'Cust ' . $no,
            'customer_phone' => '2119000', 'currency' => 'USD', 'total' => 100, 'amount_paid' => 0,
            'balance' => 100, 'status' => 'issued', 'issued_at' => now(),
        ]);
    }

    private function seedMovement(int $tenantId, string $sku): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $tenantId, 'sku' => $sku, 'location' => 'kampala', 'type' => 'IN', 'qty' => 10,
        ]);
    }

    public function test_reads_are_isolated_between_tenants(): void
    {
        // Seed under super-admin so scope never interferes with the inserts.
        $this->actAs(null, true);
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $this->seedOrder($a->id, 'ORD-A1');
        $this->seedOrder($a->id, 'ORD-A2');
        $this->seedOrder($b->id, 'ORD-B1');

        // Tenant A sees only its two orders, never B's.
        $this->actAs($a->id);
        $this->assertSame(2, Order::count(), 'tenant A should see exactly its 2 orders');
        $this->assertTrue(Order::where('order_no', 'ORD-A1')->exists());
        $this->assertFalse(
            Order::where('order_no', 'ORD-B1')->exists(),
            'LEAK: tenant A can see tenant B\'s order'
        );
        $bId = $b->id;
        $bOrderId = Order::withoutGlobalScopes()->where('order_no', 'ORD-B1')->value('id');
        $this->assertNull(Order::find($bOrderId), 'LEAK: tenant A resolved tenant B\'s order by id');

        // Tenant B sees only its one order, never A's.
        $this->actAs($bId);
        $this->assertSame(1, Order::count(), 'tenant B should see exactly its 1 order');
        $this->assertFalse(Order::where('order_no', 'ORD-A1')->exists(), 'LEAK: tenant B can see tenant A\'s order');
    }

    public function test_isolation_holds_for_invoices_and_stock_movements(): void
    {
        $this->actAs(null, true);
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $this->seedInvoice($a->id, 'INV-A-1');
        $this->seedInvoice($b->id, 'INV-B-1');
        $this->seedMovement($a->id, 'RICE');
        $this->seedMovement($b->id, 'SUGAR');

        $this->actAs($a->id);
        $this->assertSame(1, Invoice::count());
        $this->assertFalse(Invoice::where('invoice_no', 'INV-B-1')->exists(), 'LEAK: invoice cross-tenant');
        $this->assertSame(1, StockMovement::count());
        $this->assertFalse(StockMovement::where('sku', 'SUGAR')->exists(), 'LEAK: stock movement cross-tenant');
    }

    public function test_creating_a_row_stamps_the_active_tenant(): void
    {
        $this->actAs(null, true);
        $a = $this->tenant('a');
        $this->actAs($a->id);

        // Note: no tenant_id passed — the trait must stamp it from the active context.
        $o = Order::create([
            'order_no' => 'ORD-STAMP', 'customer_name' => 'Nadia', 'customer_phone' => '2119001',
            'total' => 50, 'status' => 'pending', 'channel' => 'whatsapp',
        ]);
        $this->assertSame($a->id, (int) $o->tenant_id, 'creating did not stamp the active tenant_id');
    }

    public function test_super_admin_context_sees_every_tenant(): void
    {
        $this->actAs(null, true);
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $this->seedOrder($a->id, 'ORD-A1');
        $this->seedOrder($b->id, 'ORD-B1');

        // still super-admin: the scope is bypassed on purpose (platform/admin views).
        $this->assertSame(2, Order::count(), 'super-admin should see all tenants\' orders');

        // and dropping back to a tenant re-imposes isolation.
        $this->actAs($a->id);
        $this->assertSame(1, Order::count());
    }
}
