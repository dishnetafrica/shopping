<?php

namespace Tests\Feature;

use App\Http\Controllers\Billing\BillingController;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Billing\Flutterwave;
use App\Services\Billing\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P0/WS-1c — the billing webhooks run with NO tenant context and Payment has no global scope, so
 * they must not activate or reassign the wrong tenant's plan. These pin the defense-in-depth guards:
 *   - Flutterwave: the payment row's tenant must match the tenant id embedded in tx_ref.
 *   - Stripe: firstOrNew() must never reassign an existing row to a different (metadata) tenant.
 * Both guards fail closed (no markPaid / no reassignment) on mismatch.
 */
class BillingWebhookTenantGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_txref_tenant_guard_logic(): void
    {
        $m = new ReflectionMethod(BillingController::class, 'txRefMatchesTenant');
        $m->setAccessible(true);
        $c = app(BillingController::class);

        $this->assertTrue($m->invoke($c, 'CB-12-ABCD1234', 12), 'matching embedded tenant passes');
        $this->assertFalse($m->invoke($c, 'CB-12-ABCD1234', 99), 'row tenant 99 vs ref tenant 12 is blocked');
        $this->assertTrue($m->invoke($c, 'cs_test_123', 42), 'non-CB (Stripe) refs are not blocked here');
    }

    public function test_flutterwave_webhook_rejects_a_tenant_mismatched_payment(): void
    {
        $t = Tenant::factory()->create();
        // Row belongs to $t, but tx_ref embeds a DIFFERENT tenant id (corruption / forgery).
        $pay = Payment::create([
            'tenant_id' => $t->id, 'provider' => 'flutterwave', 'plan' => 'starter', 'months' => 1,
            'amount' => 1000, 'currency' => 'UGX', 'tx_ref' => 'CB-' . ($t->id + 999) . '-ABCD1234',
            'status' => 'pending',
        ]);

        $flw = \Mockery::mock(Flutterwave::class);
        $flw->shouldReceive('webhookHashValid')->andReturn(true);

        $req = Request::create('/billing/flutterwave/webhook', 'POST', [
            'data' => ['tx_ref' => $pay->tx_ref, 'status' => 'successful', 'amount' => 1000],
        ]);

        app(BillingController::class)->flutterwaveWebhook($req, $flw);

        $this->assertSame('pending', $pay->fresh()->status, 'mismatched payment must NOT be marked paid');
    }

    public function test_stripe_webhook_does_not_reassign_a_payment_to_another_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $pay = Payment::create([
            'tenant_id' => $a->id, 'provider' => 'stripe', 'plan' => 'starter', 'months' => 1,
            'amount' => 10, 'currency' => 'USD', 'tx_ref' => 'cs_test_reassign', 'status' => 'pending',
        ]);

        $stripe = \Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('webhookValid')->andReturn(true);

        // Same tx_ref, but metadata claims tenant B.
        $event = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_reassign', 'client_reference_id' => 'cs_test_reassign',
                'metadata' => ['tenant_id' => $b->id, 'plan' => 'starter'],
            ]],
        ]);
        $req = Request::create('/billing/stripe/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $event);
        $req->headers->set('Stripe-Signature', 'x');

        app(BillingController::class)->stripeWebhook($req, $stripe);

        $fresh = $pay->fresh();
        $this->assertSame($a->id, (int) $fresh->tenant_id, 'payment must not be reassigned to tenant B');
        $this->assertSame('pending', $fresh->status, 'mismatched Stripe event must NOT mark paid');
    }
}
