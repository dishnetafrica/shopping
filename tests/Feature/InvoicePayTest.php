<?php

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E4.2 — the invoice "Pay" button records through the SAME PaymentService as the seller
 * "Record Payment", so it writes a CustomerPayment settlement (with a mirrored OrderCost) tied to
 * the order + invoice — one ledger, not a bespoke inline write.
 */
class InvoicePayTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pay_writes_a_customer_payment_settlement(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);

        $order = Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-77', 'customer_name' => 'Asish',
            'customer_phone' => '211900111', 'total' => 500, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
        $inv = Invoice::create([
            'tenant_id' => $t->id, 'invoice_no' => 'INV-2026-0009', 'order_id' => $order->id,
            'customer_name' => 'Asish', 'customer_phone' => '211900111', 'currency' => 'USD',
            'total' => 500, 'amount_paid' => 0, 'balance' => 500, 'status' => 'issued', 'issued_at' => now(),
        ]);

        $this->postJson('/papi/invoice-pay', ['id' => $inv->id, 'amount' => 100])->assertOk();

        $pay = CustomerPayment::where('tenant_id', $t->id)->where('order_no', 'ORD-77')->first();
        $this->assertNotNull($pay, 'invoice pay should write a CustomerPayment');
        $this->assertSame('settlement', $pay->kind);
        $this->assertTrue((bool) $pay->applied);
        $this->assertNotNull($pay->order_cost_id, 'should mirror a cash-in OrderCost');
        $this->assertSame('INV-2026-0009', $pay->invoice_no);
        $this->assertEqualsWithDelta(100.0, (float) $pay->usd_amount, 0.001);

        // the invoice balance is re-derived from that ledger
        $this->assertEqualsWithDelta(400.0, (float) $inv->fresh()->balance, 0.5);
    }
}
