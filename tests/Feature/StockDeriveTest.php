<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roadmap E6.5 — products.stock becomes a DERIVED cache of the ledger projection. The reconcile
 * derives the column from the ledger truth, and CRUCIALLY never zeros a product that has no ledger
 * movements (so it can't wipe stock for rows whose opening balance hasn't been seeded).
 */
class StockDeriveTest extends TestCase
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

    public function test_reconcile_derives_column_from_projection(): void
    {
        $t = $this->tenant(true, true);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Rice', 'sku' => 'RICE', 'stock' => 100, 'price' => 10]);
        // observer wrote IN 100; push projection to 70
        StockLedger::forTenant($t)->adjust(['product_id' => $p->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => -30, 'ref_type' => 'test']);

        $count = StockLedger::forTenant($t)->syncProductStockCache();
        $this->assertSame(1, $count);
        $this->assertSame(70, (int) DB::table('products')->where('id', $p->id)->value('stock'));
    }

    public function test_reconcile_never_zeros_a_product_absent_from_the_ledger(): void
    {
        $t = $this->tenant(true, true);
        // a product with NO ledger movements (e.g. bulk-inserted before a re-backfill): stock 55
        DB::table('products')->insert([
            'tenant_id' => $t->id, 'name' => 'Ghost', 'sku' => 'GHOST', 'stock' => 55, 'price' => 1,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, StockMovement::where('tenant_id', $t->id)->count());

        StockLedger::forTenant($t)->syncProductStockCache();
        // untouched — NOT zeroed
        $this->assertSame(55, (int) DB::table('products')->where('sku', 'GHOST')->value('stock'));
    }

    public function test_endpoint_requires_stock_v2(): void
    {
        $t = $this->tenant(true, false); // ledger on, reads off
        $this->postJson('/papi/stock/reconcile-cache')->assertStatus(422);
    }

    public function test_endpoint_reconciles_when_enabled(): void
    {
        $t = $this->tenant(true, true);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Oil', 'sku' => 'OIL', 'stock' => 40, 'price' => 8]);
        StockLedger::forTenant($t)->adjust(['product_id' => $p->id, 'location' => StockLedger::LOC_KAMPALA, 'qty' => 5, 'ref_type' => 'test']);

        $this->postJson('/papi/stock/reconcile-cache')->assertOk()->assertJson(['ok' => true, 'reconciled' => 1]);
        $this->assertSame(45, (int) DB::table('products')->where('id', $p->id)->value('stock')); // 40 + 5
    }
}
