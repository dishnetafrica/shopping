<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Inventory\SerialService;
use App\Services\Warranty\WarrantyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3a — the warranty object. Proves: activation attaches to a serial (and links the unit back) or to
 * a non-serialized invoice line; warranty_end computes from the longer term; status is derived
 * (active/expired/void); void works; expiring-soon lists coverage ending within N days; the API
 * activates/lists/voids; writes are owner/manager-only; tenant-isolated.
 */
class WarrantyTest extends TestCase
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

    private function events(Tenant $t, string $name): int
    {
        return Event::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', $name)->count();
    }

    public function test_activate_on_serial_links_unit_and_computes_end(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV']);
        $unit = SerialService::forTenant($t)->register($p->id, 'SN-1');

        $w = WarrantyService::forTenant($t)->activate([
            'product_id' => $p->id, 'inventory_unit_id' => $unit->id,
            'customer_name' => 'Asish', 'customer_phone' => '211900',
            'warranty_start' => '2026-01-01', 'manufacturer_months' => 24, 'company_months' => 12,
            'manufacturer' => 'Victron',
        ]);

        $this->assertSame('active', $w->status);
        $this->assertSame('SN-1', $w->serial);                                   // denormalized from the unit
        $this->assertSame('2028-01-01', $w->warranty_end->toDateString());       // start + max(24,12) months
        $this->assertSame($w->id, (int) $unit->fresh()->warranty_id);            // unit linked back
        $this->assertSame(1, $this->events($t, 'WarrantyActivated'));
    }

    public function test_activate_on_invoice_line_without_serial(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Solar cable', 'price' => 20, 'sku' => 'CBL']);

        $w = WarrantyService::forTenant($t)->activate([
            'product_id' => $p->id, 'invoice_id' => 555, 'invoice_item_id' => 999,
            'company_months' => 6, 'customer_phone' => '211901',
        ]);

        $this->assertNull($w->inventory_unit_id);
        $this->assertSame(555, (int) $w->invoice_id);
        $this->assertNotNull($w->warranty_end);
    }

    public function test_status_is_derived(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Battery', 'price' => 300, 'sku' => 'BAT']);
        $svc = WarrantyService::forTenant($t);

        $expired = $svc->activate(['product_id' => $p->id, 'warranty_start' => '2020-01-01', 'company_months' => 12]);
        $this->assertSame('expired', WarrantyService::effectiveStatus($expired));

        $active = $svc->activate(['product_id' => $p->id, 'warranty_start' => now()->toDateString(), 'company_months' => 24]);
        $this->assertSame('active', WarrantyService::effectiveStatus($active));

        $svc->void($active);
        $this->assertSame('void', WarrantyService::effectiveStatus($active->fresh()));
        $this->assertSame(1, $this->events($t, 'WarrantyVoided'));
    }

    public function test_expiring_soon(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Panel', 'price' => 200, 'sku' => 'PNL']);
        $svc = WarrantyService::forTenant($t);
        $svc->activate(['product_id' => $p->id, 'warranty_end' => now()->addDays(10)->toDateString()]);   // soon
        $svc->activate(['product_id' => $p->id, 'warranty_end' => now()->addDays(200)->toDateString()]);  // later

        $this->assertCount(1, $svc->expiringSoon(30));
    }

    public function test_api_activate_list_and_void(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV']);

        $w = $this->postJson('/papi/warranties', [
            'product_id' => $p->id, 'customer_phone' => '211900', 'company_months' => 12,
        ])->assertOk()->assertJson(['ok' => true])->json('warranty');

        $this->assertSame('active', $w['effective_status']);

        $list = $this->getJson('/papi/warranties?product_id=' . $p->id)->assertOk()->json('warranties');
        $this->assertCount(1, $list);

        $this->postJson('/papi/warranties/' . $w['id'] . '/void')->assertOk();
        $this->assertSame('void', Warranty::find($w['id'])->status);
    }

    public function test_writes_are_manager_only(): void
    {
        $t = $this->boot('staff');
        $p = Product::create(['name' => 'X', 'price' => 1, 'sku' => 'X']);
        $this->postJson('/papi/warranties', ['product_id' => $p->id])->assertStatus(403);
    }

    public function test_tenant_isolated(): void
    {
        $a = $this->boot();
        WarrantyService::forTenant($a)->activate(['customer_phone' => '1']);
        $this->assertSame(1, Warranty::count());

        $this->boot();
        $this->assertSame(0, Warranty::count());
    }
}
