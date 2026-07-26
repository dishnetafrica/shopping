<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

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
 * S3c — when a serialized unit is marked sold (or installed), it auto-inherits its product's DEFAULT
 * warranty policy (S3b). Proves: a warranty is created with the product's terms and linked back to the
 * unit; products with no policy create nothing; it never double-creates; installing also triggers; an
 * explicitly-assigned warranty is respected. All tenant-scoped, fully guarded.
 */
class SerialAutoWarrantyTest extends TestCase
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

    public function test_selling_a_serial_auto_creates_warranty_from_product_terms(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'brand' => 'Victron', 'serialized' => true,
            'warranty_manufacturer_months' => 24, 'warranty_company_months' => 12]);
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'SN-1');

        $svc->transition($unit, 'sold', ['customer_id' => 77]);

        $this->assertSame(1, Warranty::count());
        $w = Warranty::first();
        $this->assertSame($p->id, (int) $w->product_id);
        $this->assertSame($unit->id, (int) $w->inventory_unit_id);
        $this->assertSame('SN-1', $w->serial);
        $this->assertSame(24, (int) $w->manufacturer_months);
        $this->assertSame(12, (int) $w->company_months);
        $this->assertSame('Victron', $w->manufacturer);
        $this->assertSame(77, (int) $w->customer_id);
        $this->assertSame('active', WarrantyService::effectiveStatus($w));
        $this->assertSame($w->id, (int) $unit->fresh()->warranty_id);   // unit linked back
    }

    public function test_no_policy_no_auto_warranty(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Cable', 'price' => 20, 'sku' => 'CBL', 'serialized' => true]);   // no terms
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'C-1');

        $svc->transition($unit, 'sold');

        $this->assertSame(0, Warranty::count());
        $this->assertNull($unit->fresh()->warranty_id);
    }

    public function test_never_double_creates(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Battery', 'price' => 300, 'sku' => 'BAT', 'serialized' => true,
            'warranty_company_months' => 24]);
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'B-1');

        $svc->transition($unit, 'sold');            // creates #1
        $svc->transition($unit->fresh(), 'returned');
        $svc->transition($unit->fresh(), 'sold');   // must NOT create a second

        $this->assertSame(1, Warranty::count());
    }

    public function test_installing_also_triggers(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Panel', 'price' => 200, 'sku' => 'PNL', 'serialized' => true,
            'warranty_manufacturer_months' => 120]);
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'P-1');

        $svc->transition($unit, 'installed');

        $this->assertSame(1, Warranty::count());
        $this->assertSame(120, (int) Warranty::first()->manufacturer_months);
    }

    public function test_explicit_warranty_assignment_is_respected(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true,
            'warranty_manufacturer_months' => 24]);
        // A real warranty created up front, then attached during the sale transition.
        $existing = WarrantyService::forTenant($t)->activate(['product_id' => $p->id, 'company_months' => 6]);
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'SN-9');

        $svc->transition($unit, 'sold', ['warranty_id' => $existing->id]);

        $this->assertSame(1, Warranty::count(), 'the pre-assigned warranty is kept; no auto one is added');
        $this->assertSame($existing->id, (int) $unit->fresh()->warranty_id);
    }

    public function test_intermediate_statuses_do_not_trigger(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV', 'serialized' => true,
            'warranty_manufacturer_months' => 24]);
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'SN-5');

        $svc->transition($unit, 'reserved');
        $this->assertSame(0, Warranty::count(), 'reserving does not start warranty coverage');
    }
}
