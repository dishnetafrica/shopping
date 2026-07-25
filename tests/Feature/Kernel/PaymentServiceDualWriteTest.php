<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Models\CustomerPayment;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Money\PaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 3B — PaymentService dual-writes the money-in fact through the money.receive capability.
 * Proves: the authoritative rows are still written (behaviour preserved), EXACTLY ONE
 * PaymentReceived event lands with the customer subject (Customer-360 timeline unchanged),
 * and a replayed record produces one payment + one event (idempotent).
 */
class PaymentServiceDualWriteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Test Co', 'slug' => 'test-' . substr(md5(uniqid('', true)), 0, 8)]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'customer'       => 'Shop no 58',
            'phone'          => '211900000',
            'order_no'       => null,
            'invoice_no'     => null,
            'kind'           => 'advance',      // advance → R3-exempt → capability emits directly (no fallback)
            'currency'       => 'USD',
            'amount'         => 100.0,
            'rate'           => 1.0,
            'usd'            => 100.0,
            'method'         => 'Cash',
            'reference'      => null,
            'receipt_path'   => null,
            'holder'         => 'Asish',
            'holder_user_id' => null,
            'created_by'     => 'Asish',
        ], $over);
    }

    public function test_recording_a_payment_preserves_rows_and_emits_one_event(): void
    {
        $res = app(PaymentService::class)->record($this->tenant, $this->payload());

        $this->assertNotEmpty($res['receipt_no']);

        // Behaviour preserved — the authoritative ledger + receipt rows still exist.
        $this->assertSame(1, OrderCost::where('stage', 'customer_payment')->count());
        $this->assertSame(1, CustomerPayment::count());

        // Exactly one money-in fact, carrying the customer subject (timeline parity).
        $events = Event::where('name', 'PaymentReceived')->get();
        $this->assertCount(1, $events);
        $this->assertSame('customer', $events[0]->subject_type);
        $this->assertSame(100.0, (float) $events[0]->payload['amount']);
        $this->assertSame('USD', $events[0]->payload['currency']);
    }

    public function test_replayed_record_is_idempotent_one_payment_one_event(): void
    {
        $in = $this->payload(['idempotency_key' => 'fixed-key-3b']);

        app(PaymentService::class)->record($this->tenant, $in);
        app(PaymentService::class)->record($this->tenant, $in); // retry (offline reconnect)

        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame(1, Event::where('name', 'PaymentReceived')->count());
    }
}
