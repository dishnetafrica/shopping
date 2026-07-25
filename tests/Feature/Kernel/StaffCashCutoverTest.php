<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 3C — StaffCash "Receive" now records through the single, idempotent recorder (PaymentService)
 * instead of its own inline write. Proves: the same rows are produced and one PaymentReceived event
 * lands (parity); a retry with the same idempotency_key does NOT double-record (the due-diligence
 * Fatal bug, closed); and the pay_unify sync gate is preserved exactly (Option B) — ON reduces the
 * invoice, OFF leaves it. Factory-independent so it runs regardless of the factory restoration.
 */
class StaffCashCutoverTest extends TestCase
{
    use RefreshDatabase;

    private function boot(bool $payUnify): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $t->putSetting('order_system_enabled', true);
        $t->putSetting('pay_unify', $payUnify);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'owner-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    public function test_receive_records_one_payment_and_emits_one_event(): void
    {
        $this->boot(false);

        $this->postJson('/papi/staff-cash/receive', [
            'person' => 'Owner', 'customer' => 'Asish', 'currency' => 'USD',
            'amount' => 200, 'rate_to_usd' => 1, 'kind' => 'advance',
        ])->assertOk();

        $this->assertSame(1, OrderCost::where('stage', 'customer_payment')->count());
        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame(1, Event::where('name', 'PaymentReceived')->count());
    }

    public function test_retry_with_same_idempotency_key_does_not_double_record(): void
    {
        $this->boot(false);

        $body = [
            'person' => 'Owner', 'customer' => 'Asish', 'currency' => 'USD',
            'amount' => 200, 'rate_to_usd' => 1, 'kind' => 'advance', 'idempotency_key' => 'staff-key-1',
        ];
        $this->postJson('/papi/staff-cash/receive', $body)->assertOk();
        $this->postJson('/papi/staff-cash/receive', $body)->assertOk(); // offline retry

        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame(1, OrderCost::where('stage', 'customer_payment')->count());
        $this->assertSame(1, Event::where('name', 'PaymentReceived')->count());
    }

    public function test_pay_unify_on_reduces_the_invoice(): void
    {
        $t = $this->boot(true);
        $this->seedOrderInvoice($t);

        $this->postJson('/papi/staff-cash/receive', [
            'person' => 'Owner', 'customer' => 'Asish', 'order_no' => 'ORD-9',
            'currency' => 'USD', 'amount' => 200, 'rate_to_usd' => 1,
        ])->assertOk();

        $inv = Invoice::where('invoice_no', 'INV-3C')->first();
        $this->assertEqualsWithDelta(300.0, (float) $inv->balance, 0.5);
    }

    public function test_pay_unify_off_leaves_the_invoice_unchanged(): void
    {
        $t = $this->boot(false);
        $this->seedOrderInvoice($t);

        $this->postJson('/papi/staff-cash/receive', [
            'person' => 'Owner', 'customer' => 'Asish', 'order_no' => 'ORD-9',
            'currency' => 'USD', 'amount' => 200, 'rate_to_usd' => 1,
        ])->assertOk();

        $inv = Invoice::where('invoice_no', 'INV-3C')->first();
        $this->assertEqualsWithDelta(500.0, (float) $inv->balance, 0.5);
    }

    private function seedOrderInvoice(Tenant $t): void
    {
        $order = Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-9', 'customer_name' => 'Asish',
            'customer_phone' => '211900', 'total' => 500, 'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);
        Invoice::create([
            'tenant_id' => $t->id, 'invoice_no' => 'INV-3C', 'order_id' => $order->id,
            'customer_name' => 'Asish', 'customer_phone' => '211900', 'currency' => 'USD',
            'total' => 500, 'amount_paid' => 0, 'balance' => 500, 'status' => 'issued', 'issued_at' => now(),
        ]);
    }
}
