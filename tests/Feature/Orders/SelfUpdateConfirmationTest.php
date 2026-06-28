<?php
namespace Tests\Feature\Orders;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Customer self-update fallback: when the seller never marks an order Delivered, the customer can
 * still confirm receipt on the tracking page — which closes the loop AND records the seller gap
 * (delivered_by = customer). Also covers the "not received yet" escalation.
 */
class SelfUpdateConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function order(Tenant $t, string $status, ?string $deliveredAt = null): Order
    {
        return Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-' . uniqid(), 'customer_phone' => '256700000000',
            'items_text' => '250g Fafda', 'total' => 8800, 'status' => $status,
            'delivered_at' => $deliveredAt, 'track_token' => 'tok123',
        ]);
    }

    public function test_customer_can_self_confirm_when_seller_never_marked_delivered(): void
    {
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Out for delivery');          // seller stopped updating here, never "Delivered"

        $this->get("/papi/track?o={$o->id}&t=tok123&do=received")->assertOk();

        $o->refresh();
        $this->assertNotNull($o->customer_confirmed_at);
        $this->assertSame('customer', $o->delivered_by, 'self-update records the seller gap');
        $this->assertNotNull($o->delivered_at, 'delivered_at is stamped so the loop + metrics are complete');
        $this->assertSame('Delivered', $o->status);
    }

    public function test_self_confirm_offered_on_an_in_progress_order(): void
    {
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Preparing');
        $this->get("/papi/track?o={$o->id}&t=tok123")
            ->assertOk()
            ->assertSee('Confirm I received it', false);
    }

    public function test_not_received_escalates_to_the_shop(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Out for delivery');
        $this->get("/papi/track?o={$o->id}&t=tok123&do=help")->assertOk();
        Queue::assertPushed(NotifyOwner::class);
        $this->assertNull($o->refresh()->customer_confirmed_at);
    }

    public function test_seller_delivered_order_is_not_marked_customer_closed(): void
    {
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Delivered', now()->subMinutes(5)->toDateTimeString());
        $this->get("/papi/track?o={$o->id}&t=tok123&do=received")->assertOk();
        $this->assertNull($o->refresh()->delivered_by, 'seller-delivered orders are not tagged customer');
    }
}
