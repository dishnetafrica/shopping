<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\KitService;
use App\Services\Inventory\OrderStockIssuer;
use App\Services\Inventory\StockLedger;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4b — selling a kit draws its PARTS down from stock (the customer document still shows the kit as one
 * line). Proves: lines() explodes a kit into its parts scaled by kit quantity; non-kit lines pass
 * through; when stock issuing is on, delivering an order with a kit deducts each component and reversing
 * restores it, while the kit's own stock never moves. The invoicing/pricing engine is untouched.
 */
class KitStockIssueTest extends TestCase
{
    use RefreshDatabase;

    private function boot(array $settings = []): Tenant
    {
        $t = Tenant::create([
            'name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8),
            'settings' => $settings,
        ]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    /** @return array{0:Product,1:Product,2:Product,3:Product} kit, inverter, panel, battery */
    private function kitOfThree(Tenant $t): array
    {
        $kit = Product::create(['name' => '3kW Solar Kit', 'price' => 5_000_000, 'sku' => 'KIT-3KW']);
        $inv = Product::create(['name' => 'Inverter', 'price' => 900_000, 'sku' => 'INV']);
        $pan = Product::create(['name' => 'Panel 550W', 'price' => 200_000, 'sku' => 'PNL']);
        $bat = Product::create(['name' => 'Battery', 'price' => 300_000, 'sku' => 'BAT']);
        KitService::forTenant($t)->setComponents($kit->id, [
            ['component_product_id' => $inv->id, 'quantity' => 1],
            ['component_product_id' => $pan->id, 'quantity' => 8],
            ['component_product_id' => $bat->id, 'quantity' => 4],
        ]);
        return [$kit, $inv, $pan, $bat];
    }

    private function deliveredOrder(Tenant $t): Order
    {
        return Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-' . substr(md5(uniqid('', true)), 0, 6),
            'customer_name' => 'Asish', 'customer_phone' => '211900',
            'total' => 5_000_000, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
    }

    public function test_lines_explodes_kit_into_scaled_parts(): void
    {
        $t = $this->boot();
        [$kit, $inv, $pan, $bat] = $this->kitOfThree($t);
        $order = $this->deliveredOrder($t);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $kit->id, 'name' => '3kW Solar Kit', 'price' => 5_000_000, 'qty' => 2]);

        $lines = OrderStockIssuer::lines($order);
        $byId = collect($lines)->keyBy('product_id');

        $this->assertFalse($byId->has($kit->id), 'the kit line itself is replaced by its parts');
        $this->assertSame(2.0, (float) $byId[$inv->id]['qty']);    // 1 × 2 kits
        $this->assertSame(16.0, (float) $byId[$pan->id]['qty']);   // 8 × 2 kits
        $this->assertSame(8.0, (float) $byId[$bat->id]['qty']);    // 4 × 2 kits
    }

    public function test_non_kit_lines_pass_through(): void
    {
        $t = $this->boot();
        [$kit, $inv, $pan, $bat] = $this->kitOfThree($t);
        $loose = Product::create(['name' => 'Extension cable', 'price' => 50_000, 'sku' => 'EXT']);
        $order = $this->deliveredOrder($t);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $kit->id, 'name' => '3kW Solar Kit', 'price' => 5_000_000, 'qty' => 1]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $loose->id, 'name' => 'Extension cable', 'price' => 50_000, 'qty' => 3]);

        $byId = collect(OrderStockIssuer::lines($order))->keyBy('product_id');
        $this->assertSame(3.0, (float) $byId[$loose->id]['qty'], 'the standalone product is unchanged');
        $this->assertSame(1.0, (float) $byId[$inv->id]['qty']);   // kit exploded alongside it
        $this->assertFalse($byId->has($kit->id));
    }

    public function test_order_without_kits_is_unchanged(): void
    {
        $t = $this->boot();
        $p = Product::create(['name' => 'Cable', 'price' => 20_000, 'sku' => 'CBL']);
        $order = $this->deliveredOrder($t);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'name' => 'Cable', 'price' => 20_000, 'qty' => 5]);

        $lines = OrderStockIssuer::lines($order);
        $this->assertCount(1, $lines);
        $this->assertSame($p->id, (int) $lines[0]['product_id']);
        $this->assertSame(5.0, (float) $lines[0]['qty']);
    }

    public function test_delivery_deducts_components_and_reverse_restores(): void
    {
        // Ledger inventory ON (stock_v2 + stock_ledger) so OrderStockIssuer is active.
        $t = $this->boot(['stock_v2' => true, 'stock_ledger' => true]);
        [$kit, $inv, $pan, $bat] = $this->kitOfThree($t);

        // Opening stock for the parts.
        $sl = StockLedger::forTenant($t);
        $sl->receive(['product_id' => $inv->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => 10]);
        $sl->receive(['product_id' => $pan->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => 100]);
        $sl->receive(['product_id' => $bat->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => 20]);

        $order = $this->deliveredOrder($t);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $kit->id, 'name' => '3kW Solar Kit', 'price' => 5_000_000, 'qty' => 2]);

        $issuer = new OrderStockIssuer();
        $this->assertTrue(OrderStockIssuer::active($t), 'ledger issuing should be active for this tenant');

        $issuer->issueForOrder($t, $order);
        $this->assertEqualsWithDelta(8.0,  $sl->onHandFor($inv->id, null, StockLedger::LOC_KAMPALA), 0.001);   // 10 − (1×2)
        $this->assertEqualsWithDelta(84.0, $sl->onHandFor($pan->id, null, StockLedger::LOC_KAMPALA), 0.001);   // 100 − (8×2)
        $this->assertEqualsWithDelta(12.0, $sl->onHandFor($bat->id, null, StockLedger::LOC_KAMPALA), 0.001);   // 20 − (4×2)
        $this->assertEqualsWithDelta(0.0,  $sl->onHandFor($kit->id, null, StockLedger::LOC_KAMPALA), 0.001);   // kit itself never moves

        $issuer->reverseForOrder($t, $order);
        $this->assertEqualsWithDelta(10.0,  $sl->onHandFor($inv->id, null, StockLedger::LOC_KAMPALA), 0.001);
        $this->assertEqualsWithDelta(100.0, $sl->onHandFor($pan->id, null, StockLedger::LOC_KAMPALA), 0.001);
        $this->assertEqualsWithDelta(20.0,  $sl->onHandFor($bat->id, null, StockLedger::LOC_KAMPALA), 0.001);
    }

    public function test_issue_is_idempotent_for_kits(): void
    {
        $t = $this->boot(['stock_v2' => true, 'stock_ledger' => true]);
        [$kit, $inv] = $this->kitOfThree($t);
        $sl = StockLedger::forTenant($t);
        $sl->receive(['product_id' => $inv->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => 10]);

        $order = $this->deliveredOrder($t);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $kit->id, 'name' => '3kW Solar Kit', 'price' => 5_000_000, 'qty' => 2]);

        $issuer = new OrderStockIssuer();
        $issuer->issueForOrder($t, $order);
        $issuer->issueForOrder($t, $order);   // second call must not double-deduct

        $this->assertEqualsWithDelta(8.0, $sl->onHandFor($inv->id, null, StockLedger::LOC_KAMPALA), 0.001);
    }
}
