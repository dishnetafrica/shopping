<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E4.3 — a staff-cash "Receive" now reduces the linked invoice/statement (the audit bug: it
 * updated cash but not the invoice). Gated on pay_unify: ON => the invoice balance drops; OFF => the
 * receipt is still recorded but the invoice is left to the existing (unchanged) behaviour.
 */
class StaffReceiveSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setup1(bool $payUnify): array
    {
        $t = Tenant::factory()->create();
        $t->putSetting('order_system_enabled', true);
        $t->putSetting('pay_unify', $payUnify);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);

        $order = Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-9', 'customer_name' => 'Asish',
            'customer_phone' => '211900', 'total' => 500, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
        $inv = Invoice::create([
            'tenant_id' => $t->id, 'invoice_no' => 'INV-2026-0010', 'order_id' => $order->id,
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'currency' => 'USD',
            'total' => 500, 'amount_paid' => 0, 'balance' => 500, 'status' => 'issued', 'issued_at' => now(),
        ]);
        return [$t, $inv];
    }

    private function receive(): void
    {
        $this->postJson('/papi/staff-cash/receive', [
            'person' => 'Owner', 'customer' => 'Asish', 'order_no' => 'ORD-9',
            'currency' => 'USD', 'amount' => 200, 'rate_to_usd' => 1,
        ])->assertOk();
    }

    public function test_pay_unify_on_reduces_the_invoice(): void
    {
        [$t, $inv] = $this->setup1(true);
        $this->receive();
        $this->assertEqualsWithDelta(300.0, (float) $inv->fresh()->balance, 0.5);
    }

    public function test_pay_unify_off_leaves_invoice_unchanged(): void
    {
        [$t, $inv] = $this->setup1(false);
        $this->receive();
        $this->assertEqualsWithDelta(500.0, (float) $inv->fresh()->balance, 0.5);
    }
}
