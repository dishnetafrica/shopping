<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Warranty\WarrantyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3b — a product's DEFAULT warranty policy (manufacturer + company months) surfaces on the V3 product
 * detail page and is editable there, and the page shows a live count of active warranties on the
 * product — all through the capability payload (no front-end change). Ordinary consumer goods (not
 * serialized, no policy) are unchanged.
 */
class ProductWarrantyTermsTest extends TestCase
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

    public function test_serialized_product_shows_warranty_terms_and_action(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true,
            'warranty_manufacturer_months' => 24, 'warranty_company_months' => 12]);

        $j = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();

        $answers = collect($j['answers'] ?? []);
        $this->assertSame('24 months', $answers->firstWhere('q', 'Manufacturer warranty')['value']);
        $this->assertSame('12 months', $answers->firstWhere('q', 'Company warranty')['value']);

        $this->assertTrue(
            collect($j['actions'] ?? [])->contains(fn ($a) => ($a['key'] ?? '') === 'set_warranty'),
            'a Set warranty terms action should be offered on a serialized product'
        );
    }

    public function test_serialized_product_without_terms_shows_dashes(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Battery', 'price' => 300, 'sku' => 'BAT', 'serialized' => true]);

        $answers = collect($this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json('answers'));
        $this->assertSame('—', $answers->firstWhere('q', 'Manufacturer warranty')['value']);
        $this->assertSame('—', $answers->firstWhere('q', 'Company warranty')['value']);
    }

    public function test_ordinary_product_shows_no_warranty(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Bread', 'price' => 5, 'sku' => 'BRD']);   // not serialized, no policy

        $j = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();
        $answers = collect($j['answers'] ?? []);
        $this->assertNull($answers->firstWhere('q', 'Manufacturer warranty'), 'consumer goods carry no warranty terms');
        $this->assertFalse(collect($j['actions'] ?? [])->contains(fn ($a) => ($a['key'] ?? '') === 'set_warranty'));
    }

    public function test_set_warranty_command_persists_and_clears(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Panel', 'price' => 200, 'sku' => 'PNL', 'serialized' => true]);

        $this->postJson('/api/v1/workspace/product/' . $p->id . '/set_warranty', [
            'warranty_manufacturer_months' => '25', 'warranty_company_months' => '10',
        ])->assertOk();

        $p->refresh();
        $this->assertSame(25, $p->warranty_manufacturer_months);
        $this->assertSame(10, $p->warranty_company_months);

        // Blank / zero clears the policy back to "no term" rather than storing 0.
        $this->postJson('/api/v1/workspace/product/' . $p->id . '/set_warranty', [
            'warranty_manufacturer_months' => '', 'warranty_company_months' => '0',
        ])->assertOk();

        $p->refresh();
        $this->assertNull($p->warranty_manufacturer_months);
        $this->assertNull($p->warranty_company_months);
    }

    public function test_active_warranty_count_shows_as_stat(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true]);

        $svc = WarrantyService::forTenant($t);
        $svc->activate(['product_id' => $p->id, 'company_months' => 12, 'customer_phone' => '211900']);  // active
        $expired = $svc->activate(['product_id' => $p->id, 'warranty_start' => '2019-01-01', 'company_months' => 12]);
        $svc->void($svc->activate(['product_id' => $p->id, 'company_months' => 12]));                    // voided

        $stats = collect($this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json('stats'));
        $active = $stats->firstWhere('label', 'Active warranties');
        $this->assertNotNull($active, 'an active-warranty count should appear');
        $this->assertSame('1', $active['value'], 'only the live warranty counts (expired and voided excluded)');
    }
}
