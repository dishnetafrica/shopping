<?php
namespace Tests\Feature\Orders;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** One digest message per shop, vertical-aware — replaces the per-order staleness spam. */
class OrderPendingDigestTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $vertical): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst-' . uniqid()]);
        $t->putSetting('vertical', $vertical);
        $t->putSetting('owner_alert_phone', '256700000000');
        return $t;
    }

    private function order(Tenant $t, string $status, $agoMinutes): Order
    {
        $o = Order::create(['tenant_id' => $t->id, 'customer_phone' => '256711000000', 'items_text' => 'x',
            'total' => 1000, 'status' => $status, 'track_token' => uniqid()]);
        $o->status_changed_at = now()->subMinutes($agoMinutes);
        $o->saveQuietly();
        return $o;
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_manufacturer_gets_one_digest_at_nine_am_not_per_order_spam(): void
    {
        Queue::fake();
        $t = $this->tenant('manufacturer');
        $this->order($t, 'New', 60 * 24);        // 1 day — normal for a manufacturer
        $this->order($t, 'New', 60 * 43);        // ~1d19h
        $this->order($t, 'Preparing', 60 * 72);  // 3 days

        Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00'));
        Artisan::call('orders:pending-digest');

        Queue::assertPushed(NotifyOwner::class, 1);   // ONE message, not three
    }

    public function test_slow_vertical_silent_outside_digest_windows(): void
    {
        Queue::fake();
        $t = $this->tenant('manufacturer');
        $this->order($t, 'New', 60 * 24);

        Carbon::setTestNow(Carbon::parse('2026-06-29 10:30:00'));   // not 09:00 / 14:00
        Artisan::call('orders:pending-digest');

        Queue::assertNotPushed(NotifyOwner::class);
    }

    public function test_restaurant_alerts_on_overdue_then_dedupes(): void
    {
        Queue::fake();
        $t = $this->tenant('restaurant');
        $this->order($t, 'New', 30);             // 30 min in "New" — overdue for a restaurant (15m)

        Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00'));
        Artisan::call('orders:pending-digest');
        Artisan::call('orders:pending-digest');  // same set → must not re-alert

        Queue::assertPushed(NotifyOwner::class, 1);
    }

    public function test_pending_page_requires_valid_token(): void
    {
        $t = $this->tenant('manufacturer');
        $t->putSetting('pending_token', 'good-token');
        $this->order($t, 'New', 60);

        $this->get("/papi/pending?t={$t->id}&k=good-token")->assertOk()->assertSee('open order', false);
        $this->get("/papi/pending?t={$t->id}&k=wrong")->assertNotFound();
    }
}
