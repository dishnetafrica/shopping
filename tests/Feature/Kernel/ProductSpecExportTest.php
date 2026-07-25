<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductSpecService;
use App\Support\Presets\SolarPreset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S1c-1 — the spec-sheet export (products × their spec values) as a separate CSV, distinct from the
 * round-trip catalogue CSV. Proves the header carries a column per spec and each product's values land.
 */
class ProductSpecExportTest extends TestCase
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
        SolarPreset::seedSpecCatalogue($t);
        return $t;
    }

    public function test_spec_sheet_lists_products_with_their_specs(): void
    {
        $t = $this->boot();
        $p1 = Product::create(['name' => 'Panel 550', 'price' => 200, 'sku' => 'PNL-550']);
        $p2 = Product::create(['name' => 'Panel 300', 'price' => 120, 'sku' => 'PNL-300']);
        $svc = ProductSpecService::forTenant($t);
        $svc->setProductSpecs($p1->id, ['wattage' => '550', 'battery_chemistry' => 'LiFePO4']);
        $svc->setProductSpecs($p2->id, ['wattage' => '300']);

        $csv = $this->get('/api/v1/doc/spec-sheet')->assertOk()->getContent();

        // a column per spec (label + unit), and each product's values
        $this->assertStringContainsString('Wattage (W)', $csv);
        $this->assertStringContainsString('Battery Chemistry', $csv);
        $this->assertStringContainsString('Panel 550', $csv);
        $this->assertStringContainsString('PNL-550', $csv);
        $this->assertStringContainsString('550', $csv);
        $this->assertStringContainsString('LiFePO4', $csv);
        $this->assertStringContainsString('Panel 300', $csv);
    }

    public function test_export_is_csv_download(): void
    {
        $this->boot();
        Product::create(['name' => 'Inverter 3kW', 'price' => 900, 'sku' => 'INV-3K']);

        $res = $this->get('/api/v1/doc/spec-sheet')->assertOk();
        $this->assertStringContainsString('text/csv', strtolower((string) $res->headers->get('content-type')));
        $this->assertStringContainsString('attachment', strtolower((string) $res->headers->get('content-disposition')));
    }
}
