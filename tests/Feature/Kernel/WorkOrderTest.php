<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Inventory\SerialService;
use App\Services\Field\WorkOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S5 — Jobs / Work Orders. Proves: a job is created (numbered) and moves
 * through its lifecycle; equipment (serial units) attaches; COMPLETING an installation marks those
 * units 'installed' and auto-activates their warranties (Phase 1 + Phase 2 lock together) and emits
 * InstallationCompleted; the API is manager-gated; tenant-isolated.
 */
class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => $role,
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    private function events(Tenant $t, string $name): int
    {
        return Event::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', $name)->count();
    }

    public function test_create_numbers_and_schedules(): void
    {
        $t = $this->boot();
        $svc = WorkOrderService::forTenant($t);

        $a = $svc->create(['type' => 'installation', 'customer_name' => 'Asish', 'customer_phone' => '211900']);
        $this->assertSame('JOB-0001', $a->number);
        $this->assertSame('draft', $a->status);

        $b = $svc->create(['type' => 'service', 'scheduled_for' => now()->addDay()->toDateTimeString()]);
        $this->assertSame('JOB-0002', $b->number);
        $this->assertSame('scheduled', $b->status, 'a scheduled date starts the job scheduled');
        $this->assertSame(1, $this->events($t, 'WorkOrderCreated') >= 1 ? 1 : 0);
    }

    public function test_completing_install_marks_units_installed_and_activates_warranty(): void
    {
        $t = $this->boot();
        // A serialized product that carries a default warranty policy (Phase 1 · S3b).
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true,
            'warranty_manufacturer_months' => 24]);
        $svcSerial = SerialService::forTenant($t);
        $u1 = $svcSerial->register($p->id, 'SN-1');
        $u2 = $svcSerial->register($p->id, 'SN-2');

        $svc = WorkOrderService::forTenant($t);
        $wo = $svc->create([
            'type' => 'installation', 'customer_name' => 'Asish', 'customer_phone' => '211900',
            'site_address' => 'Juba, Plot 5',
            'items' => [
                ['inventory_unit_id' => $u1->id],
                ['inventory_unit_id' => $u2->id],
            ],
        ]);
        $this->assertSame(2, WorkOrderItem::where('work_order_id', $wo->id)->count());

        $svc->complete($wo, 'Tech Joseph');

        $wo->refresh();
        $this->assertSame('completed', $wo->status);
        $this->assertNotNull($wo->completed_at);
        $this->assertSame('Tech Joseph', $wo->completed_by);

        // Both units are now installed…
        $this->assertSame('installed', (string) $u1->fresh()->status);
        $this->assertSame('installed', (string) $u2->fresh()->status);
        // …and each got its warranty auto-activated (S3c), from the product's default terms.
        $this->assertSame(2, Warranty::count());
        $this->assertSame(1, $this->events($t, 'InstallationCompleted'));
        $this->assertSame(2, $this->events($t, 'WarrantyActivated'));
    }

    public function test_complete_is_idempotent(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Battery', 'price' => 300, 'sku' => 'BAT', 'serialized' => true,
            'warranty_company_months' => 12]);
        $unit = SerialService::forTenant($t)->register($p->id, 'B-1');
        $svc = WorkOrderService::forTenant($t);
        $wo = $svc->create(['type' => 'installation', 'items' => [['inventory_unit_id' => $unit->id]]]);

        $svc->complete($wo, 'Tech');
        $svc->complete($wo->fresh(), 'Tech');   // second call must not double-anything

        $this->assertSame(1, Warranty::count());
        $this->assertSame(1, $this->events($t, 'InstallationCompleted'));
    }

    public function test_service_visit_completes_without_warranty(): void
    {
        $t = $this->boot();
        $svc = WorkOrderService::forTenant($t);
        $wo = $svc->create(['type' => 'service', 'customer_name' => 'Repeat customer']);
        $svc->start($wo);
        $svc->complete($wo->fresh(), 'Tech');

        $this->assertSame('completed', $wo->fresh()->status);
        $this->assertSame(0, Warranty::count());
        $this->assertSame(1, $this->events($t, 'ServiceVisitCompleted'));
    }

    public function test_api_create_and_complete(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Panel', 'price' => 200, 'sku' => 'PNL', 'serialized' => true,
            'warranty_manufacturer_months' => 120]);
        $unit = SerialService::forTenant($t)->register($p->id, 'P-1');

        $wo = $this->postJson('/papi/work-orders', [
            'type' => 'installation', 'customer_phone' => '211900',
            'items' => [['inventory_unit_id' => $unit->id]],
        ])->assertOk()->json('work_order');
        $this->assertSame('JOB-0001', $wo['number']);

        $this->postJson('/papi/work-orders/' . $wo['id'] . '/complete')->assertOk();
        $this->assertSame('installed', (string) $unit->fresh()->status);
        $this->assertSame(1, Warranty::count());
    }

    public function test_writes_are_manager_only(): void
    {
        $this->boot('staff');
        $this->postJson('/papi/work-orders', ['type' => 'installation'])->assertStatus(403);
    }

    public function test_tenant_isolated(): void
    {
        $a = $this->boot();
        WorkOrderService::forTenant($a)->create(['type' => 'installation']);
        $this->assertSame(1, WorkOrder::count());

        $this->boot();
        $this->assertSame(0, WorkOrder::count());
    }
}
