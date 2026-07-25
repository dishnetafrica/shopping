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
 * S1b — product specifications appear on the V3 product detail page and can be edited there, all via
 * the capability payload (no front-end change). Proves specs render in the 'answers' section, an
 * "Edit specifications" action is offered, and the set_specs command persists changes.
 */
class ProductSpecWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tenant,1:Product} */
    private function boot(): array
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        SolarPreset::seedSpecCatalogue($t);
        $p = Product::create(['name' => 'Panel 550', 'price' => 200]);
        ProductSpecService::forTenant($t)->setProductSpecs($p->id, ['wattage' => '550', 'battery_chemistry' => 'LiFePO4']);
        return [$t, $p];
    }

    public function test_product_detail_shows_specs_and_edit_action(): void
    {
        [, $p] = $this->boot();

        $j = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();

        $answers = collect($j['answers'] ?? []);
        $watt = $answers->firstWhere('q', 'Wattage (W)');
        $this->assertNotNull($watt, 'specs should render in the answers section');
        $this->assertSame('550', $watt['value']);
        $chem = $answers->firstWhere('q', 'Battery Chemistry');
        $this->assertSame('LiFePO4', $chem['value']);

        $this->assertTrue(
            collect($j['actions'] ?? [])->contains(fn ($a) => ($a['key'] ?? '') === 'set_specs'),
            'an Edit specifications action should be offered'
        );
    }

    public function test_set_specs_command_persists(): void
    {
        [, $p] = $this->boot();

        $this->postJson('/api/v1/workspace/product/' . $p->id . '/set_specs', [
            'wattage' => '600', 'battery_chemistry' => 'AGM',
        ])->assertOk();

        $answers = collect($this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json('answers'));
        $this->assertSame('600', $answers->firstWhere('q', 'Wattage (W)')['value']);
        $this->assertSame('AGM', $answers->firstWhere('q', 'Battery Chemistry')['value']);
    }

    public function test_product_without_specs_is_unaffected(): void
    {
        $this->boot();
        $bare = Product::create(['name' => 'Unspecced', 'price' => 50]);

        $answers = $this->getJson('/api/v1/workspace/product/' . $bare->id)->assertOk()->json('answers');
        $this->assertSame([], $answers ?? [], 'a product with no spec values shows no spec answers');
    }
}
