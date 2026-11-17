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
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'quote_validity_days' => 14] + $settings]);
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

    private function gateway(): object
    {
        return new class implements \App\Contracts\WhatsAppGateway {
            public array $texts = [];
            public array $docs = [];
            public function sendText(string $i, string $to, string $m, ?array $q = null): array { $this->texts[] = [$to, $m]; return ['ok' => true]; }
            public function sendImage(string $i, string $to, string $m, string $c = ''): array { return ['ok' => true]; }
            public function sendDocument(string $i, string $to, string $media, string $file, string $caption = ''): array { $this->docs[] = [$to, $file, $caption, strlen($media)]; return ['ok' => true]; }
            public function markRead(string $i, string $id): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $i): array { return []; }
        };
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

        $this->assertTrue($r['sent']);
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

        $this->assertTrue($r['sent'], 'kits are ordered for the customer; stock 0 does not stop a quotation');
        $this->assertStringContainsString("One-time: USD 400.00\nMonthly: USD 50.00", $gw->docs[0][2]);
        $this->assertSame([], $gw->texts, 'no empty sentence is sent');
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
