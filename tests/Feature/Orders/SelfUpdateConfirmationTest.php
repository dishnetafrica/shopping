<?php
namespace Tests\Feature\Orders;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Customer self-update fallback. Seller delivery and customer confirmation are independent events:
 * a customer confirming an un-delivered order records ONLY the customer event (delivered_by =
 * customer) and never overwrites the seller's status — preserving the audit trail and seller
 * response time. A two-tap "are you sure" guards against accidental confirmation.
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

    public function test_self_confirm_records_only_the_customer_event(): void
    {
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Out for delivery');           // seller never marked Delivered

        $this->get("/papi/track?o={$o->id}&t=tok123&do=received&c=1")->assertOk();

        $o->refresh();
        $this->assertNotNull($o->customer_confirmed_at);
        $this->assertSame('customer', $o->delivered_by, 'records who closed it first');
        $this->assertNull($o->delivered_at, 'seller delivered_at is NOT fabricated');
        $this->assertSame('Out for delivery', $o->status, 'seller status is preserved (independent events)');
        $this->assertTrue($o->isComplete());
        $this->assertSame('customer', $o->completedBy());
    }

    public function test_first_tap_asks_are_you_sure_and_does_not_record(): void
    {
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Delivered', now()->subMinutes(5)->toDateTimeString());

        $this->get("/papi/track?o={$o->id}&t=tok123&do=received")     // no c=1
            ->assertOk()
            ->assertSee('Are you sure', false);
        $this->assertNull($o->refresh()->customer_confirmed_at);
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

    public function test_seller_delivered_order_is_not_tagged_customer(): void
    {
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Delivered', now()->subMinutes(5)->toDateTimeString());
        $this->get("/papi/track?o={$o->id}&t=tok123&do=received&c=1")->assertOk();
        $o->refresh();
        $this->assertNull($o->delivered_by, 'seller-delivered orders are not tagged customer');
        $this->assertSame('seller', $o->completedBy());
    }
}
