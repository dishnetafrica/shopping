<?php

namespace Tests\Feature\Sales;

use App\Jobs\HandleMissedCall;
use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * A customer tries to call an Internet provider's WhatsApp line, through the real webhook (DishNet,
 * 18 Nov 2026: "make a missed WhatsApp call alert the customer's salesperson"). Names and numbers are
 * test values.
 */
class MissedCallTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER = '211911000077';

    private const SALES = '211922000001';

    private object $gw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gw = new class implements \App\Contracts\WhatsAppGateway {
            public array $sent = [];
            public function sendText(string $i, string $to, string $m, ?array $q = null): array { $this->sent[] = [$to, $m]; return ['key' => ['id' => 'X']]; }
            public function sendImage(string $i, string $to, string $m, string $c = ''): array { return []; }
            public function markRead(string $i, string $id): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $i): array { return []; }
        };
        $gw = $this->gw;
        $this->app->instance(WhatsAppManager::class, new class($gw) extends WhatsAppManager {
            public function __construct(private \App\Contracts\WhatsAppGateway $g) {}
            public function forTenant(Tenant $t): \App\Contracts\WhatsAppGateway { return $this->g; }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(array $settings = []): Tenant
    {
        // $settings first: in PHP's array union the LEFT value wins
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => $settings + [
            'vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'business_hours' => '24/7',
        ]]);
        app(TenantContext::class)->set($t->id);
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => self::SALES, 'email' => 'a' . $t->id . '@t.test', 'password' => 'secret']);

        return $t;
    }

    private function ring(Tenant $t, string $id, string $from = self::CALLER, string $status = 'offer'): void
    {
        $this->postJson('/api/webhook/whatsapp/evolution', ['event' => 'call', 'instance' => $t->whatsapp_instance, 'data' => [
            'id' => $id, 'from' => $from . '@s.whatsapp.net', 'chatId' => $from . '@s.whatsapp.net', 'status' => $status,
            'isVideo' => false, 'isGroup' => false, 'offline' => false, 'date' => '2026-11-18T09:00:00.000Z',
        ]])->assertOk();
    }

    public function test_the_caller_is_answered_once_and_their_salesperson_alerted(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        Message::create(['tenant_id' => $t->id, 'customer_phone' => self::CALLER, 'instance' => $t->whatsapp_instance, 'direction' => 'in', 'sender' => 'customer', 'body' => 'Starlink for my shop in Munuki']);

        $this->ring($t, 'CALL-1');

        $this->assertCount(1, $this->gw->sent);
        $this->assertSame(self::CALLER, $this->gw->sent[0][0]);
        $this->assertSame("Sorry we missed your call \u{1F64F} We cannot take calls on this WhatsApp number. Please type your question here and we will answer right away. Akol Deng from our sales team will also call you back.", $this->gw->sent[0][1]);
        $logged = Message::where('customer_phone', self::CALLER)->where('direction', 'out')->first();
        $this->assertSame('bot', $logged->sender);
        $this->assertSame('missed_call', $logged->meta['via']);
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === self::SALES
            && str_contains($j->text, 'Missed WhatsApp call* — +' . self::CALLER)
            && str_contains($j->text, '"Starlink for my shop in Munuki"')
            && str_contains($j->text, 'The bot told them you will call them back.'));
        $this->assertSame(self::SALES, app(SalesOwnership::class)->ownerOf($t, self::CALLER)?->phone, 'a caller with no salesperson now has one');

        // The same call's later events, and the caller trying again a minute later: nothing more.
        $this->ring($t, 'CALL-1', self::CALLER, 'terminate');
        $this->ring($t, 'CALL-2');
        $this->assertCount(1, $this->gw->sent);
        Bus::assertDispatchedTimes(NotifyOwner::class, 1);
    }

    public function test_after_hours_the_caller_is_told_when_the_office_opens(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business(['business_hours' => 'Mon-Sat 9 AM - 6 PM']);
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00', 'Africa/Juba'));   // a Sunday

        $this->ring($t, 'CALL-SUN');

        $this->assertStringEndsWith('Akol Deng from our sales team will also call you back when the office opens (tomorrow at 9:00 AM).', $this->gw->sent[0][1]);
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => str_contains($j->text, 'Please call them back when the office opens (tomorrow at 9:00 AM)'));
    }

    public function test_staff_a_person_handling_the_chat_and_other_businesses(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();

        $this->ring($t, 'CALL-STAFF', self::SALES);
        $this->assertSame([], $this->gw->sent, 'a salesperson calling the line is not a customer');
        Bus::assertNotDispatched(NotifyOwner::class);

        // A person has this chat: they get the alert, the bot does not talk over them.
        Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::CALLER, 'instance' => $t->whatsapp_instance, 'state' => ['human_until' => time() + 3600], 'cart' => []]);
        $this->ring($t, 'CALL-H');
        $this->assertSame([], $this->gw->sent);
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === self::SALES && ! str_contains($j->text, 'The bot told them'));

        // Not an Internet provider (a shop): a call changes nothing at all.
        Bus::fake([NotifyOwner::class, HandleMissedCall::class]);
        $shop = Tenant::factory()->create(['whatsapp_instance' => 'shop_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => ['vertical' => 'grocery']]);
        $this->ring($shop, 'CALL-SHOP');
        Bus::assertNotDispatched(HandleMissedCall::class);
        $this->assertSame([], $this->gw->sent);
    }

    public function test_switched_off(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business(['isp_call_alerts' => false]);
        $this->ring($t, 'CALL-OFF');
        $this->assertSame([], $this->gw->sent);
        Bus::assertNotDispatched(NotifyOwner::class);

        $t2 = $this->business(['isp_call_reply' => false]);
        $this->ring($t2, 'CALL-NOREPLY');
        $this->assertSame([], $this->gw->sent, 'alert only, no message to the caller');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === self::SALES);
    }
}
