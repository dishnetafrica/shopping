<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalog\KitService;
use App\Services\Catalog\ProductSpecService;
use App\Support\Presets\SolarPreset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storefront solar product page (Stage 1 — API payload). Proves the public product endpoint now carries
 * what a solar buyer needs — specifications, warranty, kit contents, sell mode and the shop's vertical —
 * and that these read correctly on the public route (which has no tenant context) via context pinning.
 * A grocery product is unaffected: sell_mode 'buy', no specs, no kit, vertical 'grocery'.
 */
class StorefrontSolarProductTest extends TestCase
{
    use RefreshDatabase;

    private function slug(): string
    {
        return 't-' . substr(md5(uniqid('', true)), 0, 8);
    }

    public function test_solar_product_page_carries_specs_warranty_kit_and_sell_mode(): void
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => $this->slug(), 'settings' => ['vertical' => 'solar']]);
        app(TenantContext::class)->set($t->id);
        SolarPreset::seedSpecCatalogue($t);

        $kit = Product::create([
            'tenant_id' => $t->id, 'name' => '300W Solar Kit', 'category' => 'Solar',
            'price' => 199, 'base_price' => 199, 'stock' => 10, 'active' => true,
            'sell_mode' => 'quote', 'warranty_manufacturer_months' => 12, 'warranty_company_months' => 6,
        ]);
        $bulb = Product::create([
            'tenant_id' => $t->id, 'name' => 'LED Bulb', 'category' => 'Solar',
            'price' => 5, 'base_price' => 5, 'stock' => 100, 'active' => true,
        ]);
        KitService::forTenant($t)->setComponents($kit->id, [['component_product_id' => $bulb->id, 'quantity' => 2]]);
        ProductSpecService::forTenant($t)->setProductSpecs($kit->id, ['wattage' => 300, 'battery_chemistry' => 'LiFePO4']);

        $j = $this->getJson('/' . $t->slug . '/product/' . $kit->id)->assertOk()->json();

        $this->assertSame('quote', $j['product']['sell_mode']);
        $this->assertTrue($j['product']['is_kit']);
        $this->assertSame(12, $j['product']['warranty']['manufacturer_months']);
        $this->assertSame(6, $j['product']['warranty']['company_months']);
        $this->assertSame('solar', $j['vertical']);

        $specs = collect($j['specs'])->keyBy('key');
        $this->assertSame('300', $specs['wattage']['value'] ?? null);
        $this->assertSame('LiFePO4', $specs['battery_chemistry']['value'] ?? null);

        $kitc = collect($j['kit']);
        $this->assertTrue($kitc->contains(fn ($r) => $r['name'] === 'LED Bulb' && (float) $r['qty'] === 2.0));
    }

    public function test_grocery_product_defaults_to_buy_with_no_specs_or_kit(): void
    {
        $t = Tenant::create(['name' => 'Grocery Co', 'slug' => $this->slug()]);   // no vertical => grocery
        app(TenantContext::class)->set($t->id);

        $p = Product::create([
            'tenant_id' => $t->id, 'name' => 'Milk 1L', 'category' => 'Dairy',
            'price' => 2, 'base_price' => 2, 'stock' => 5, 'active' => true,
        ]);

        $j = $this->getJson('/' . $t->slug . '/product/' . $p->id)->assertOk()->json();

        $this->assertSame('buy', $j['product']['sell_mode']);
        $this->assertFalse($j['product']['is_kit']);
        $this->assertSame('grocery', $j['vertical']);
        $this->assertSame([], $j['specs']);
        $this->assertSame([], $j['kit']);
    }
}
