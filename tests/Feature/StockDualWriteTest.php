<?php

namespace Tests\Feature;

use App\Models\ConsignmentStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E6.2 — the real consignment stock events dual-write the ONE stock ledger when stock_ledger
 * is ON: a market sale is stock OUT of Juba, and receiving goods is stock IN. With the flag OFF the
 * existing paths are untouched (no movements). Nothing reads the ledger yet (E6.4), so this is a
 * low-risk additive write.
 */
class StockDualWriteTest extends TestCase
{
    use RefreshDatabase;

    private function setUpTenant(bool $stockLedger): array
    {
        $t = Tenant::factory()->create();
        $t->putSetting('stock_ledger', $stockLedger);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Asish']);
        $this->actingAs($u);

        $ship = Shipment::create([
            'tenant_id' => $t->id, 'shipment_number' => 'SHP-1', 'status' => 'arrived',
            'origin_city' => 'Kampala', 'destination_city' => 'Juba',
        ]);
        $line = ConsignmentStock::create([
            'tenant_id' => $t->id, 'shipment_id' => $ship->id, 'product_id' => null, 'name' => 'Rice 25kg',
            'qty_requested' => 100, 'qty_sent' => 100, 'qty_received' => 100, 'qty_sold' => 0,
            'unit_price' => 50, 'unit_cost' => 30, 'currency' => 'USD',
        ]);
        return [$t, $line];
    }

    public function test_sale_writes_out_movement_when_flag_on(): void
    {
        [$t, $line] = $this->setUpTenant(true);
        $this->postJson('/papi/consignment-stock-sell', ['line_id' => $line->id, 'qty' => 20, 'price' => 50])->assertOk();

        $mv = StockMovement::where('tenant_id', $t->id)->where('ref_type', 'consignment_sale')->first();
        $this->assertNotNull($mv);
        $this->assertSame(StockLedger::OUT, $mv->type);
        $this->assertSame(StockLedger::LOC_JUBA, $mv->location);
        $this->assertSame(-20.0, (float) $mv->qty);
        $this->assertSame('consignment_sale', $mv->ref_type);
    }

    public function test_receive_writes_in_movement_when_flag_on(): void
    {
        [$t, $line] = $this->setUpTenant(true);
        // raise received from 100 -> 130 (a +30 arrival)
        $this->postJson('/papi/consignment-stock-update', ['line_id' => $line->id, 'qty_received' => 130])->assertOk();

        $mv = StockMovement::where('tenant_id', $t->id)->where('ref_type', 'consignment_receive')->first();
        $this->assertNotNull($mv);
        $this->assertSame(StockLedger::IN, $mv->type);
        $this->assertSame(30.0, (float) $mv->qty);
    }

    public function test_downward_received_correction_is_an_adjust(): void
    {
        [$t, $line] = $this->setUpTenant(true);
        $this->postJson('/papi/consignment-stock-update', ['line_id' => $line->id, 'qty_received' => 90])->assertOk();

        $mv = StockMovement::where('tenant_id', $t->id)->where('ref_type', 'consignment_receive')->first();
        $this->assertNotNull($mv);
        $this->assertSame(StockLedger::ADJUST, $mv->type);
        $this->assertSame(-10.0, (float) $mv->qty);
    }

    public function test_flag_off_writes_no_movements(): void
    {
        [$t, $line] = $this->setUpTenant(false);
        $this->postJson('/papi/consignment-stock-sell', ['line_id' => $line->id, 'qty' => 20, 'price' => 50])->assertOk();
        $this->postJson('/papi/consignment-stock-update', ['line_id' => $line->id, 'qty_received' => 130])->assertOk();

        $this->assertSame(0, StockMovement::where('tenant_id', $t->id)->count(), 'flag off => no dual-write');
    }
}
