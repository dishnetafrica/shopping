<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Field\MaintenancePlanService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S6b — the V3 Maintenance workspace. Proves: the capability catalog (which
 * the V3 sidebar is built from) includes 'maintenance'; the list renders plans with due status linking
 * to detail; the plan detail offers a "Log a visit" action; and logging from the API spawns a service job.
 */
class MaintenanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    public function test_catalog_includes_maintenance(): void
    {
        $this->boot();
        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'maintenance'));
    }

    public function test_list_shows_due_status_linking_to_detail(): void
    {
        $t = $this->boot();
        $plan = MaintenancePlanService::forTenant($t)->create([
            'customer_name' => 'Asish', 'next_due' => now()->subDays(1)->toDateString(),   // overdue
        ]);

        $j = $this->getJson('/api/v1/workspace/maintenance')->assertOk()->json();
        $rows = collect($j['table']['rows'] ?? []);
        $row = $rows->first(fn ($r) => str_contains((string) ($r['link'] ?? ''), '/workspace/maintenancePlan/' . $plan->id));
        $this->assertNotNull($row);
        $this->assertSame('Overdue', $row['status']['value']);
        $this->assertSame('danger', $row['status']['badge']);
    }

    public function test_detail_offers_log_visit_and_api_logs_it(): void
    {
        $t = $this->boot();
        $plan = MaintenancePlanService::forTenant($t)->create(['customer_name' => 'Asish', 'interval_months' => 6]);

        $j = $this->getJson('/api/v1/workspace/maintenancePlan/' . $plan->id)->assertOk()->json();
        $this->assertTrue(collect($j['actions'] ?? [])->contains(fn ($a) => ($a['key'] ?? '') === 'log_visit'));

        $this->postJson('/papi/maintenance-plans/' . $plan->id . '/log-visit', ['notes' => 'done'])->assertOk();
        $this->assertSame(1, WorkOrder::where('type', 'service')->count(), 'logging a visit spawns a service job');
    }
}
