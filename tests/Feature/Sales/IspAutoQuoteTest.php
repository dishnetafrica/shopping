<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bot\QuotationService;
use App\Services\Catalog\ProductWriteService;
use App\Services\Isp\IspAutoQuote;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The quotation the bot sends an Internet-provider customer from the chat (DishNet, 17 Nov 2026), with
 * the real database, price calculator, PDF and saved quotation. Product names and prices are test values.
 */
class IspAutoQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '211911000077';

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(array $settings = []): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => ['vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'quote_validity_days' => 14] + $settings]);
        app(TenantContext::class)->set($t->id);
        $w = app(ProductWriteService::class);
        $w->create($t, ['name' => 'Test Starlink Kit', 'price' => 400, 'stock' => 0, 'billing' => 'one_off', 'service_line' => 'starlink']);
        $w->create($t, ['name' => 'Test Starlink Plan', 'price' => 50, 'billing' => 'monthly', 'service_line' => 'starlink']);
        $w->create($t, ['name' => 'Fiber Test 20 Mbps', 'price' => 45, 'billing' => 'monthly', 'service_line' => 'fiber']);
        $w->create($t, ['name' => 'Fiber Test Installation', 'price' => 150, 'billing' => 'one_off', 'service_line' => 'fiber']);
        $w->create($t, ['name' => 'Test Hidden Kit', 'price' => 999, 'billing' => 'one_off', 'service_line' => 'starlink', 'hidden' => true]);
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'akol' . $t->id . '@t.test', 'password' => 'secret']);

        return $t;
    }

    private function gateway(array $docResult = ['key' => ['id' => 'X'], 'status' => 'PENDING']): object
    {
        return new class($docResult) implements \App\Contracts\WhatsAppGateway {
            public array $texts = [];
            public array $docs = [];
            public function __construct(private array $docResult) {}
            public function sendText(string $i, string $to, string $m, ?array $q = null): array { $this->texts[] = [$to, $m]; return ['ok' => true]; }
            public function sendImage(string $i, string $to, string $m, string $c = ''): array { return ['ok' => true]; }
            public function sendDocument(string $i, string $to, string $media, string $file, string $caption = ''): array { $this->docs[] = [$to, $file, $caption, strlen($media)]; return $this->docResult; }
            public function markRead(string $i, string $id): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $i): array { return []; }
        };
    }

    /** The quotation step log, so a failure says why. */
    private function steps(Tenant $t): string
    {
        return \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->orderBy('id')->get(['stage', 'detail'])
            ->map(fn ($e) => $e->stage . ': ' . $e->detail)->implode(' | ');
    }

    private function capture(Tenant $t, object $gw, string $reply): array
    {
        $c = Conversation::firstOrCreate(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER], ['instance' => $t->whatsapp_instance, 'state' => [], 'cart' => []]);

        return app(IspAutoQuote::class)->capture($t, $c, self::CUSTOMER, $reply, $gw);
    }

    public function test_a_fiber_quotation_is_priced_from_the_catalogue_with_its_installation_and_the_salesperson_is_alerted(): void
    {
        $this->assertTrue(app(QuotationService::class)->available(), 'the PDF engine is a composer requirement');
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $gw = $this->gateway();

        $r = $this->capture($t, $gw, "Sure — here is your quotation 📄\n<<QUOTE {\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>");

        $this->assertTrue($r['sent'], $this->steps($t));
        $this->assertSame([self::CUSTOMER, 'Sure — here is your quotation 📄'], $gw->texts[0], 'the model\'s sentence first, without the hidden line');
        $this->assertCount(1, $gw->docs);
        $this->assertStringContainsString("One-time: USD 150.00\nMonthly: USD 45.00", $gw->docs[0][2], 'catalogue prices, one-time and monthly apart');
        $this->assertStringContainsString('*Akol Deng* (+211922000001), your salesperson,', $r['reply']);

        $q = Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::CUSTOMER)->sole();
        $this->assertSame(['Fiber Test 20 Mbps', 'Fiber Test Installation'], $q->items()->orderBy('id')->pluck('name')->all(), 'the installation comes with the plan');
        $this->assertSame(150.0, round((float) $q->total, 2), 'the saved total is the one-time total');
        $this->assertSame('bot', (string) $q->source);
        $this->assertSame((int) User::where('tenant_id', $t->id)->where('role', 'sales')->value('id'), (int) $q->sales_exec_id, 'prepared by the customer\'s salesperson');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'Quotation') && str_contains($j->text, 'Call them today'));

        // The same request again (customer says "send it again"): the same quotation, no new number.
        $again = $this->capture($t, $gw, "Here it is.\n<<QUOTE {\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>");
        $this->assertFalse($again['sent']);
        $this->assertStringContainsString((string) $q->quote_no, $again['reply']);
        $this->assertSame(1, Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->count());
    }

    public function test_a_starlink_kit_with_no_stock_figure_is_still_quoted_with_its_plan(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $gw = $this->gateway();

        $r = $this->capture($t, $gw, "<<QUOTE {\"items\":[{\"name\":\"Test Starlink Kit\",\"qty\":1},{\"name\":\"Test Starlink Plan\",\"qty\":1}]}>>");

        $this->assertTrue($r['sent'], 'kits are ordered for the customer; stock 0 does not stop a quotation. ' . $this->steps($t));
        $this->assertStringContainsString("One-time: USD 400.00\nMonthly: USD 50.00", $gw->docs[0][2]);
        $this->assertSame([], $gw->texts, 'no empty sentence is sent');
    }

    /** DishNet, 21 Nov 2026: "we have to submit the quotation with hardware — we don't offer subscription only". */
    public function test_a_starlink_plan_alone_is_never_quoted_the_bot_asks_which_kit(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $gw = $this->gateway();

        $r = $this->capture($t, $gw, "Sure — here is your quotation 📄\n<<QUOTE {\"items\":[{\"name\":\"Test Starlink Plan\",\"qty\":1}]}>>");

        $this->assertFalse($r['sent']);
        $this->assertSame('Our Starlink plans come with a Starlink kit: we do not offer the subscription on its own. Shall I put the *Test Starlink Kit* on your quotation with the plan?',
            $r['reply'], 'no "here is your quotation" when none is sent; the hidden kit is never offered');
        $this->assertSame([], $gw->docs);
        $this->assertSame(0, Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->count(), 'nothing priced');
        Bus::assertNotDispatched(NotifyOwner::class);
        $this->assertStringContainsString('isp_no_kit', $this->steps($t));

        // The customer picks the kit: kit + plan is quoted as usual.
        $this->assertTrue($this->capture($t, $gw, "<<QUOTE {\"items\":[{\"name\":\"Test Starlink Kit\",\"qty\":1},{\"name\":\"Test Starlink Plan\",\"qty\":1}]}>>")['sent'], $this->steps($t));

        // A business that does sell the plan alone switches the rule off.
        $t->putSetting('isp_starlink_plan_needs_kit', false);
        $this->assertTrue($this->capture($t->fresh(), $gw, "<<QUOTE {\"items\":[{\"name\":\"Test Starlink Plan\",\"qty\":2}]}>>")['sent'], $this->steps($t));
    }

    public function test_what_cannot_be_quoted_automatically_goes_to_the_salesperson_and_nothing_is_priced(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $gw = $this->gateway();

        foreach (['{"items":[{"name":"Starlink Priority","qty":1}]}', '{"items":[{"name":"Test Hidden Kit","qty":1}]}', '{"items":[{"name":"Test Starlink Kit","qty":20}]}', '{"items":[{"name":"Fiber","qty":1}]}'] as $json) {
            $r = $this->capture($t, $gw, "Sure.\n<<QUOTE {$json}>>");
            $this->assertFalse($r['sent'], $json);
            $this->assertSame("Sure.\n\n" . IspAutoQuote::HANDOFF, $r['reply'], $json);
        }
        $this->assertSame([], $gw->docs);
        $this->assertSame(0, Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->count(), 'nothing priced');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'Starlink Priority'));
    }

    public function test_a_pdf_whatsapp_refused_is_never_reported_as_sent(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $gw = $this->gateway(['status' => 400, 'error' => 'Bad Request']);

        $r = $this->capture($t, $gw, "<<QUOTE {\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>");

        $this->assertFalse($r['sent'], $this->steps($t));
        $this->assertSame(IspAutoQuote::HANDOFF, $r['reply'], 'the customer is not told a PDF arrived');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'did not deliver the PDF'));
    }

    /** Live, 17 Nov 2026: "not like 250 people" (in decorative letters) was quoted the Residential plan. */
    public function test_a_large_starlink_setup_goes_to_the_salesperson_even_if_the_model_quotes_it(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        \App\Models\Message::create(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER, 'instance' => $t->whatsapp_instance, 'direction' => 'in', 'sender' => 'customer',
            'body' => "\u{1D67D}\u{1D698}\u{1D69D} \u{1D695}\u{1D692}\u{1D694}\u{1D68E} 250 \u{1D699}\u{1D68E}\u{1D698}\u{1D699}\u{1D695}\u{1D68E}"]);
        $gw = $this->gateway();

        $r = $this->capture($t, $gw, "Thank you, Peter! I will prepare your quotation. Sure — here is your quotation \u{1F4C4}\n<<QUOTE {\"customer_name\":\"peter\",\"items\":[{\"name\":\"Test Starlink Kit\",\"qty\":1},{\"name\":\"Test Starlink Plan\",\"qty\":1}]}>>");

        $this->assertFalse($r['sent']);
        $this->assertSame([], $gw->docs, 'no Residential quotation for 250 users');
        $this->assertSame("Thank you, Peter!\n\n" . sprintf(IspAutoQuote::LARGE, 250), $r['reply'], 'and the reply no longer claims a quotation is here');
        $this->assertSame(0, Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->count());
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'about 250 users') && str_contains($j->text, 'Peter'));

        // Fiber has no such ceiling: the same customer can still get a Fiber quotation.
        $this->assertTrue($this->capture($t, $gw, "<<QUOTE {\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>")['sent'], $this->steps($t));
    }

    /** Live, 17 Nov 2026: "My name is peter" — and the quotation was billed to a bare phone number. */
    public function test_the_name_the_customer_gave_goes_on_the_quotation_and_their_record(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();

        $r = $this->capture($t, $this->gateway(), "<<QUOTE {\"customer_name\":\"peter\",\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>");

        $this->assertTrue($r['sent'], $this->steps($t));
        $this->assertSame('Peter', (string) Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->value('customer_name'));
        $this->assertSame('Peter', (string) \App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', self::CUSTOMER)->value('name'));
    }

    /** Codex on #163: an unnamed quotation is not reused once the customer gives a name, and the name is kept. */
    public function test_a_name_given_later_gets_a_named_quotation(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $line = fn (string $name) => "<<QUOTE {\"customer_name\":\"{$name}\",\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>";

        $this->assertTrue($this->capture($t, $this->gateway(), $line(''))['sent'], $this->steps($t));
        $this->assertTrue($this->capture($t, $this->gateway(), $line('mary'))['sent'], 'a named quotation, not the unnamed one again');
        $this->assertSame(['', 'Mary'], Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->orderBy('id')->pluck('customer_name')->map(fn ($n) => (string) $n)->all());
        $this->assertFalse($this->capture($t, $this->gateway(), $line('mary'))['sent'], 'the named one is reused after that');
        $this->assertSame('Mary', (string) \App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', self::CUSTOMER)->value('name'));
    }

    /** Live, 17 Nov 2026: "I will confirm … with the team. Please hold on" — and nobody was told. */
    public function test_a_promise_to_follow_up_alerts_the_salesperson_once(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $turn = app(\App\Services\Isp\IspSalesTurn::class);
        $promise = 'I will confirm the prices for the router and cables with the team. Please hold on for a moment.';

        $this->assertSame($promise, $turn->afterReply($t, self::CUSTOMER, 'Check for me', $promise), 'the reply itself is unchanged');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'Bot promised a follow-up') && str_contains($j->text, 'Check for me'));

        $turn->afterReply($t, self::CUSTOMER, 'Any news?', 'Our team will get back to you shortly.');
        Bus::assertDispatchedTimes(NotifyOwner::class, 1);   // once per customer per 2 hours

        $turn->afterReply($t, '211911000088', 'Which area?', 'Which area are you in?');
        Bus::assertDispatchedTimes(NotifyOwner::class, 1);   // a question is not a promise
    }

    /** DishNet, 17 Nov 2026: "if the bot replied at night, tell them our team member will call in the morning". */
    public function test_at_night_the_customer_is_told_the_call_comes_when_the_office_opens(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business(['business_hours' => 'Mon–Sat 9 AM – 6 PM (closed Sundays)']);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-17 22:30', 'Africa/Juba'));   // a Tuesday night
        try {
            $r = $this->capture($t, $this->gateway(), "<<QUOTE {\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>");
            $this->assertTrue($r['sent'], $this->steps($t));
            $this->assertStringContainsString('will call you when our office opens (tomorrow at 9:00 AM)', $r['reply']);
            Bus::assertDispatched(NotifyOwner::class, fn ($j) => str_contains($j->text, 'call them when the office opens (tomorrow at 9:00 AM)'));

            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-18 11:00', 'Africa/Juba'));   // open
            $this->assertNull(\App\Services\Isp\IspSalesTurn::opensAt($t), 'in office hours nothing about timing is added');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_switched_off_the_line_is_left_for_removal_and_nothing_is_sent(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business(['isp_auto_quote' => false]);
        $gw = $this->gateway();

        $reply = "Sure.\n<<QUOTE {\"items\":[{\"name\":\"Fiber Test 20 Mbps\",\"qty\":1}]}>>";
        $this->assertSame(['reply' => $reply, 'sent' => false], $this->capture($t, $gw, $reply));
        $this->assertSame([], $gw->docs);
        Bus::assertNotDispatched(NotifyOwner::class);
    }
}
