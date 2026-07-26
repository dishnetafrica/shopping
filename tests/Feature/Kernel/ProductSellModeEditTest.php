<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sell-mode toggle from the product edit screen (not just the CSV importer). Proves the update-product
 * endpoint switches a product between 'buy' and 'quote', validates the value (bad input falls back to
 * 'buy'), and that the products-list payload exposes Sell_Mode so the edit dialog can pre-select the
 * product's current mode.
 */
class ProductSellModeEditTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8),
            'settings' => ['vertical' => 'solar']]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    public function test_update_product_switches_sell_mode_both_ways(): void
    {
        $this->boot();
        $p = Product::create(['name' => '5kVA Hybrid Kit', 'category' => 'Solar',
            'price' => 1000, 'base_price' => 1000, 'stock' => 2, 'sell_mode' => 'buy']);

        $this->get('/papi/update-product?row=' . $p->id . '&sell_mode=quote')
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('quote', $p->fresh()->sell_mode);

        $this->get('/papi/update-product?row=' . $p->id . '&sell_mode=buy')
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('buy', $p->fresh()->sell_mode);
    }

    public function test_bad_sell_mode_falls_back_to_buy(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Panel', 'category' => 'Solar',
            'price' => 100, 'base_price' => 100, 'stock' => 5, 'sell_mode' => 'quote']);

        $this->get('/papi/update-product?row=' . $p->id . '&sell_mode=nonsense')->assertOk();
        $this->assertSame('buy', $p->fresh()->sell_mode);
    }

    public function test_editing_other_fields_leaves_sell_mode_untouched(): void
    {
        $this->boot();
        $p = Product::create(['name' => 'Inverter', 'category' => 'Solar',
            'price' => 900, 'base_price' => 900, 'stock' => 3, 'sell_mode' => 'quote']);

        // No sell_mode param — a plain price edit must not reset the mode.
        $this->get('/papi/update-product?row=' . $p->id . '&price=950')->assertOk();
        $this->assertSame('quote', $p->fresh()->sell_mode);
    }

    public function test_products_payload_exposes_sell_mode(): void
    {
        $this->boot();
        Product::create(['name' => 'Quote Kit', 'category' => 'Solar',
            'price' => 5000, 'base_price' => 5000, 'stock' => 1, 'sell_mode' => 'quote']);

        $rows = $this->get('/papi/products')->assertOk()->json('products');
        $this->assertNotEmpty($rows);
        $found = collect($rows)->firstWhere('Product Name', 'Quote Kit');
        $this->assertNotNull($found);
        $this->assertSame('quote', $found['Sell_Mode']);
    }
}
