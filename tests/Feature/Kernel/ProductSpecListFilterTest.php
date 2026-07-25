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
 * S1c-2 — the product list exposes spec filters (as hidden, filterable row data) and a
 * "Specifications (CSV)" download, all via the payload (no front-end change).
 */
class ProductSpecListFilterTest extends TestCase
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

    public function test_list_declares_spec_filters_and_carries_hidden_spec_cells(): void
    {
        $t = $this->boot();
        $p1 = Product::create(['name' => 'Panel 550', 'price' => 200, 'sku' => 'PNL-550']);
        $p2 = Product::create(['name' => 'Panel 300', 'price' => 120, 'sku' => 'PNL-300']);
        ProductSpecService::forTenant($t)->setProductSpecs($p1->id, ['wattage' => '550', 'battery_chemistry' => 'LiFePO4']);
        ProductSpecService::forTenant($t)->setProductSpecs($p2->id, ['wattage' => '300']);

        $j = $this->getJson('/api/v1/workspace/products')->assertOk()->json();

        // a Wattage filter is declared on the table
        $filters = collect($j['table']['filters'] ?? []);
        $this->assertTrue($filters->contains(fn ($f) => ($f['key'] ?? '') === 'spec_wattage'));
        $this->assertTrue($filters->contains(fn ($f) => ($f['label'] ?? '') === 'Wattage (W)'));

        // the rows carry the hidden spec cell used for filtering
        $rows = collect($j['table']['rows']);
        $panel550 = $rows->firstWhere('link', '/api/v1/workspace/product/' . $p1->id);
        $this->assertSame('550', $panel550['spec_wattage']['value']);
        // Panel 300 has no chemistry → no hidden cell for it
        $panel300 = $rows->firstWhere('link', '/api/v1/workspace/product/' . $p2->id);
        $this->assertArrayNotHasKey('spec_battery_chemistry', $panel300);
    }

    public function test_export_chooser_includes_spec_sheet(): void
    {
        $this->boot();
        $j = $this->getJson('/api/v1/workspace/products')->assertOk()->json();

        $export = collect($j['actions'] ?? [])->firstWhere('key', 'export_catalog');
        $this->assertNotNull($export, 'the export menu should exist');
        $hrefs = collect($export['chooser'] ?? [])->map(fn ($c) => $c['action']['href'] ?? '')->all();
        $this->assertContains('/api/v1/doc/spec-sheet', $hrefs);
    }
}
