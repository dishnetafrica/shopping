<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Models\Message;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * `php artisan isp:quote-followup` with the real database (DishNet, 18 Nov 2026: "the AI works as the
 * relationship and sales manager"). Names and numbers are test values.
 */
class IspQuoteFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '211911000055';

    private object $gw;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(array $settings = []): Tenant
    {
        // $settings first: in PHP's array union the LEFT value wins, so the test's own settings override the defaults
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => $settings + [
            'vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'business_hours' => '24/7', 'isp_quote_followup' => true,
        ]]);
        app(TenantContext::class)->set($t->id);
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'a' . $t->id . '@t.test', 'password' => 'secret']);
        app(SalesOwnership::class)->ownerForEnquiry($t, self::CUSTOMER, 'Peter');

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

        return $t;
    }

    private function quote(Tenant $t, int $daysAgo, string $status = 'sent'): Quotation
    {
        $q = Quotation::create(['tenant_id' => $t->id, 'quote_no' => 'Q-T' . $daysAgo, 'customer_name' => 'Peter', 'customer_phone' => self::CUSTOMER,
            'currency' => 'USD', 'total' => 550, 'status' => $status, 'source' => 'bot', 'valid_until' => now()->addDays(14 - $daysAgo)->toDateString()]);
        $q->forceFill(['created_at' => now()->subDays($daysAgo)->subHour()])->save();

        return $q;
    }

    private function run1(Tenant $t): void
    {
        $this->artisan('isp:quote-followup', ['--tenant' => $t->id, '--no-pause' => true])->assertSuccessful();
    }

    public function test_day_two_follow_up_goes_out_in_the_salespersons_name_once(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $q = $this->quote($t, 2);

        $this->run1($t);

        $this->assertCount(1, $this->gw->sent);
        $this->assertSame(self::CUSTOMER, $this->gw->sent[0][0]);
        $this->assertStringStartsWith('Hello Peter, this is Akol Deng from ' . $t->name . '. Did you get a chance to look at your quotation Q-T2?', $this->gw->sent[0][1]);
        $this->assertCount(1, (array) $q->fresh()->meta['isp_followups']);
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'Followed up quotation Q-T2'));

        $this->run1($t);
        $this->assertCount(1, $this->gw->sent, 'never twice in a day, never the same touch twice');
    }

    public function test_nothing_goes_to_a_customer_who_replied_or_when_closed_or_switched_off(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $q = $this->quote($t, 3);
        Message::create(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER, 'instance' => $t->whatsapp_instance, 'direction' => 'in', 'sender' => 'customer', 'body' => 'ok let me think']);
        $this->run1($t);
        $this->assertSame([], $this->gw->sent, 'the customer wrote after the quotation: the conversation is live');

        $t2 = $this->business(['business_hours' => 'Mon-Sat 9 AM - 6 PM']);
        $this->quote($t2, 2);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-22 10:00', 'Africa/Juba'));   // a Sunday
        try {
            $this->run1($t2);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
        $this->assertSame([], $this->gw->sent, 'closed: nothing is sent');

        $t3 = $this->business(['isp_quote_followup' => false]);
        $this->quote($t3, 2);
        $this->run1($t3);
        $this->assertSame([], $this->gw->sent, 'off unless switched on');
    }

    public function test_accepted_expired_or_old_quotations_are_left_alone(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $this->quote($t, 2, 'accepted');
        $this->run1($t);
        $this->assertSame([], $this->gw->sent);

        $t2 = $this->business();
        $this->quote($t2, 15);
        $this->run1($t2);
        $this->assertSame([], $this->gw->sent, 'older than 14 days');

        // Codex on #165: the customer changed it in the portal — that is an answer, not silence.
        $t3 = $this->business();
        $this->quote($t3, 2, 'customer_revised');
        $this->run1($t3);
        $this->assertSame([], $this->gw->sent, 'customer_revised is never chased');
    }

    /** Codex on #165: a resend from the panel today logs a message but does not stamp last_contacted_at. */
    public function test_anything_we_sent_in_the_last_day_holds_the_follow_up(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $this->quote($t, 2);
        Message::create(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER, 'instance' => $t->whatsapp_instance, 'direction' => 'out', 'sender' => 'agent', 'body' => 'Here is your quotation again']);

        $this->run1($t);

        $this->assertSame([], $this->gw->sent);
    }

    /** A quotation sales revised and re-sent is counted from the re-send, not from when it was first made. */
    public function test_a_revised_quotation_counts_from_when_it_was_sent_again(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $q = $this->quote($t, 9, 'revised');
        $q->forceFill(['last_sent_at' => now()->subDays(2)->subHour()])->save();

        $this->run1($t);

        $this->assertCount(1, $this->gw->sent, 'day 2 after the re-send: first touch');
        $this->assertStringContainsString('Did you get a chance to look at your quotation Q-T9?', $this->gw->sent[0][1]);
    }
}
