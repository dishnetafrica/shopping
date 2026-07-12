<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E6.4 — on-hand reads switch to the stock-ledger projection when a tenant opts into ledger
 * reads (stock_v2 + stock_ledger); otherwise the legacy `products.stock` column is used. Requiring
 * BOTH flags means on-hand is never read from a ledger that isn't being maintained.
 */
class StockReadV2Test extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $ledger, bool $v2): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('stock_ledger', $ledger);
        $t->putSetting('stock_v2', $v2);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        return $t;
    }

    /** Product with column stock 100 but a ledger projection of 70 (so the two are distinguishable). */
    private function productWithDivergentLedger(Tenant $t): Product
    {
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Rice', 'sku' => 'RICE', 'stock' => 100, 'price' => 10]);
        // observer already wrote IN 100 (stock_ledger on); push projection to 70 with an out-of-band adjust
        StockLedger::forTenant($t)->adjust([
            'product_id' => $p->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => -30, 'ref_type' => 'test',
        ]);
        return $p;
    }

    public function test_reads_from_ledger_when_both_flags_on(): void
    {
        $t = $this->tenant(true, true);
        $p = $this->productWithDivergentLedger($t);

        $this->assertTrue(StockLedger::readsFromLedger($t));
        $this->assertSame(70.0, $p->onHand());          // projection
        $this->assertSame(100, (int) $p->fresh()->stock); // column untouched
    }

    public function test_reads_from_column_when_v2_off(): void
    {
        $t = $this->tenant(true, false); // ledger maintained, but reads NOT switched
        $p = $this->productWithDivergentLedger($t);

        $this->assertFalse(StockLedger::readsFromLedger($t));
        $this->assertSame(100.0, $p->onHand()); // legacy column
    }

    public function test_products_list_reflects_projection_under_flags(): void
    {
        $t = $this->tenant(true, true);
        $p = $this->productWithDivergentLedger($t);

        $res = $this->getJson('/papi/products')->assertOk()->json('products');
        $row = collect($res)->firstWhere('_row', $p->id);
        $this->assertNotNull($row);
        $this->assertSame(70, $row['Stock']); // projected on-hand, not the 100 column
    }

    public function test_products_list_uses_column_when_flags_off(): void
    {
        $t = $this->tenant(false, false);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Oil', 'sku' => 'OIL', 'stock' => 42, 'price' => 8]);

        $res = $this->getJson('/papi/products')->assertOk()->json('products');
        $row = collect($res)->firstWhere('_row', $p->id);
        $this->assertSame(42, $row['Stock']);
    }
}
