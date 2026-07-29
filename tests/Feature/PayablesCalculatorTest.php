<?php

namespace Tests\Feature;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Finance\PayablesCalculator;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supplier payables must net off supplier-payment rows (they live separately and never touch
 * paid_native). Regression guard for the "you owe" overstatement bug: a supplier billed 5,667.03
 * and paid 5,128.59 should read 538.44 owed — not the gross 5,667.03.
 */
class PayablesCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private int $tid;

    protected function setUp(): void
    {
        parent::setUp();
        $t = Tenant::factory()->create();
        $this->tid = $t->id;
        app(TenantContext::class)->set($t->id);
    }

    private function bill(int $sid, float $usd): void
    {
        OrderCost::create(['tenant_id' => $this->tid, 'supplier_id' => $sid, 'stage' => 'purchase',
            'pay_status' => 'credit', 'paid_native' => 0, 'currency' => 'UGX',
            'native_amount' => $usd * 3703, 'rate_to_usd' => 3703, 'usd_amount' => $usd, 'status' => 'approved']);
    }

    private function payment(int $sid, float $usd): void
    {
        OrderCost::create(['tenant_id' => $this->tid, 'supplier_id' => $sid, 'stage' => 'supplier_payment',
            'pay_status' => 'cash', 'currency' => 'UGX',
            'native_amount' => $usd * 3703, 'rate_to_usd' => 3703, 'usd_amount' => $usd, 'status' => 'approved']);
    }

    public function test_payments_are_subtracted_from_bills(): void
    {
        $this->bill(1, 5667.03);
        $this->payment(1, 5128.59);

        $this->assertEqualsWithDelta(538.44, PayablesCalculator::totalUsd($this->tid), 0.01);
    }

    public function test_overpaid_supplier_floors_at_zero(): void
    {
        $this->bill(2, 200.00);
        $this->payment(2, 1000.00);   // overpaid

        $this->assertSame(0.0, PayablesCalculator::totalUsd($this->tid));
    }

    public function test_voided_rows_are_ignored(): void
    {
        $this->bill(3, 500.00);
        OrderCost::create(['tenant_id' => $this->tid, 'supplier_id' => 3, 'stage' => 'supplier_payment',
            'currency' => 'UGX', 'native_amount' => 500 * 3703, 'rate_to_usd' => 3703, 'usd_amount' => 500, 'status' => 'void']);

        // the void payment must NOT reduce the bill
        $this->assertEqualsWithDelta(500.00, PayablesCalculator::totalUsd($this->tid), 0.01);
    }

    public function test_ageing_totals_equal_the_total(): void
    {
        $this->bill(1, 5667.03);
        $this->payment(1, 5128.59);
        $aged = PayablesCalculator::agedUsd($this->tid, fn ($d) => 'Current');
        $this->assertEqualsWithDelta(538.44, array_sum($aged), 0.01);
    }

    public function test_by_supplier_nets_payments_per_supplier(): void
    {
        // Supplier 1 (AVION-like): billed then partly paid → net owed. Supplier 2: unpaid.
        $this->bill(1, 2787.40);
        $this->payment(1, 1617.28);
        $this->bill(2, 500.00);

        $map = PayablesCalculator::bySupplierUsd($this->tid);
        $this->assertEqualsWithDelta(1170.12, $map[1], 0.01);   // NOT the gross 2787.40
        $this->assertEqualsWithDelta(500.00, $map[2], 0.01);
    }

    public function test_by_supplier_is_negative_when_paid_before_billed(): void
    {
        // Exactly the AVION-before-bills state: payments recorded, no bills yet → supplier owes YOU.
        $this->payment(7, 1617.28);
        $map = PayablesCalculator::bySupplierUsd($this->tid);
        $this->assertEqualsWithDelta(-1617.28, $map[7], 0.01);
    }
}
