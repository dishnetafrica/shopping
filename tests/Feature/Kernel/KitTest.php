<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\KitComponent;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\KitService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4a — the kit / bundle engine. Proves: a kit knows its parts and is flagged is_kit; explode() expands
 * the parts scaled by kit quantity; CSV import builds kits from SKUs (dry-run then apply) and merges
 * duplicate parts; a kit can't contain itself; the product page lists a kit's contents; ordinary
 * products are untouched; tenant-isolated; import is owner/manager-only. Nothing touches pricing/stock.
 */
class KitTest extends TestCase
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

    public function test_set_components_marks_kit_and_explodes(): void
    {
        $t   = $this->boot();
        $kit = Product::create(['name' => '3kW Solar Kit', 'price' => 5_000_000, 'sku' => 'KIT-3KW']);
        $inv = Product::create(['name' => 'Inverter', 'price' => 900_000, 'sku' => 'INV']);
        $pan = Product::create(['name' => 'Panel 550W', 'price' => 200_000, 'sku' => 'PNL']);

        $svc = KitService::forTenant($t);
        $svc->setComponents($kit->id, [
            ['component_product_id' => $inv->id, 'quantity' => 1],
            ['component_product_id' => $pan->id, 'quantity' => 8],
        ]);

        $this->assertTrue($svc->isKit($kit->id));
        $this->assertTrue((bool) $kit->fresh()->is_kit);

        $parts = $svc->explode($kit->id, 2);   // two kits
        $this->assertCount(2, $parts);
        $byId = collect($parts)->keyBy('product_id');
        $this->assertSame(2.0, (float) $byId[$inv->id]['quantity']);   // 1 × 2 kits
        $this->assertSame(16.0, (float) $byId[$pan->id]['quantity']);  // 8 × 2 kits
        $this->assertSame(200000.0, (float) $byId[$pan->id]['unit_price']);
    }

    public function test_import_csv_dry_run_then_apply(): void
    {
        $t   = $this->boot();
        Product::create(['name' => '3kW Solar Kit', 'price' => 5_000_000, 'sku' => 'KIT-3KW']);
        Product::create(['name' => 'Inverter', 'price' => 900_000, 'sku' => 'INV']);
        Product::create(['name' => 'Panel 550W', 'price' => 200_000, 'sku' => 'PNL']);

        $csv = "Kit SKU,Part SKU,Quantity\n"
             . "KIT-3KW,INV,1\n"
             . "KIT-3KW,PNL,8\n";

        $svc = KitService::forTenant($t);

        $dry = $svc->importCsv($csv, false);
        $this->assertTrue($dry['ok']);
        $this->assertSame(1, $dry['kits']);
        $this->assertSame(2, $dry['components']);
        $this->assertSame(0, KitComponent::count(), 'dry-run persists nothing');

        $applied = $svc->importCsv($csv, true);
        $this->assertTrue($applied['apply']);
        $this->assertSame(2, KitComponent::count());
        $kit = Product::where('sku', 'KIT-3KW')->first();
        $this->assertTrue((bool) $kit->fresh()->is_kit);
        $this->assertCount(2, $svc->explode($kit->id));
    }

    public function test_import_reports_unresolved_and_merges_duplicates(): void
    {
        $t = $this->boot();
        Product::create(['name' => 'Kit', 'price' => 100, 'sku' => 'K']);
        Product::create(['name' => 'Cable', 'price' => 20, 'sku' => 'CBL']);

        $csv = "Kit SKU,Part SKU,Quantity\n"
             . "K,CBL,3\n"
             . "K,CBL,2\n"          // same part twice → merged to 5
             . "K,NOPE,1\n";        // unknown part → reported, skipped

        $res = KitService::forTenant($t)->importCsv($csv, true);
        $this->assertNotEmpty($res['unresolved']);

        $kit = Product::where('sku', 'K')->first();
        $rows = KitComponent::where('kit_product_id', $kit->id)->get();
        $this->assertCount(1, $rows, 'duplicate part merged into one row');
        $this->assertSame(5.0, (float) $rows->first()->quantity);
    }

    public function test_kit_cannot_contain_itself(): void
    {
        $t = $this->boot();
        $kit = Product::create(['name' => 'Kit', 'price' => 100, 'sku' => 'K']);

        KitService::forTenant($t)->setComponents($kit->id, [
            ['component_product_id' => $kit->id, 'quantity' => 1],   // self — ignored
        ]);

        $this->assertSame(0, KitComponent::count());
        $this->assertFalse((bool) $kit->fresh()->is_kit);
    }

    public function test_removing_all_parts_clears_kit_flag(): void
    {
        $t = $this->boot();
        $kit = Product::create(['name' => 'Kit', 'price' => 100, 'sku' => 'K']);
        $part = Product::create(['name' => 'Part', 'price' => 10, 'sku' => 'P']);
        $svc = KitService::forTenant($t);

        $svc->setComponents($kit->id, [['component_product_id' => $part->id, 'quantity' => 1]]);
        $this->assertTrue((bool) $kit->fresh()->is_kit);

        $svc->setComponents($kit->id, []);   // clear
        $this->assertFalse((bool) $kit->fresh()->is_kit);
        $this->assertSame(0, KitComponent::count());
    }

    public function test_product_page_shows_kit_contents(): void
    {
        $t = $this->boot();
        $kit = Product::create(['name' => '3kW Solar Kit', 'price' => 5_000_000, 'sku' => 'KIT-3KW']);
        $pan = Product::create(['name' => 'Panel 550W', 'price' => 200_000, 'sku' => 'PNL']);
        KitService::forTenant($t)->setComponents($kit->id, [['component_product_id' => $pan->id, 'quantity' => 8]]);

        $j = $this->getJson('/api/v1/workspace/product/' . $kit->id)->assertOk()->json();

        $answers = collect($j['answers'] ?? []);
        $panel = $answers->firstWhere('q', 'Panel 550W');
        $this->assertNotNull($panel, 'kit parts should render in the answers section');
        $this->assertSame('×8', $panel['value']);

        $this->assertTrue(collect($j['stats'] ?? [])->contains(fn ($s) => ($s['key'] ?? '') === 'kit_parts'));
    }

    public function test_ordinary_product_shows_no_kit_section(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Bread', 'price' => 5, 'sku' => 'BRD']);

        $j = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();
        $this->assertSame([], $j['answers'] ?? []);
        $this->assertFalse((bool) $p->fresh()->is_kit);
    }

    public function test_import_via_api_is_manager_only(): void
    {
        $this->boot('staff');
        $csv = "Kit SKU,Part SKU,Quantity\nK,P,1\n";

        $this->postJson('/papi/kits/import', ['csv' => $csv, 'apply' => 1])->assertStatus(403);
    }

    public function test_tenant_isolated(): void
    {
        $a = $this->boot();
        $kitA = Product::create(['name' => 'Kit A', 'price' => 1, 'sku' => 'KA']);
        $partA = Product::create(['name' => 'Part A', 'price' => 1, 'sku' => 'PA']);
        KitService::forTenant($a)->setComponents($kitA->id, [['component_product_id' => $partA->id, 'quantity' => 1]]);
        $this->assertSame(1, KitComponent::count());

        $this->boot();   // new tenant
        $this->assertSame(0, KitComponent::count());
    }
}
