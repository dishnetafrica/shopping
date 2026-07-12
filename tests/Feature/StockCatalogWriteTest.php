<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E6.4 (prerequisite) — Kampala catalog stock changes mirror into the ONE stock ledger so
 * the projection stays correct once reads switch to it. A new product's stock is an opening IN; an
 * edit is a signed ADJUST; the CSV stock-sync (a raw bulk update that bypasses model events) mirrors
 * its own ADJUST. All gated on stock_ledger (default off).
 */
class StockCatalogWriteTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $flag): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('stock_ledger', $flag);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        return $t;
    }

    public function test_create_emits_opening_in(): void
    {
        $t = $this->tenant(true);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Rice', 'sku' => 'RICE', 'stock' => 50, 'price' => 10]);

        $mv = StockMovement::where('tenant_id', $t->id)->where('ref_type', 'product_create')->first();
        $this->assertNotNull($mv);
        $this->assertSame(StockLedger::IN, $mv->type);
        $this->assertSame(50.0, (float) $mv->qty);
        $this->assertSame(StockLedger::LOC_KAMPALA, $mv->location);
        $this->assertSame(50.0, $l = StockLedger::forTenant($t)->onHandFor($p->id, null, StockLedger::LOC_KAMPALA));
    }

    public function test_edit_emits_signed_adjust(): void
    {
        $t = $this->tenant(true);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Oil', 'sku' => 'OIL', 'stock' => 20, 'price' => 8]);
        $p->stock = 32;
        $p->save();

        $adj = StockMovement::where('tenant_id', $t->id)->where('ref_type', 'product_edit')->first();
        $this->assertNotNull($adj);
        $this->assertSame(StockLedger::ADJUST, $adj->type);
        $this->assertSame(12.0, (float) $adj->qty);
        // projection tracks the live stock: 20 opening + 12 adjust = 32
        $this->assertSame(32.0, StockLedger::forTenant($t)->onHandFor($p->id, null, StockLedger::LOC_KAMPALA));
    }

    public function test_flag_off_emits_nothing(): void
    {
        $t = $this->tenant(false);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'X', 'sku' => 'X', 'stock' => 5, 'price' => 1]);
        $p->stock = 9;
        $p->save();
        $this->assertSame(0, StockMovement::where('tenant_id', $t->id)->count());
    }

    public function test_csv_stock_sync_mirrors_adjust(): void
    {
        $t = $this->tenant(true);
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Rice', 'sku' => 'RICE', 'stock' => 100, 'price' => 10]);
        // clear the create-opening so we isolate the sync movement
        StockMovement::where('tenant_id', $t->id)->delete();

        $csv = "name,code,stock\nRice,RICE,80";
        $this->postJson('/papi/stock-sync', ['csv' => $csv, 'apply' => 1])->assertOk();

        $mv = StockMovement::where('tenant_id', $t->id)->where('ref_type', 'stock_sync')->first();
        $this->assertNotNull($mv);
        $this->assertSame(StockLedger::ADJUST, $mv->type);
        $this->assertSame(-20.0, (float) $mv->qty); // 100 -> 80
    }
}
