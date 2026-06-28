<?php
namespace Tests\Feature\Orders;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The watchdog that forces sellers to keep order data current. */
class OrderStalenessWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private function order(Tenant $t, string $status, $changedAgo): Order
    {
        $o = Order::create([
            'tenant_id' => $t->id, 'order_no' => 'ORD-' . uniqid(), 'customer_phone' => '256700000000',
            'items_text' => 'x', 'total' => 1000, 'status' => $status, 'track_token' => uniqid(),
        ]);
        $o->status_changed_at = $changedAgo;   // simulate time-in-status without flipping status
        $o->saveQuietly();
        return $o;
    }

    public function test_order_stuck_past_sla_nudges_the_seller(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Preparing', now()->subHours(2));   // SLA 90 min
        Artisan::call('orders:staleness-watchdog');

        Queue::assertPushed(NotifyOwner::class);
        $o->refresh();
        $this->assertSame(1, $o->stale_level);
        $this->assertNotNull($o->stale_nudged_at);
    }

    public function test_fresh_order_is_not_nudged(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Preparing', now()->subMinutes(10));
        Artisan::call('orders:staleness-watchdog');

        Queue::assertNotPushed(NotifyOwner::class);
        $this->assertSame(0, $o->refresh()->stale_level);
    }

    public function test_terminal_order_is_not_nudged(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Delivered', now()->subHours(5));
        Artisan::call('orders:staleness-watchdog');
        Queue::assertNotPushed(NotifyOwner::class);
    }

    public function test_escalation_caps_out(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        $o = $this->order($t, 'Preparing', now()->subHours(6));
        $o->stale_level = 2;            // already escalated to the cap
        $o->saveQuietly();
        Artisan::call('orders:staleness-watchdog');
        Queue::assertNotPushed(NotifyOwner::class);
    }
}
