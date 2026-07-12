<?php

namespace Tests\Unit;

use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E6.1 — the one stock ledger. Proves IN/OUT/MOVE/ADJUST project to the correct on-hand per
 * SKU per location, that a MOVE conserves the company total, and that the ledger is append-only
 * (every event is a row; on-hand is a projection, never a mutated cell).
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): array
    {
        $t = Tenant::factory()->create();
        return [$t, StockLedger::forTenant($t)];
    }

    public function test_in_and_out_project_on_hand_per_sku_location(): void
    {
        [$t, $l] = $this->ledger();
        $l->receive(['sku' => 'RICE', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 100]);
        $l->issue(['sku' => 'RICE', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 30]);
        $l->receive(['sku' => 'RICE', 'location' => StockLedger::LOC_JUBA, 'qty' => 10]);

        $this->assertSame(70.0, $l->onHandFor(null, 'RICE', StockLedger::LOC_KAMPALA));
        $this->assertSame(10.0, $l->onHandFor(null, 'RICE', StockLedger::LOC_JUBA));
    }

    public function test_adjust_is_a_signed_correction(): void
    {
        [$t, $l] = $this->ledger();
        $l->receive(['sku' => 'OIL', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 50]);
        $l->adjust(['sku' => 'OIL', 'location' => StockLedger::LOC_KAMPALA, 'qty' => -4]); // shrinkage
        $l->adjust(['sku' => 'OIL', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 2]);  // found stock

        $this->assertSame(48.0, $l->onHandFor(null, 'OIL', StockLedger::LOC_KAMPALA));
    }

    public function test_move_transfers_between_locations_and_conserves_total(): void
    {
        [$t, $l] = $this->ledger();
        $l->receive(['sku' => 'SUGAR', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 80]);
        [$out, $in] = $l->move([
            'sku' => 'SUGAR', 'from' => StockLedger::LOC_KAMPALA, 'to' => StockLedger::LOC_JUBA, 'qty' => 30,
        ]);

        $this->assertSame(StockLedger::MOVE, $out->type);
        $this->assertSame(StockLedger::MOVE, $in->type);
        $this->assertSame(50.0, $l->onHandFor(null, 'SUGAR', StockLedger::LOC_KAMPALA));
        $this->assertSame(30.0, $l->onHandFor(null, 'SUGAR', StockLedger::LOC_JUBA));

        // company total across locations is unchanged by the move
        $total = array_sum(array_map(fn ($r) => $r['qty'], $l->onHand(['sku' => 'SUGAR'])));
        $this->assertSame(80.0, $total);
    }

    public function test_projection_matches_by_product_id(): void
    {
        [$t, $l] = $this->ledger();
        $l->receive(['product_id' => 7, 'sku' => 'X', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 12]);
        $l->issue(['product_id' => 7, 'sku' => 'X', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 5]);
        $this->assertSame(7.0, $l->onHandFor(7, null, StockLedger::LOC_KAMPALA));
    }

    public function test_ledger_is_append_only(): void
    {
        [$t, $l] = $this->ledger();
        $l->receive(['sku' => 'A', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 1]);
        $l->issue(['sku' => 'A', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 1]);
        // two events recorded even though net on-hand is zero
        $this->assertSame(2, StockMovement::where('tenant_id', $t->id)->count());
        $this->assertSame(0.0, $l->onHandFor(null, 'A', StockLedger::LOC_KAMPALA));
    }
}
