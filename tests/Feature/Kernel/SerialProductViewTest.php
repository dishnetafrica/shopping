<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\SerialService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2c — serialized units surface on the product detail page (as 'stats' tiles) and can be registered
 * inline via the add_serials command, all through the capability payload (no front-end change).
 */
class SerialProductViewTest extends TestCase
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
        return $t;
    }

    public function test_product_detail_shows_serial_stats_and_add_action(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV']);
        $svc = SerialService::forTenant($t);
        $svc->bulkRegister($p->id, ['S1', 'S2', 'S3']);
        $svc->transition(InventoryUnit::where('serial', 'S3')->first(), 'sold');

        $j = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();

        $stats = collect($j['stats'] ?? []);
        $this->assertSame('3', $stats->firstWhere('label', 'Tracked units')['value']);
        $this->assertSame('2', $stats->firstWhere('label', 'In stock')['value']);
        $this->assertSame('1', $stats->firstWhere('label', 'Sold')['value']);

        $this->assertTrue(collect($j['actions'] ?? [])->contains(fn ($a) => ($a['key'] ?? '') === 'add_serials'));
    }

    public function test_non_serialized_product_shows_no_serial_stats(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Cable', 'price' => 20, 'sku' => 'CBL']);

        $j = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();
        $this->assertSame([], $j['stats'] ?? []);
    }

    public function test_add_serials_command_registers_from_free_text(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Battery', 'price' => 300, 'sku' => 'BAT']);

        $this->postJson('/api/v1/workspace/product/' . $p->id . '/add_serials', [
            'serials' => "B1, B2\nB3  B4",   // commas, newline, spaces
        ])->assertOk();

        $this->assertSame(4, InventoryUnit::where('product_id', $p->id)->count());
        $this->assertTrue((bool) $p->fresh()->serialized);
    }
}
