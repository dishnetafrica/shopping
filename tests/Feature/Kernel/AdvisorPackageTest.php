<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\AdvisorProject;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Advisor API (Slice 4 — Solution Packages). Proves packages are listed, that starting a
 * package creates a sized project and a full proposal in one call, and that an equipment SKU present in
 * the catalogue is resolved to the real product (its price/name), while unknown SKUs keep the package's
 * indicative price.
 */
class AdvisorPackageTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8), 'settings' => ['vertical' => 'solar']]);
        $u = User::create(['tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    public function test_packages_are_listed(): void
    {
        $this->boot();
        $j = $this->getJson('/papi/advisor/packages?profile=solar')->assertOk()->json();
        $this->assertTrue($j['ok']);
        $keys = array_column($j['packages'], 'key');
        $this->assertContains('home_starter', $keys);
        $this->assertContains('borehole', $keys);
    }

    public function test_start_package_creates_sized_project_and_proposal(): void
    {
        $t = $this->boot();

        // A catalogue product that matches one of the package SKUs, priced differently from the indicative.
        $panel = Product::create([
            'tenant_id' => $t->id, 'name' => 'Premium Panel 200W', 'sku' => 'SP-200',
            'category' => 'Solar', 'price' => 400000, 'base_price' => 400000, 'stock' => 50, 'active' => true,
        ]);

        $j = $this->postJson('/papi/advisor/packages/home_starter/start', ['profile' => 'solar'])
            ->assertOk()->json();

        $this->assertTrue($j['ok']);
        $pid = $j['project_id'];
        $this->assertCount(11, $j['proposal']['sections']);

        // Sized project persisted with a proposal.
        $row = AdvisorProject::find($pid);
        $this->assertNotNull($row);
        $this->assertSame('proposed', $row->status);
        $this->assertGreaterThan(0, $row->sizing['result']['pv_array_w']);
        $this->assertNotNull($row->proposal);

        // The catalogue SKU resolved to the real product (name + price), not the package's indicative one.
        $items = collect($j['proposal']['sections'])->firstWhere('key', 'equipment_list')['items'];
        $resolved = collect($items)->firstWhere('name', 'Premium Panel 200W');
        $this->assertNotNull($resolved, 'SP-200 should resolve to the catalogue product');
        $this->assertEqualsWithDelta(400000.0, $resolved['unit_price'], 0.01);

        // An unknown SKU keeps its package name/price.
        $this->assertTrue(collect($items)->contains(fn ($i) => $i['name'] === '1kVA inverter'));
    }
}
