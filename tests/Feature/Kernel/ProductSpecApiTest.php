<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\SpecDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductSpecService;
use App\Support\Presets\SolarPreset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product Specifications end-to-end (core capability). Proves: the solar preset seeds a catalogue;
 * values set on a product read back with unit/label; numeric filtering and enum filtering work;
 * catalogue/value edits are owner/manager-only; and everything is tenant-isolated. Factory-independent.
 */
class ProductSpecApiTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => $role,
            'email' => 'owner-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    private function product(string $name = 'Panel 550'): Product
    {
        return Product::create(['name' => $name, 'price' => 200]);
    }

    public function test_solar_preset_seeds_catalogue(): void
    {
        $this->boot();
        $this->postJson('/papi/spec-definitions/seed-solar')->assertOk()->assertJson(['ok' => true, 'seeded' => 10]);
        $this->assertSame(10, SpecDefinition::count());
        // idempotent — re-seeding does not duplicate
        $this->postJson('/papi/spec-definitions/seed-solar')->assertOk();
        $this->assertSame(10, SpecDefinition::count());
    }

    public function test_set_and_read_product_specs(): void
    {
        $this->boot();
        SolarPreset::seedSpecCatalogue(Tenant::first());
        $p = $this->product();

        $this->putJson("/papi/products/{$p->id}/specs", ['specs' => [
            'wattage' => '550', 'battery_chemistry' => 'LiFePO4', 'ip_rating' => 'IP65',
        ]])->assertOk()->assertJson(['ok' => true, 'written' => 3]);

        $read = $this->getJson("/papi/products/{$p->id}/specs")->assertOk()->json('specs');
        $byKey = collect($read)->keyBy('key');
        $this->assertSame('550', $byKey['wattage']['value']);
        $this->assertSame('W', $byKey['wattage']['unit']);
        $this->assertSame('LiFePO4', $byKey['battery_chemistry']['value']);
    }

    public function test_numeric_and_enum_filtering(): void
    {
        $t = $this->boot();
        SolarPreset::seedSpecCatalogue($t);
        $big = $this->product('Panel 550');
        $small = $this->product('Panel 300');
        $svc = ProductSpecService::forTenant($t);
        $svc->setProductSpecs($big->id, ['wattage' => '550', 'battery_chemistry' => 'LiFePO4']);
        $svc->setProductSpecs($small->id, ['wattage' => '300', 'battery_chemistry' => 'AGM']);

        $ge400 = $this->getJson('/papi/product-spec-filter?filters[wattage][min]=400')->json('product_ids');
        $this->assertContains($big->id, $ge400);
        $this->assertNotContains($small->id, $ge400);

        $lifepo = $this->getJson('/papi/product-spec-filter?filters[battery_chemistry][eq]=LiFePO4')->json('product_ids');
        $this->assertSame([$big->id], $lifepo);

        // combined filter (AND): ≥400W AND AGM → nobody
        $none = $this->getJson('/papi/product-spec-filter?filters[wattage][min]=400&filters[battery_chemistry][eq]=AGM')->json('product_ids');
        $this->assertSame([], $none);
    }

    public function test_clearing_a_spec(): void
    {
        $t = $this->boot();
        SolarPreset::seedSpecCatalogue($t);
        $p = $this->product();
        $svc = ProductSpecService::forTenant($t);
        $svc->setProductSpecs($p->id, ['wattage' => '550']);
        $this->assertCount(1, $svc->specsFor($p->id));
        $svc->setProductSpecs($p->id, ['wattage' => '']);   // blank clears
        $this->assertCount(0, $svc->specsFor($p->id));
    }

    public function test_catalogue_edit_is_manager_only(): void
    {
        $this->boot('staff');
        $this->postJson('/papi/spec-definitions', ['label' => 'Weight', 'data_type' => 'number'])
            ->assertStatus(403);
    }

    public function test_specs_are_tenant_isolated(): void
    {
        $a = $this->boot();
        SolarPreset::seedSpecCatalogue($a);
        $this->assertSame(10, SpecDefinition::count());

        // a second tenant starts empty
        $this->boot();
        $this->assertSame(0, SpecDefinition::count());
    }
}
