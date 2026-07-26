<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Field\WorkOrderService;
use App\Services\Inventory\SerialService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S5b — the V3 Jobs workspace. Proves: the capability catalog (which the V3
 * sidebar is built from) includes 'jobs'; the Jobs list renders rows that deep-link to a job; the job
 * detail renders its equipment and lifecycle actions; and completing from the API installs the units.
 */
class WorkOrderWorkspaceTest extends TestCase
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

    public function test_catalog_includes_jobs(): void
    {
        $this->boot();
        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'jobs'), 'the V3 sidebar catalog must include jobs');
    }

    public function test_jobs_list_renders_rows_linking_to_detail(): void
    {
        $t = $this->boot();
        $wo = WorkOrderService::forTenant($t)->create(['type' => 'installation', 'customer_name' => 'Asish']);

        $j = $this->getJson('/api/v1/workspace/jobs')->assertOk()->json();
        $rows = collect($j['table']['rows'] ?? $j['table'] ?? []);
        $this->assertTrue($rows->isNotEmpty(), 'the jobs list should have a row');
        $row = $rows->first(fn ($r) => str_contains((string) ($r['link'] ?? ''), '/workspace/workOrder/' . $wo->id));
        $this->assertNotNull($row, 'a row should deep-link to the job detail');
    }

    public function test_job_detail_shows_equipment_and_actions(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true,
            'warranty_manufacturer_months' => 24]);
        $unit = SerialService::forTenant($t)->register($p->id, 'SN-1');
        $wo = WorkOrderService::forTenant($t)->create([
            'type' => 'installation', 'customer_name' => 'Asish',
            'items' => [['inventory_unit_id' => $unit->id]],
        ]);

        $j = $this->getJson('/api/v1/workspace/workOrder/' . $wo->id)->assertOk()->json();
        $this->assertSame((string) $wo->number, (string) ($j['object']['ref'] ?? ''));
        $this->assertTrue(collect($j['actions'] ?? [])->contains(fn ($a) => ($a['key'] ?? '') === 'complete'));

        // Completing from the API installs the unit and activates its warranty.
        $this->postJson('/papi/work-orders/' . $wo->id . '/complete')->assertOk();
        $this->assertSame('installed', (string) $unit->fresh()->status);
        $this->assertSame(1, \App\Models\Warranty::count());
    }
}
