<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Push\WebPushService;
use App\Support\MessageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dependency-free Web Push: key issuance, subscription, and the inbound buzz hook. */
class PushTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function login(): User
    {
        $this->t = Tenant::factory()->create();
        $this->t->putSetting('order_system_enabled', true);
        app(\App\Support\TenantContext::class)->set($this->t->id);
        $u = User::factory()->create(['tenant_id' => $this->t->id, 'role' => 'owner']);
        $this->actingAs($u);
        return $u;
    }

    public function test_vapid_public_key_is_generated_once_and_persisted(): void
    {
        $this->login();
        $k1 = $this->getJson('/papi/push/vapid-public')->assertOk()->json('key');
        $k2 = $this->getJson('/papi/push/vapid-public')->assertOk()->json('key');

        $this->assertNotSame('', $k1);
        $this->assertSame($k1, $k2, 'one keypair for the app, generated lazily once');
        $this->assertDatabaseCount('push_keys', 1);
    }

    public function test_subscribe_upserts_by_endpoint(): void
    {
        $u = $this->login();
        $body = ['endpoint' => 'https://fcm.googleapis.com/x/abc', 'p256dh' => 'Pk', 'auth' => 'Au'];
        $this->postJson('/papi/push/subscribe', $body)->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/papi/push/subscribe', $body)->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
        $s = PushSubscription::first();
        $this->assertSame($this->t->id, (int) $s->tenant_id);
        $this->assertSame($u->id, (int) $s->user_id);
    }

    public function test_inbound_message_triggers_push_and_outbound_does_not(): void
    {
        $this->login();
        $fake = new class extends WebPushService {
            public array $calls = [];
            public function notifyInbound(int $t, string $p, string $n, string $prev): void
            {
                $this->calls[] = [$t, $p, $prev];
            }
        };
        $this->app->instance(WebPushService::class, $fake);

        MessageLog::record($this->t->id, '256700000012', 'inst', 'in', 'customer', 'hello there');
        MessageLog::record($this->t->id, '256700000012', 'inst', 'out', 'bot', 'hi!');

        $this->assertCount(1, $fake->calls, 'inbound buzzes, outbound never does');
        $this->assertSame([$this->t->id, '256700000012', 'hello there'], $fake->calls[0]);
    }
}
