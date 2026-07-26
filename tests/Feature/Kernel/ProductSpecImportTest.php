<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductSpecService;
use App\Support\Presets\SolarPreset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spec auto-import. Proves the catalogue importer fills product specifications from CSV columns that
 * match this tenant's spec catalogue (solar: Wattage, Voltage, Capacity, Battery Chemistry, …). A
 * number spec lands in value_number too (for filtering), an enum lands as text, blank spec cells are
 * skipped, and a CSV with no spec columns imports exactly as before (no specs written).
 */
class ProductSpecImportTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        SolarPreset::seedSpecCatalogue($t);   // Wattage, Voltage, Capacity, Battery Chemistry, …
        return $t;
    }

    private function zip(string $csv, array $images): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'psz');
        $za = new \ZipArchive();
        $za->open($tmp, \ZipArchive::OVERWRITE);
        $za->addFromString('products.csv', $csv);
        foreach ($images as $name) {
            $za->addFromString($name, "\xFF\xD8\xFF" . $name);
        }
        $za->close();
        $b64 = base64_encode((string) file_get_contents($tmp));
        @unlink($tmp);
        return $b64;
    }

    public function test_specs_fill_from_matching_columns(): void
    {
        if (! class_exists('ZipArchive')) { $this->markTestSkipped('zip extension unavailable'); }
        $t = $this->boot();
        Storage::fake('public');

        $csv = "Product Name,Category,Price USD,Stock,Image,Wattage,Voltage,Current,Capacity,Battery Chemistry,Lumens\n"
             . "300W Solar Power Station,Solar,199,100,main.jpeg,300,12.8,5,20,LiFePO4,\n";
        $b64 = $this->zip($csv, ['main.jpeg']);

        $this->postJson('/papi/products-import', ['zip' => $b64, 'apply' => 1])
            ->assertOk()->assertJson(['ok' => true, 'created' => 1]);

        $p = Product::where('name', '300W Solar Power Station')->firstOrFail();
        $specs = collect(ProductSpecService::forTenant($t)->specsFor($p->id))->keyBy('key');

        $this->assertSame('300', $specs['wattage']['value'] ?? null);
        $this->assertSame('12.8', $specs['voltage']['value'] ?? null);
        $this->assertSame('5', $specs['current']['value'] ?? null);
        $this->assertSame('20', $specs['capacity_ah']['value'] ?? null);
        $this->assertSame('LiFePO4', $specs['battery_chemistry']['value'] ?? null);
        // Lumens was blank -> not stored.
        $this->assertArrayNotHasKey('lumens', $specs->all());

        // Number spec also stored numerically (so wattage >= 200 filters work).
        $def = \App\Models\SpecDefinition::where('tenant_id', $t->id)->where('key', 'wattage')->firstOrFail();
        $row = ProductSpec::where('tenant_id', $t->id)->where('product_id', $p->id)
            ->where('spec_definition_id', $def->id)->firstOrFail();
        $this->assertSame(300.0, (float) $row->value_number);
    }

    public function test_no_spec_columns_writes_no_specs(): void
    {
        if (! class_exists('ZipArchive')) { $this->markTestSkipped('zip extension unavailable'); }
        $t = $this->boot();
        Storage::fake('public');

        $csv = "Product Name,Category,Price USD,Stock,Image\n"
             . "Plain Product,Solar,150,4,p.jpeg\n";
        $b64 = $this->zip($csv, ['p.jpeg']);

        $this->postJson('/papi/products-import', ['zip' => $b64, 'apply' => 1])
            ->assertOk()->assertJson(['ok' => true, 'created' => 1]);

        $p = Product::where('name', 'Plain Product')->firstOrFail();
        $this->assertSame(0, ProductSpec::where('tenant_id', $t->id)->where('product_id', $p->id)->count());
    }
}
