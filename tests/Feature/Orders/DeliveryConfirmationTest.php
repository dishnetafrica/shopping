<?php
namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer delivery-confirmation flow on the tracking page: I Received → rating, token-credentialed,
 * recorded as the customer_confirmed_at event (status untouched so the kitchen flow keeps working).
 */
class DeliveryConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function deliveredOrder(): Order
    {
        $t = Tenant::factory()->create();
        return Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-1', 'customer_phone' => '256700000000',
            'items_text' => '250g Fafda', 'total' => 12500, 'status' => 'Delivered',
            'delivered_at' => now()->subMinutes(5), 'track_token' => 'tok123',
        ]);
    }

    public function test_shows_confirm_button_when_delivered_and_unconfirmed(): void
    {
        $o = $this->deliveredOrder();
        $this->get("/papi/track?o={$o->id}&t=tok123")
            ->assertOk()
            ->assertSee('I Received My Order', false);
    }

    public function test_received_records_confirmation_event_without_changing_status(): void
    {
        $o = $this->deliveredOrder();
        $this->get("/papi/track?o={$o->id}&t=tok123&do=received&c=1")->assertOk();

        $o->refresh();
        $this->assertNotNull($o->customer_confirmed_at);
        $this->assertSame('tracking_page', $o->confirmation_source);
        $this->assertSame('Delivered', $o->status, 'status must stay Delivered (event-based confirmation)');
    }

    public function test_rating_is_saved_after_confirmation(): void
    {
        $o = $this->deliveredOrder();
        $this->get("/papi/track?o={$o->id}&t=tok123&do=received&c=1")->assertOk();
        $this->get("/papi/track?o={$o->id}&t=tok123&do=rate&r=5")->assertOk();

        $o->refresh();
        $this->assertSame(5, $o->rating);
        $this->assertNotNull($o->rating_at);
    }

    public function test_bad_token_cannot_confirm(): void
    {
        $o = $this->deliveredOrder();
        $this->get("/papi/track?o={$o->id}&t=WRONG&do=received&c=1")->assertOk();
        $this->assertNull($o->refresh()->customer_confirmed_at);
    }
}
