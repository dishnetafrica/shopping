<?php

namespace Tests\Feature;

use App\Models\ConsignmentStock;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockBackfill;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E6.3 — the backfill seeds the ledger so its projected on-hand equals today's numbers.
 * Acceptance: projected on-hand == current Product.stock per SKU (Kampala) and == each consignment
 * line's remaining quantity (Juba). Idempotent: a re-run adds nothing.
 */
class StockBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): Tenant
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u); // sets tenant context for model scopes

        Product::create(['tenant_id' => $t->id, 'name' => 'Rice', 'sku' => 'RICE', 'stock' => 120, 'price' => 10]);
        Product::create(['tenant_id' => $t->id, 'name' => 'Oil',  'sku' => 'OIL',  'stock' => 45,  'price' => 8]);
        Product::create(['tenant_id' => $t->id, 'name' => 'Zero', 'sku' => 'ZERO', 'stock' => 0,   'price' => 1]); // skipped

        $ship = Shipment::create(['tenant_id' => $t->id, 'shipment_number' => 'SHP-9', 'status' => 'arrived',
            'origin_city' => 'Kampala', 'destination_city' => 'Juba']);
        ConsignmentStock::create(['tenant_id' => $t->id, 'shipment_id' => $ship->id, 'product_id' => null, 'name' => 'Sugar',
            'qty_requested' => 100, 'qty_sent' => 100, 'qty_received' => 100, 'qty_sold' => 40]); // remaining 60

        return $t;
    }

    public function test_projection_matches_current_stock(): void
    {
        $t = $this->seed();
        (new StockBackfill())->runForTenant($t->id);

        $l = StockLedger::forTenant($t);
        foreach (Product::where('tenant_id', $t->id)->where('stock', '>', 0)->get() as $p) {
            $this->assertSame(
                (float) $p->stock,
                $l->onHandFor($p->id, null, StockLedger::LOC_KAMPALA),
                "projection should equal Product.stock for {$p->sku}"
            );
        }

        // consignment remaining (100 received - 40 sold = 60) at Juba
        $line = ConsignmentStock::where('tenant_id', $t->id)->first();
        $this->assertSame(60.0, $l->onHandFor(null, null, StockLedger::LOC_JUBA));
        $this->assertEqualsWithDelta(60.0, (float) $line->remaining(), 0.001);
    }

    public function test_zero_stock_products_are_skipped(): void
    {
        $t = $this->seed();
        (new StockBackfill())->runForTenant($t->id);
        $this->assertSame(0, StockMovement::where('tenant_id', $t->id)->where('sku', 'ZERO')->count());
    }

    public function test_backfill_is_idempotent(): void
    {
        $t = $this->seed();
        $first = (new StockBackfill())->runForTenant($t->id);
        $countAfterFirst = StockMovement::where('tenant_id', $t->id)->count();

        $second = (new StockBackfill())->runForTenant($t->id);
        $countAfterSecond = StockMovement::where('tenant_id', $t->id)->count();

        $this->assertSame(3, $first, '2 stocked products + 1 consignment line');
        $this->assertSame(0, $second, 're-run should insert nothing');
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}
