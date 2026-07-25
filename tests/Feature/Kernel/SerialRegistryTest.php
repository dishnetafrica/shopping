<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\SerialService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2a — the serialized-inventory registry. Proves: registering a serial creates an In-Stock unit,
 * flags the product serialized, and logs one SerialRegistered event; registration is idempotent per
 * serial; a status change updates the column (source of truth) and logs its audit event; the API
 * registers/lists/transitions; writes are owner/manager-only; and units are tenant-isolated.
 */
class SerialRegistryTest extends TestCase
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

    private function product(Tenant $t): Product
    {
        return Product::create(['name' => 'Inverter 3kW', 'price' => 900, 'sku' => 'INV-3K']);
    }

    private function events(Tenant $t, string $name): int
    {
        return Event::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', $name)->count();
    }

    public function test_register_creates_unit_flags_product_and_logs_event(): void
    {
        $t = $this->boot();
        $p = $this->product($t);

        $unit = SerialService::forTenant($t)->register($p->id, 'SN-001', ['supplier' => 'Victron']);

        $this->assertSame('in_stock', $unit->status);
        $this->assertNotEmpty($unit->qr_token);
        $this->assertTrue((bool) $p->fresh()->serialized, 'registering serials flags the product serialized');
        $this->assertSame(1, InventoryUnit::where('product_id', $p->id)->count());
        $this->assertSame(1, $this->events($t, 'SerialRegistered'));
    }

    public function test_register_is_idempotent_per_serial(): void
    {
        $t = $this->boot();
        $p = $this->product($t);
        $svc = SerialService::forTenant($t);

        $a = $svc->register($p->id, 'SN-DUP');
        $b = $svc->register($p->id, 'SN-DUP'); // same serial again

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, InventoryUnit::where('product_id', $p->id)->count());
        $this->assertSame(1, $this->events($t, 'SerialRegistered'));
    }

    public function test_transition_updates_status_and_logs_audit(): void
    {
        $t = $this->boot();
        $p = $this->product($t);
        $svc = SerialService::forTenant($t);
        $unit = $svc->register($p->id, 'SN-777');

        $svc->transition($unit, 'sold', ['customer_id' => 42]);

        $this->assertSame('sold', $unit->fresh()->status);
        $this->assertSame(42, (int) $unit->fresh()->customer_id);
        $this->assertSame(1, $this->events($t, 'SerialSold'));
    }

    public function test_invalid_status_is_rejected(): void
    {
        $t = $this->boot();
        $p = $this->product($t);
        $unit = SerialService::forTenant($t)->register($p->id, 'SN-BAD');

        $this->expectException(\InvalidArgumentException::class);
        SerialService::forTenant($t)->transition($unit, 'teleported');
    }

    public function test_api_register_list_and_transition(): void
    {
        $t = $this->boot();
        $p = $this->product($t);

        $this->postJson("/papi/products/{$p->id}/serials", ['serials' => ['A1', 'A2', 'A3']])
            ->assertOk()->assertJson(['ok' => true, 'registered' => 3]);

        $list = $this->getJson("/papi/products/{$p->id}/serials")->assertOk()->json();
        $this->assertCount(3, $list['units']);
        $this->assertSame(3, $list['counts']['in_stock']);

        $unitId = $list['units'][0]['id'];
        $this->postJson("/papi/serials/{$unitId}/status", ['status' => 'reserved'])->assertOk();
        $this->assertSame('reserved', InventoryUnit::find($unitId)->status);
    }

    public function test_writes_are_manager_only(): void
    {
        $t = $this->boot('staff');
        $p = $this->product($t);
        $this->postJson("/papi/products/{$p->id}/serials", ['serial' => 'X1'])->assertStatus(403);
    }

    public function test_units_are_tenant_isolated(): void
    {
        $a = $this->boot();
        $pa = $this->product($a);
        SerialService::forTenant($a)->register($pa->id, 'SN-A');
        $this->assertSame(1, InventoryUnit::count());

        $this->boot(); // second tenant
        $this->assertSame(0, InventoryUnit::count());
    }
}
