<?php

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E4.6 — retire the legacy ledger_entries order_payment write.
 *
 * With pay_unify ON a cashbook "Record payment" is recorded ONCE, in the unified CustomerPayment
 * ledger (mirrored 1:1 by a cash-in OrderCost) — the duplicate ledger_entries copy is no longer
 * written. With the flag OFF the legacy write is preserved verbatim and no unified row is created.
 * Either way the money reconciles: CustomerPayment USD == its mirrored OrderCost USD.
 */
class PaymentRetireTest extends TestCase
{
    use RefreshDatabase;

    private function make(bool $payUnify): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('pay_unify', $payUnify);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-77', 'customer_name' => 'Asish',
            'customer_phone' => '211900', 'total' => 300000, 'amount_paid' => 0, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
        return $t;
    }

    public function test_flag_on_writes_unified_ledger_only_no_legacy_order_payment(): void
    {
        $t = $this->make(true);
        $this->postJson('/papi/record-payment', ['order' => 'ORD-77', 'amount' => 100000, 'notify' => '0'])->assertOk();

        // The legacy ledger_entries order_payment copy is retired.
        $this->assertSame(0, LedgerEntry::where('category', 'order_payment')->count(),
            'pay_unify ON should NOT write the duplicate ledger_entries order_payment row');

        // The unified ledger recorded it exactly once, and it reconciles.
        $pay = CustomerPayment::where('tenant_id', $t->id)->where('order_no', 'ORD-77')->first();
        $this->assertNotNull($pay);
        $this->assertNotNull($pay->order_cost_id);
        $cost = OrderCost::find($pay->order_cost_id);
        $this->assertNotNull($cost);
        $this->assertSame('customer_payment', $cost->stage);
        $this->assertEqualsWithDelta((float) $pay->usd_amount, (float) $cost->usd_amount, 0.001,
            'CustomerPayment USD must equal its mirrored OrderCost receipt USD');

        // amount_paid still moves (single-counted).
        $this->assertEqualsWithDelta(100000.0, (float) Order::where('order_no', 'ORD-77')->value('amount_paid'), 0.5);
    }

    public function test_flag_off_keeps_legacy_write_and_no_unified_row(): void
    {
        $t = $this->make(false);
        $this->postJson('/papi/record-payment', ['order' => 'ORD-77', 'amount' => 100000, 'notify' => '0'])->assertOk();

        $this->assertSame(1, LedgerEntry::where('category', 'order_payment')->count(),
            'pay_unify OFF should preserve the legacy ledger_entries write verbatim');
        $this->assertSame(0, CustomerPayment::where('tenant_id', $t->id)->count(),
            'flag off => no unified dual-write');
        $this->assertEqualsWithDelta(100000.0, (float) Order::where('order_no', 'ORD-77')->value('amount_paid'), 0.5);
    }
}
