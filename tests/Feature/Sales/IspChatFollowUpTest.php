<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * `php artisan isp:quote-followup` following up enquiries that went quiet (DishNet, 20 Nov 2026: "it's still
 * not following up with customers automatically"). Names and numbers are test values.
 */
class IspChatFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '211911000066';

    private object $gw;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(array $settings = []): Tenant
    {
        // $settings first: in PHP's array union the LEFT value wins
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => $settings + [
            'vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'business_hours' => '24/7', 'isp_quote_followup' => true,
        ]]);
        app(TenantContext::class)->set($t->id);
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'a' . $t->id . '@t.test', 'password' => 'secret']);

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

    /** One chat line, $hoursAgo in the past. */
    private function line(Tenant $t, string $dir, string $body, float $hoursAgo, string $phone = self::CUSTOMER, ?array $meta = null): void
    {
        $m = Message::create(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => $t->whatsapp_instance, 'direction' => $dir,
            'sender' => $dir === 'in' ? 'customer' : 'bot', 'body' => $body, 'meta' => $meta]);
        $m->forceFill(['created_at' => now()->subMinutes((int) ($hoursAgo * 60))])->save();
        Conversation::firstOrCreate(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => $t->whatsapp_instance], ['state' => [], 'cart' => []]);
    }

    private function run1(Tenant $t): void
    {
        $this->artisan('isp:quote-followup', ['--tenant' => $t->id, '--no-pause' => true])->assertSuccessful();
    }

    public function test_an_enquiry_that_went_quiet_is_followed_up_on_day_one_and_day_four_then_never(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $this->line($t, 'in', 'How much is Starlink for my shop in Munuki?', 30);
        $this->line($t, 'out', 'The Starlink Standard kit is ... Which area are you in?', 29.9);

        $this->run1($t);

        $this->assertCount(1, $this->gw->sent);
        $this->assertSame(self::CUSTOMER, $this->gw->sent[0][0]);
        $this->assertSame('Hello, this is Akol Deng from ' . $t->name . '. I am following up on the Starlink you asked about. Do you have any other questions, or would you like me to prepare a quotation for you?', $this->gw->sent[0][1]);
        $this->assertSame('chat_followup', Message::where('customer_phone', self::CUSTOMER)->where('direction', 'out')->latest('id')->first()->meta['via']);
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, "*Follow-ups sent in your name* (1):\n• +" . self::CUSTOMER . ' — Starlink, asked'));

        $this->run1($t);
        $this->assertCount(1, $this->gw->sent, 'never twice: once per touch, nothing within 24 hours of a message from us');

        // Day 4, still quiet: the second (last) touch. Our follow-up 3 days ago is moved back with the rest.
        Message::where('customer_phone', self::CUSTOMER)->get()->each(fn ($m) => $m->forceFill(['created_at' => $m->created_at->subDays(3)])->save());
        \App\Models\CustomerProfile::where('phone', self::CUSTOMER)->update(['last_contacted_at' => now()->subDays(3)]);
        $c = Conversation::where('customer_phone', self::CUSTOMER)->first();
        $c->state = ['isp_chat_followups' => [now()->subDays(3)->toIso8601String()]];
        $c->save();
        $this->run1($t);
        $this->assertCount(2, $this->gw->sent);
        $this->assertStringContainsString('I will not disturb you again', $this->gw->sent[1][1]);
        $this->assertSame(['chat_sent', 'touch 2 · warm'], [\DB::table('bot_events')->where('phone', self::CUSTOMER)->latest('id')->value('stage'), \DB::table('bot_events')->where('phone', self::CUSTOMER)->latest('id')->value('detail')], 'the step log is written');

        Message::where('customer_phone', self::CUSTOMER)->get()->each(fn ($m) => $m->forceFill(['created_at' => $m->created_at->subDays(2)])->save());
        $this->run1($t);
        $this->assertCount(2, $this->gw->sent, 'two touches, then never again until they write');
    }

    public function test_they_wrote_last_vendors_spam_support_and_copied_history_are_never_chased(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $this->line($t, 'out', 'Welcome to DishNet!', 31, '211911000001');
        $this->line($t, 'in', 'Do you sell fiber in Hai Malakal?', 30, '211911000001');       // they wrote last: we owe the reply
        $this->line($t, 'in', 'We offer billboard advertising in Juba for your internet services', 30, '211911000002');
        $this->line($t, 'out', 'Our sales team is on +211 923 400 000.', 29, '211911000002');
        $this->line($t, 'in', 'I got 150,000 SSP! You should try it too https://pjfra.buzz/x', 30, '211911000003');
        $this->line($t, 'out', 'I am here to help with internet services.', 29, '211911000003');
        $this->line($t, 'in', 'My internet is very slow since yesterday', 30, '211911000004');
        $this->line($t, 'out', 'Sorry about that, our support team will check.', 29, '211911000004');
        $this->line($t, 'in', 'How much is Starlink?', 30, '211911000005', ['imported' => 'evolution']);   // history copied from the phone
        $this->line($t, 'out', 'It was $299.', 29, '211911000005', ['imported' => 'evolution']);

        $this->artisan('isp:quote-followup', ['--tenant' => $t->id, '--no-pause' => true])
            ->expectsOutputToContain('1 chat(s) where the customer wrote last and nobody has answered: +211911000001')
            ->assertSuccessful();

        $this->assertSame([], $this->gw->sent);
    }

    public function test_quotations_are_left_to_the_quotation_follow_up_unless_they_answered_it(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        // Quotation sent after their last message: the quotation follow-up's (day 2), not a day-1 chat touch.
        $this->line($t, 'in', 'Send me a quotation for Starlink', 30);
        $this->line($t, 'out', '[quotation Q-A] Quotation Q-A', 29.9);
        $q = Quotation::create(['tenant_id' => $t->id, 'quote_no' => 'Q-A', 'customer_name' => 'Ann', 'customer_phone' => self::CUSTOMER, 'currency' => 'USD', 'total' => 550, 'status' => 'sent', 'source' => 'bot', 'valid_until' => now()->addDays(13)->toDateString()]);
        $q->forceFill(['created_at' => now()->subMinutes(1794)])->save();
        $this->run1($t);
        $this->assertSame([], $this->gw->sent);

        // Peter, 18 Nov: answered the quotation ("what's this kit for $650?"), got a reply, went quiet.
        $this->line($t, 'in', "What's this kit for 650$", 26, '211911000077');
        $this->line($t, 'out', 'The Starlink Standard kit is a one-time charge…', 25.9, '211911000077');
        $p = Quotation::create(['tenant_id' => $t->id, 'quote_no' => 'Q-P', 'customer_name' => 'Peter', 'customer_phone' => '211911000077', 'currency' => 'USD', 'total' => 550, 'status' => 'sent', 'source' => 'bot', 'valid_until' => now()->addDays(12)->toDateString()]);
        $p->forceFill(['created_at' => now()->subHours(36)])->save();
        $this->run1($t);
        $this->assertCount(1, $this->gw->sent);
        $this->assertSame('211911000077', $this->gw->sent[0][0]);
        $this->assertSame('Hello Peter, this is Akol Deng from ' . $t->name . '. I hope you are well! Do you have any other questions about your quotation Q-P? When you are ready, I can arrange the site survey and the installation for you.', $this->gw->sent[0][1]);
    }

    /** A preview (--dry) sends nothing, so it shows what would go out even while the office is closed. */
    public function test_a_preview_works_while_closed_and_sends_nothing(): void
    {
        Bus::fake([NotifyOwner::class]);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-22 07:20', 'Africa/Juba'));   // Sunday morning
        try {
            $t = $this->business(['business_hours' => 'Mon-Sat 9 AM - 6 PM']);
            $this->line($t, 'in', 'How much is Starlink?', 30);
            $this->line($t, 'out', 'Which area are you in?', 29.9);

            $this->artisan('isp:quote-followup', ['--tenant' => $t->id, '--dry' => true, '--no-pause' => true])
                ->expectsOutputToContain('closed now (Sun 07:20 Africa/Juba, hours: Mon-Sat 9 AM - 6 PM) — PREVIEW')
                // ONE expectation per printed line (each line is matched by one expectation only): the [DRY]
                // line carries the message, in the name of the salesperson they would get — a preview assigns nobody.
                ->expectsOutputToContain('Hello, this is Akol Deng from')
                ->assertSuccessful();
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
        $this->assertSame([], $this->gw->sent);
        Bus::assertNotDispatched(NotifyOwner::class);
    }

    /** More due than the daily cap: ready-to-buy first, then price questions, then a hello; ONE alert for the run. */
    public function test_the_best_leads_go_first_and_the_salesperson_gets_one_summary(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business(['isp_quote_followup_daily_cap' => 2]);
        $this->line($t, 'in', 'Hello', 26, '211911000101');                                         // only a hello, the newest
        $this->line($t, 'out', 'Welcome! How can I help?', 25.9, '211911000101');
        $this->line($t, 'in', 'How much is fiber in Gudele?', 40, '211911000102');                   // a price question
        $this->line($t, 'out', 'Which area exactly?', 39.9, '211911000102');
        $this->line($t, 'in', 'I want Starlink installed at my office', 50, '211911000103');        // ready to buy, the oldest
        $this->line($t, 'out', 'Great! Where is the office?', 49.9, '211911000103');

        $this->artisan('isp:quote-followup', ['--tenant' => $t->id, '--no-pause' => true])
            ->expectsOutputToContain('daily cap 2 reached — 1 more did not fit today; they stay due for the next run')
            ->assertSuccessful();

        $this->assertSame(['211911000103', '211911000102'], array_column($this->gw->sent, 0), 'hot, then the price question; the hello waits');
        Bus::assertDispatchedTimes(NotifyOwner::class, 1);
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => str_contains($j->text, '(2):') && str_contains($j->text, '+211911000103 — Starlink') && str_contains($j->text, '+211911000102 — Fiber'));
    }

    public function test_switched_off_or_closed(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business(['isp_chat_followup' => false]);
        $this->line($t, 'in', 'How much is Starlink?', 30);
        $this->line($t, 'out', 'Which area are you in?', 29.9);
        $this->run1($t);
        $this->assertSame([], $this->gw->sent, 'switched off on its own');

        $t2 = $this->business(['business_hours' => 'Mon-Sat 9 AM - 6 PM']);
        $this->line($t2, 'in', 'How much is Starlink?', 30);
        $this->line($t2, 'out', 'Which area are you in?', 29.9);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-22 10:00', 'Africa/Juba'));   // a Sunday
        try {
            $this->run1($t2);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
        $this->assertSame([], $this->gw->sent, 'closed: nothing is sent');
    }
}
