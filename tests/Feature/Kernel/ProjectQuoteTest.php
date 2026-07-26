<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\KitService;
use App\Services\Sales\ProjectQuoteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S8 — Project Quotes. Proves: kits explode into priced sections with
 * correct subtotals (pure, engine-free); extras become their own section; building a project quote
 * creates a real quotation (frozen engine, untouched) and records the section grouping in its meta; the
 * API is manager-gated; tenant-isolated. Section math is asserted independently of engine pricing.
 */
class ProjectQuoteTest extends TestCase
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

    /** A kit "3kW Solar Kit" = 1 inverter (900k) + 8 panels (200k). Returns the kit product. */
    private function kit(Tenant $t): Product
    {
        $kit = Product::create(['name' => '3kW Solar Kit', 'price' => 5_000_000, 'sku' => 'KIT-3KW']);
        $inv = Product::create(['name' => 'Inverter', 'price' => 900_000, 'sku' => 'INV']);
        $pan = Product::create(['name' => 'Panel 550W', 'price' => 200_000, 'sku' => 'PNL']);
        KitService::forTenant($t)->setComponents($kit->id, [
            ['component_product_id' => $inv->id, 'quantity' => 1],
            ['component_product_id' => $pan->id, 'quantity' => 8],
        ]);
        return $kit;
    }

    public function test_build_sections_is_pure_and_priced(): void
    {
        $t = $this->boot();
        $kit = $this->kit($t);

        $built = ProjectQuoteService::forTenant($t)->buildSections([
            'kit_ids' => [$kit->id],
            'extras'  => [['name' => 'Installation labour', 'qty' => 1, 'price' => 500_000]],
        ]);

        $this->assertCount(2, $built['sections']);           // the kit + the extras
        $kitSection = $built['sections'][0];
        $this->assertSame('3kW Solar Kit', $kitSection['title']);
        $this->assertCount(2, $kitSection['lines']);
        $this->assertSame(2_500_000.0, (float) $kitSection['subtotal']);   // 900k×1 + 200k×8
        $this->assertSame('Installation & extras', $built['sections'][1]['title']);
        $this->assertSame(500_000.0, (float) $built['sections'][1]['subtotal']);

        // The flat line list handed to the engine covers every line (2 kit parts + 1 extra).
        $this->assertCount(3, $built['flat']);
    }

    public function test_from_kits_creates_quote_with_section_meta(): void
    {
        $t = $this->boot();
        $kit = $this->kit($t);

        $res = ProjectQuoteService::forTenant($t)->fromKits([
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'kit_ids' => [$kit->id],
        ]);

        $this->assertTrue($res['ok']);
        $this->assertNotEmpty($res['id']);
        $this->assertSame(1, $res['sections']);

        $q = Quotation::find($res['id']);
        $this->assertNotNull($q);
        $this->assertTrue((bool) ($q->meta['project']['is_project'] ?? false));
        $this->assertSame('3kW Solar Kit', $q->meta['project']['sections'][0]['title']);
        $this->assertSame(2_500_000.0, (float) $q->meta['project']['sections_total']);
        $this->assertGreaterThan(0, (float) $q->total);   // priced by the frozen engine
    }

    public function test_no_kits_is_an_error(): void
    {
        $t = $this->boot();
        $res = ProjectQuoteService::forTenant($t)->fromKits(['customer_name' => 'X', 'customer_phone' => '211900', 'kit_ids' => []]);
        $this->assertFalse($res['ok']);
        $this->assertSame('no_lines', $res['error']);
    }

    public function test_api_is_manager_only(): void
    {
        $this->boot('staff');
        $this->postJson('/papi/project-quotes/from-kits', ['customer_phone' => '211900'])->assertStatus(403);
    }

    public function test_tenant_isolated(): void
    {
        $a = $this->boot();
        $kit = $this->kit($a);
        ProjectQuoteService::forTenant($a)->fromKits(['customer_name' => 'A', 'customer_phone' => '211900', 'kit_ids' => [$kit->id]]);
        $this->assertSame(1, Quotation::count());

        $this->boot();
        $this->assertSame(0, Quotation::count());
    }
}
