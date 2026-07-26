<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\Inventory\SerialService;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S7 — Warranty Claims. Proves: opening a claim inherits the warranty's
 * serial/customer; approving sets a resolution + decision stamp and emits the event; rejecting works;
 * decisions only act on an OPEN claim (idempotent); the API reports whether the warranty is in coverage;
 * writes are manager-only; tenant-isolated.
 */
class WarrantyClaimTest extends TestCase
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

    /** An active warranty on a serial unit, ready to claim against. */
    private function activeWarranty(Tenant $t)
    {
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true]);
        $unit = SerialService::forTenant($t)->register($p->id, 'SN-1');
        return WarrantyService::forTenant($t)->activate([
            'product_id' => $p->id, 'inventory_unit_id' => $unit->id,
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'company_months' => 24,
        ]);
    }

    public function test_open_inherits_from_warranty(): void
    {
        $t = $this->boot();
        $w = $this->activeWarranty($t);

        $claim = WarrantyClaimService::forTenant($t)->open([
            'warranty_id' => $w->id, 'fault_description' => 'No output at night',
        ]);

        $this->assertSame('open', $claim->status);
        $this->assertSame($w->id, (int) $claim->warranty_id);
        $this->assertSame('SN-1', $claim->serial);                 // inherited from the warranty
        $this->assertSame('211900', $claim->customer_phone);       // inherited
        $this->assertSame(1, $this->events($t, 'WarrantyClaimOpened'));
    }

    public function test_approve_sets_resolution_and_stamps(): void
    {
        $t = $this->boot();
        $w = $this->activeWarranty($t);
        $svc = WarrantyClaimService::forTenant($t);
        $claim = $svc->open(['warranty_id' => $w->id, 'fault_description' => 'Faulty MPPT']);

        $svc->approve($claim, ['resolution' => 'replace', 'resolution_notes' => 'Swap unit', 'decided_by' => 'Owner']);

        $claim->refresh();
        $this->assertSame('approved', $claim->status);
        $this->assertSame('replace', $claim->resolution);
        $this->assertSame('Owner', $claim->decided_by);
        $this->assertNotNull($claim->decided_at);
        $this->assertSame(1, $this->events($t, 'WarrantyClaimApproved'));
    }

    public function test_reject_and_decisions_only_act_on_open(): void
    {
        $t = $this->boot();
        $w = $this->activeWarranty($t);
        $svc = WarrantyClaimService::forTenant($t);
        $claim = $svc->open(['warranty_id' => $w->id]);

        $svc->reject($claim, ['resolution_notes' => 'Physical damage — not covered']);
        $this->assertSame('rejected', $claim->fresh()->status);

        // A rejected claim can't then be approved (decisions only act while open).
        $svc->approve($claim->fresh(), ['resolution' => 'repair']);
        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertSame(0, $this->events($t, 'WarrantyClaimApproved'));
    }

    public function test_api_reports_coverage(): void
    {
        $t = $this->boot();
        $w = $this->activeWarranty($t);   // active → in coverage

        $claim = $this->postJson('/papi/warranty-claims', [
            'warranty_id' => $w->id, 'fault_description' => 'No output',
        ])->assertOk()->json('claim');
        $this->assertSame('active', $claim['warranty_status']);
        $this->assertTrue($claim['in_coverage']);

        // An expired warranty → out of coverage.
        $expired = WarrantyService::forTenant($t)->activate(['warranty_start' => '2019-01-01', 'company_months' => 12, 'customer_phone' => '1']);
        $c2 = $this->postJson('/papi/warranty-claims', ['warranty_id' => $expired->id])->assertOk()->json('claim');
        $this->assertSame('expired', $c2['warranty_status']);
        $this->assertFalse($c2['in_coverage']);
    }

    public function test_writes_are_manager_only(): void
    {
        $this->boot('staff');
        $this->postJson('/papi/warranty-claims', ['fault_description' => 'x'])->assertStatus(403);
    }

    public function test_tenant_isolated(): void
    {
        $a = $this->boot();
        WarrantyClaimService::forTenant($a)->open(['fault_description' => 'x']);
        $this->assertSame(1, WarrantyClaim::count());

        $this->boot();
        $this->assertSame(0, WarrantyClaim::count());
    }
}
