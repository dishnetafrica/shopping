<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\SerialService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2b — observe-first (D2) + reconciliation. Proves: delivering a serialized product without picked
 * serials logs a SerialSaleUnpicked observation (only for serialized lines, never a block); and
 * reconciliation reports the drift between in-stock serials and ledger on-hand.
 */
class SerialSaleObserveTest extends TestCase
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

    private function order(Tenant $t): Order
    {
        return Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-' . substr(md5(uniqid('', true)), 0, 6),
            'customer_name' => 'Asish', 'customer_phone' => '211900',
            'total' => 500, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
    }

    private function events(Tenant $t, string $name): int
    {
        return Event::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', $name)->count();
    }

    public function test_delivery_observes_only_serialized_lines(): void
    {
        $t = $this->boot();
        $serial = Product::create(['name' => 'Inverter', 'price' => 900, 'sku' => 'INV']);
        $plain  = Product::create(['name' => 'Cable roll', 'price' => 20, 'sku' => 'CBL']);
        SerialService::forTenant($t)->register($serial->id, 'SN-1'); // flags $serial serialized

        SerialService::forTenant($t)->observeDelivery($this->order($t), [
            ['product_id' => $serial->id, 'qty' => 2, 'name' => 'Inverter'],
            ['product_id' => $plain->id,  'qty' => 1, 'name' => 'Cable roll'],
        ]);

        // exactly one observation — for the serialized line only
        $this->assertSame(1, $this->events($t, 'SerialSaleUnpicked'));
    }

    public function test_reconcile_reports_drift(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Battery', 'price' => 300, 'sku' => 'BAT', 'stock' => 5]);
        $svc = SerialService::forTenant($t);
        $svc->bulkRegister($p->id, ['B1', 'B2', 'B3']);

        $r = $svc->reconcile($p->id);
        $this->assertEqualsWithDelta(5.0, $r['ledger_on_hand'], 0.001);
        $this->assertSame(3, $r['in_stock_serials']);
        $this->assertEqualsWithDelta(2.0, $r['drift'], 0.001);
        $this->assertFalse($r['reconciled']);

        $svc->bulkRegister($p->id, ['B4', 'B5']);
        $this->assertTrue($svc->reconcile($p->id)['reconciled']);
    }

    public function test_reconcile_endpoint(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Panel', 'price' => 200, 'sku' => 'PNL', 'stock' => 2]);
        SerialService::forTenant($t)->bulkRegister($p->id, ['S1', 'S2']);

        $this->getJson("/papi/products/{$p->id}/serials/reconcile")
            ->assertOk()
            ->assertJson(['ok' => true, 'in_stock_serials' => 2, 'reconciled' => true]);
    }
}
