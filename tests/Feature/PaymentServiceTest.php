<?php

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Money\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E4.1 — the extracted single payment recorder writes the same ledger the controller did:
 * a cash-in OrderCost mirrored by a CustomerPayment receipt, with the RCP-##### number and the
 * settlement/advance applied flag.
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $over = []): array
    {
        return array_merge([
            'customer' => 'RA Enterprises', 'phone' => '211900111222',
            'order_no' => 'ORD-1', 'invoice_no' => null, 'kind' => 'settlement',
            'currency' => 'USD', 'amount' => 380.0, 'rate' => 1.0, 'usd' => 380.0,
            'method' => 'Cash', 'reference' => null, 'receipt_path' => null,
            'holder' => 'Owner', 'holder_user_id' => null, 'created_by' => 'Owner',
        ], $over);
    }

    public function test_record_writes_customer_payment_and_order_cost(): void
    {
        $t = Tenant::factory()->create();
        $svc = app(PaymentService::class);

        $res = $svc->record($t, $this->data());

        $this->assertSame('RCP-00001', $res['receipt_no']);

        $pay = CustomerPayment::where('tenant_id', $t->id)->first();
        $this->assertNotNull($pay);
        $this->assertSame('settlement', $pay->kind);
        $this->assertTrue((bool) $pay->applied);          // settlement => applied
        $this->assertSame('ORD-1', $pay->order_no);
        $this->assertEqualsWithDelta(380.0, (float) $pay->usd_amount, 0.001);
        $this->assertNotNull($pay->order_cost_id);

        $cost = OrderCost::where('tenant_id', $t->id)->where('stage', 'customer_payment')->first();
        $this->assertNotNull($cost);
        $this->assertSame('Receipt', $cost->category);
        $this->assertSame($cost->id, $pay->order_cost_id);
        $this->assertEqualsWithDelta(380.0, (float) $cost->usd_amount, 0.001);
    }

    public function test_advance_is_not_applied(): void
    {
        $t = Tenant::factory()->create();
        $pay = app(PaymentService::class)->record($t, $this->data(['kind' => 'advance']));
        $this->assertFalse((bool) CustomerPayment::find($pay['id'])->applied);
    }

    public function test_receipt_numbers_increment(): void
    {
        $t = Tenant::factory()->create();
        $svc = app(PaymentService::class);
        $a = $svc->record($t, $this->data());
        $b = $svc->record($t, $this->data());
        $this->assertSame('RCP-00001', $a['receipt_no']);
        $this->assertSame('RCP-00002', $b['receipt_no']);
    }
}
