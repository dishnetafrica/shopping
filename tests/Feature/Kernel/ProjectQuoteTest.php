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
 * Asset Lifecycle · Phase 2 · S8 — Project Quotes. Covers S8's OWN additions without depending on the
 * frozen quotation engine (createFor → OrderCalculator → QuotationService), which the wider test suite
 * never invokes — it needs Dompdf / live context, so every other quotation test uses Quotation::create()
 * directly. So here: the section math is proven pure; the section grouping is verified by writing the
 * same meta the engine stamps and reading it back; and the sectioned view is proven via the workspace.
 * The fromKits() glue (buildSections + the frozen engine + stamp meta) rides on the production-proven
 * engine used live by the storefront.
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

    public function test_sections_for_reads_project_meta(): void
    {
        $t = $this->boot();
        $kit = $this->kit($t);
        $svc = ProjectQuoteService::forTenant($t);

        // The section grouping fromKits() computes, stored on a quotation exactly as it would be.
        $built = $svc->buildSections(['kit_ids' => [$kit->id]]);
        $q = Quotation::create([
            'tenant_id' => $t->id, 'quote_no' => 'Q-' . substr(md5(uniqid('', true)), 0, 6),
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'currency' => 'UGX',
            'total' => 2_500_000, 'status' => 'sent',
            'meta' => ['project' => [
                'is_project' => true, 'generated_from' => 'kits',
                'sections' => $built['sections'],
                'sections_total' => 2_500_000,
            ]],
        ]);

        $project = $svc->sectionsFor($q->fresh());
        $this->assertTrue((bool) $project['is_project']);
        $this->assertSame('3kW Solar Kit', $project['sections'][0]['title']);
        $this->assertSame(2_500_000.0, (float) $project['sections_total']);

        // An ordinary quotation has no project grouping.
        $plain = Quotation::create(['tenant_id' => $t->id, 'quote_no' => 'Q-PLAIN', 'customer_phone' => '1',
            'currency' => 'UGX', 'total' => 10, 'status' => 'sent']);
        $this->assertNull($svc->sectionsFor($plain->fresh()));
    }

    public function test_no_kits_is_an_error(): void
    {
        $t = $this->boot();
        // Guard fires before the engine is ever called, so this is engine-free.
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
        Quotation::create(['tenant_id' => $a->id, 'quote_no' => 'Q-A', 'customer_phone' => '1',
            'currency' => 'UGX', 'total' => 1, 'status' => 'sent', 'meta' => ['project' => ['is_project' => true]]]);
        $this->assertSame(1, Quotation::count());

        $this->boot();
        $this->assertSame(0, Quotation::count());
    }
}
