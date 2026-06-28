<?php
namespace Tests\Feature\Orders;

use App\Apps\Orders\Observatory\OrderObservatory;
use App\Models\OrderFlowMetric;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderObservatoryTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::factory()->create();
        app(TenantContext::class)->set($t->id);
        return $t;
    }

    public function test_order_message_is_logged_as_parsed(): void
    {
        $t = $this->boot();
        app(OrderObservatory::class)->record($t->id, 1, '250g khaman 250g fafda', 'Added 250g Khaman.');
        $m = OrderFlowMetric::where('tenant_id', $t->id)->first();
        $this->assertSame('order', $m->intent);
        $this->assertTrue($m->draft_parsed);
        $this->assertGreaterThanOrEqual(2, $m->products_found);
        $this->assertSame('added_to_cart', $m->outcome);
    }

    public function test_greeting_is_not_parsed(): void
    {
        $t = $this->boot();
        app(OrderObservatory::class)->record($t->id, 2, 'Hello', 'Hi! How can I help?');
        $m = OrderFlowMetric::where('conversation_id', 2)->first();
        $this->assertSame('greeting', $m->intent);
        $this->assertFalse($m->draft_parsed);
        $this->assertSame(0, $m->products_found);
    }

    public function test_clarification_in_live_reply_is_flagged(): void
    {
        $t = $this->boot();
        app(OrderObservatory::class)->record($t->id, 3, 'gathiya', 'Did you mean Vanela Gathiya or Fafda Gathiya?');
        $this->assertTrue(OrderFlowMetric::where('conversation_id', 3)->first()->clarification);
    }
}
