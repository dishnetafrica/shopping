<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\MaintenancePlan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WorkOrder;
use App\Services\Field\MaintenancePlanService;
use App\Services\Inventory\SerialService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S6 — recurring maintenance / service plans. Proves: create computes the
 * next-due date; logging a visit spawns a completed service Work Order (reusing the Jobs engine) and
 * rolls next_due forward by the interval; the due list surfaces what's due and excludes paused plans; a
 * visit on an already-installed unit never re-touches its warranty; API is manager-gated; tenant-isolated.
 */
class MaintenancePlanTest extends TestCase
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

    public function test_create_computes_next_due(): void
    {
        $t = $this->boot();
        $plan = MaintenancePlanService::forTenant($t)->create([
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'interval_months' => 6,
        ]);

        $this->assertSame('active', $plan->status);
        $this->assertTrue(
            Carbon::parse($plan->next_due)->between(now()->addMonths(6)->subDays(2), now()->addMonths(6)->addDays(2)),
            'next_due should be ~6 months out'
        );
    }

    public function test_log_visit_spawns_service_job_and_advances_next_due(): void
    {
        $t = $this->boot();
        $svc = MaintenancePlanService::forTenant($t);
        $plan = $svc->create([
            'customer_name' => 'Asish', 'customer_phone' => '211900',
            'interval_months' => 3, 'next_due' => now()->addDays(5)->toDateString(),   // due soon
        ]);

        $res = $svc->logVisit($plan, ['notes' => 'Cleaned panels, checked inverter']);

        // A real service job was created and closed.
        $wo = $res['work_order'];
        $this->assertSame('service', (string) $wo->type);
        $this->assertSame('completed', (string) $wo->fresh()->status);
        $this->assertSame(1, WorkOrder::where('type', 'service')->count());
        $this->assertSame(1, $this->events($t, 'ServiceVisitCompleted'));

        // next_due rolled forward ~3 months from today; last_service recorded; linked.
        $plan = $res['plan']->fresh();
        $this->assertSame(now()->toDateString(), $plan->last_service_at->toDateString());
        $this->assertTrue(
            Carbon::parse($plan->next_due)->between(now()->addMonths(3)->subDays(2), now()->addMonths(3)->addDays(2)),
            'next_due should advance ~3 months from the visit'
        );
        $this->assertSame($wo->id, (int) $plan->work_order_id);
    }

    public function test_visit_on_installed_unit_does_not_touch_warranty(): void
    {
        $t = $this->boot();
        // A serialized product with NO warranty policy, already installed.
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true]);
        $unit = SerialService::forTenant($t)->register($p->id, 'SN-1');
        SerialService::forTenant($t)->transition($unit, 'installed');   // now installed, no warranty (no terms)
        $this->assertSame(0, Warranty::count());

        $plan = MaintenancePlanService::forTenant($t)->create(['inventory_unit_id' => $unit->id, 'interval_months' => 6]);
        MaintenancePlanService::forTenant($t)->logVisit($plan);

        $this->assertSame('installed', (string) $unit->fresh()->status, 'service visit leaves the unit installed');
        $this->assertSame(0, Warranty::count(), 'a service visit never activates a warranty');
    }

    public function test_due_soon_and_pause(): void
    {
        $t = $this->boot();
        $svc = MaintenancePlanService::forTenant($t);
        $soon = $svc->create(['customer_name' => 'Due', 'next_due' => now()->addDays(10)->toDateString()]);
        $svc->create(['customer_name' => 'Later', 'next_due' => now()->addDays(200)->toDateString()]);

        $this->assertCount(1, $svc->dueSoon(30));

        $svc->pause($soon);
        $this->assertCount(0, $svc->dueSoon(30), 'a paused plan drops off the due list');
    }

    public function test_api_create_and_log_visit(): void
    {
        $t = $this->boot();
        $plan = $this->postJson('/papi/maintenance-plans', [
            'customer_phone' => '211900', 'interval_months' => 6,
        ])->assertOk()->json('plan');

        $this->postJson('/papi/maintenance-plans/' . $plan['id'] . '/log-visit', ['notes' => 'done'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, WorkOrder::where('type', 'service')->count());
        $this->assertNotNull(MaintenancePlan::find($plan['id'])->last_service_at);
    }

    public function test_writes_are_manager_only(): void
    {
        $this->boot('staff');
        $this->postJson('/papi/maintenance-plans', ['customer_phone' => '1'])->assertStatus(403);
    }

    public function test_tenant_isolated(): void
    {
        $a = $this->boot();
        MaintenancePlanService::forTenant($a)->create(['customer_phone' => '1']);
        $this->assertSame(1, MaintenancePlan::count());

        $this->boot();
        $this->assertSame(0, MaintenancePlan::count());
    }
}
