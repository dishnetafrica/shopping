<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\KitService;
use App\Services\Sales\ProjectQuoteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Lifecycle · Phase 2 · S8b — the V3 Project Quote builder & view. Proves: the Quotations list
 * offers a "New project quote" action once kits exist; and a project quote's detail shows one card per
 * priced section (instead of the flat line-items card). Reuses the existing Quotations screens — no new
 * capability or nav.
 */
class ProjectQuoteWorkspaceTest extends TestCase
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

    public function test_quotations_list_offers_new_project_quote_when_kits_exist(): void
    {
        $t = $this->boot();

        // No kits yet → no project-quote action.
        $before = collect($this->getJson('/api/v1/workspace/quotations')->assertOk()->json('actions'));
        $this->assertFalse($before->contains(fn ($a) => ($a['key'] ?? '') === 'new_project_quote'));

        $this->kit($t);

        $after = collect($this->getJson('/api/v1/workspace/quotations')->assertOk()->json('actions'));
        $this->assertTrue($after->contains(fn ($a) => ($a['key'] ?? '') === 'new_project_quote'),
            'once a kit exists, the Quotations screen offers "New project quote"');
    }

    public function test_project_quote_detail_shows_sections(): void
    {
        $t = $this->boot();
        $kit = $this->kit($t);

        $res = ProjectQuoteService::forTenant($t)->fromKits([
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'kit_ids' => [$kit->id],
        ]);
        $this->assertTrue($res['ok']);

        $cards = collect($this->getJson('/api/v1/workspace/quotation/' . $res['id'])->assertOk()->json('overviewCards'));
        $section = $cards->first(fn ($c) => ($c['title'] ?? '') === '3kW Solar Kit');
        $this->assertNotNull($section, 'the project quote detail shows a card for the kit section');
        $this->assertStringContainsString('Subtotal', (string) ($section['sub'] ?? ''));
        // The flat "Line items" card is replaced by the sectioned view for a project quote.
        $this->assertFalse($cards->contains(fn ($c) => ($c['title'] ?? '') === 'Line items'));
    }
}
