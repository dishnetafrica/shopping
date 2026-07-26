<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

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
 * Asset Lifecycle · Phase 2 · S7b — the V3 Warranty Claims workspace. Proves: the capability catalog
 * (which the V3 sidebar is built from) includes 'claims'; the list shows claims with coverage linking to
 * detail; an open claim's detail offers approve/reject; approving from the API records the resolution.
 */
class WarrantyClaimWorkspaceTest extends TestCase
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

    private function openClaim(Tenant $t): WarrantyClaim
    {
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true]);
        $unit = SerialService::forTenant($t)->register($p->id, 'SN-1');
        $w = WarrantyService::forTenant($t)->activate([
            'product_id' => $p->id, 'inventory_unit_id' => $unit->id,
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'company_months' => 24,
        ]);
        return WarrantyClaimService::forTenant($t)->open(['warranty_id' => $w->id, 'fault_description' => 'No output']);
    }

    public function test_catalog_includes_claims(): void
    {
        $this->boot();
        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'claims'));
    }

    public function test_list_shows_coverage_linking_to_detail(): void
    {
        $t = $this->boot();
        $claim = $this->openClaim($t);

        $j = $this->getJson('/api/v1/workspace/claims')->assertOk()->json();
        $rows = collect($j['table']['rows'] ?? []);
        $row = $rows->first(fn ($r) => str_contains((string) ($r['link'] ?? ''), '/workspace/warrantyClaim/' . $claim->id));
        $this->assertNotNull($row);
        $this->assertSame('Active', $row['coverage']['value']);
        $this->assertSame('Open', $row['status']['value']);
    }

    public function test_detail_offers_decisions_and_api_approves(): void
    {
        $t = $this->boot();
        $claim = $this->openClaim($t);

        $j = $this->getJson('/api/v1/workspace/warrantyClaim/' . $claim->id)->assertOk()->json();
        $keys = collect($j['actions'] ?? [])->pluck('key');
        $this->assertTrue($keys->contains('approve'));
        $this->assertTrue($keys->contains('reject'));

        $this->postJson('/papi/warranty-claims/' . $claim->id . '/approve', ['resolution' => 'replace'])->assertOk();
        $this->assertSame('approved', WarrantyClaim::find($claim->id)->status);
        $this->assertSame('replace', WarrantyClaim::find($claim->id)->resolution);
    }
}
