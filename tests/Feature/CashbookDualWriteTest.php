<?php

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E4.4 — the cashbook "Record payment" (PanelApiController::recordPayment) dual-writes into
 * the ONE payment ledger (CustomerPayment) when pay_unify is on, so the payment is visible in
 * Payments / the trade cashbook / statements — not only the legacy ledger_entries table. With the
 * flag off, only the legacy path runs (no CustomerPayment).
 */
class CashbookDualWriteTest extends TestCase
{
    use RefreshDatabase;

    private function make(bool $payUnify): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('pay_unify', $payUnify);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-55', 'customer_name' => 'Asish',
            'customer_phone' => '211900', 'total' => 300000, 'amount_paid' => 0, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
        return $t;
    }

    public function test_dual_write_creates_customer_payment_when_flag_on(): void
    {
        $t = $this->make(true);
        $this->postJson('/papi/record-payment', ['order' => 'ORD-55', 'amount' => 100000, 'notify' => '0'])->assertOk();

        $pay = CustomerPayment::where('tenant_id', $t->id)->where('order_no', 'ORD-55')->first();
        $this->assertNotNull($pay, 'cashbook payment should also land in the unified ledger');
        $this->assertSame('settlement', $pay->kind);
        $this->assertNotNull($pay->order_cost_id);
    }

    public function test_flag_off_keeps_legacy_only(): void
    {
        $t = $this->make(false);
        $this->postJson('/papi/record-payment', ['order' => 'ORD-55', 'amount' => 100000, 'notify' => '0'])->assertOk();

        $this->assertSame(0, CustomerPayment::where('tenant_id', $t->id)->count(), 'flag off => no dual-write');
    }
}
