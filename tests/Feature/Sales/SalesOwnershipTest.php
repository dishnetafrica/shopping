<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bot\LeadService;
use App\Services\Bot\QuotationService;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sales team, Phase 1 (DishNet South Sudan plan): one salesperson per customer on an
 * "Internet provider" business, every panel reply attributed to who sent it, and every other
 * business unchanged. Real database, real endpoints; the WhatsApp provider is a recorder.
 */
class SalesOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const ALI   = '211911000001';   // customers
    private const BETTY = '211911000002';
    private const CHOL  = '211911000003';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NotifyOwner::class]);
        config(['whatsapp.default' => 'evolution', 'whatsapp.drivers.evolution.base_url' => 'http://evolution.test', 'whatsapp.drivers.evolution.api_key' => 'k']);
        Http::fake(fn () => Http::response(['key' => ['id' => 'OUT']], 200));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical = 'isp', array $settings = []): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution',
            'settings' => array_merge(['vertical' => $vertical, 'timezone' => 'Africa/Juba'], $settings)]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function staff(Tenant $t, string $role, string $name, ?string $phone = null): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => $name, 'role' => $role, 'phone' => $phone,
            'email' => strtolower($name) . '-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    /** A sales team: two salespeople with WhatsApp numbers and a manager. */
    private function team(Tenant $t): array
    {
        return [
            $this->staff($t, 'sales', 'Akol', '211922000001'),
            $this->staff($t, 'sales', 'Bol', '211922000002'),
            $this->staff($t, 'manager', 'Mary', '211922000009'),
        ];
    }

    private function enquiry(Tenant $t, string $phone, string $text): void
    {
        app(TenantContext::class)->set($t->id);
        app(LeadService::class)->capture($t, $phone, 'Customer', $text, 'lead');
    }

    private function ownerOf(Tenant $t, string $phone): ?int
    {
        $id = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->value('owner_user_id');

        return $id === null ? null : (int) $id;
    }

    private function reply(User $as, string $phone, string $body = 'Hello from DishNet')
    {
        $this->actingAs($as);
        app(TenantContext::class)->set($as->tenant_id);

        return $this->postJson('/papi/chats/send', ['phone' => $phone, 'body' => $body]);
    }

    // ---- routing ------------------------------------------------------------------------------

    public function test_a_new_customer_goes_to_the_next_salesperson_and_every_later_enquiry_stays_with_them(): void
    {
        $t = $this->business();
        [$akol, $bol] = $this->team($t);

        $this->enquiry($t, self::ALI, 'I need Starlink for my home');
        $this->enquiry($t, self::BETTY, 'Fiber for our office please');
        $this->enquiry($t, self::ALI, 'Also want fiber for my shop');          // a different ask from the same customer

        $this->assertSame([$akol->id, $bol->id], [$this->ownerOf($t, self::ALI), $this->ownerOf($t, self::BETTY)]);
        $aliLeads = Lead::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::ALI)->get();
        $this->assertCount(2, $aliLeads, 'two different asks are two leads');
        $this->assertSame([$akol->id, $akol->id], $aliLeads->pluck('owner_user_id')->map(fn ($v) => (int) $v)->all(), 'but both stay with Ali\'s salesperson');
        $this->assertSame(['211922000001', '211922000001'], $aliLeads->pluck('assigned_to')->all(), 'alerts go to that salesperson\'s WhatsApp');
        Bus::assertDispatched(NotifyOwner::class, fn ($job) => $job->to === '211922000002');
    }

    public function test_with_no_salesperson_set_up_the_enquiry_waits_unassigned_for_a_manager(): void
    {
        $t = $this->business();
        $this->staff($t, 'sales', 'NoPhone', null);                         // a salesperson with no WhatsApp number cannot be alerted

        $this->enquiry($t, self::ALI, 'I need Starlink');

        $this->assertNull($this->ownerOf($t, self::ALI));
        $this->assertNull(Lead::withoutGlobalScopes()->where('tenant_id', $t->id)->value('owner_user_id'));
    }

    // ---- the inbox ----------------------------------------------------------------------------

    public function test_a_salesperson_cannot_reply_to_a_colleagues_customer_and_the_first_reply_claims_an_unassigned_one(): void
    {
        $t = $this->business();
        [$akol, $bol, $mary] = $this->team($t);
        $this->enquiry($t, self::ALI, 'I need Starlink');                 // Ali → Akol

        $this->reply($bol, self::ALI)->assertStatus(403)->assertJson(['ok' => false, 'error' => 'owned_by_other', 'owner' => 'Akol']);
        $this->reply($akol, self::ALI)->assertOk()->assertJson(['ok' => true]);

        $this->reply($bol, self::CHOL)->assertOk();                         // Chol had no salesperson: Bol's reply makes him Bol's
        $this->assertSame($bol->id, $this->ownerOf($t, self::CHOL));
        $this->reply($akol, self::CHOL)->assertStatus(403);

        $this->reply($mary, self::ALI)->assertOk();                         // a manager is never blocked
        $this->assertSame($akol->id, $this->ownerOf($t, self::ALI), 'and a manager replying does not take the customer');
    }

    public function test_every_panel_reply_records_who_sent_it_and_other_messages_record_nobody(): void
    {
        $t = $this->business();
        [$akol] = $this->team($t);
        $this->enquiry($t, self::ALI, 'I need Starlink');

        $this->reply($akol, self::ALI, 'Welcome to DishNet')->assertOk();
        MessageLog::record($t->id, self::ALI, $t->whatsapp_instance, 'in', 'customer', 'How much is the kit?');

        $out = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('body', 'Welcome to DishNet')->sole();
        $this->assertSame($akol->id, (int) $out->sender_user_id);
        $this->assertNull(Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->value('sender_user_id'));
    }

    public function test_salespeople_see_their_own_and_unassigned_customers_managers_see_everyone(): void
    {
        $t = $this->business();
        [$akol, $bol, $mary] = $this->team($t);
        $this->enquiry($t, self::ALI, 'Starlink please');                  // → Akol
        $this->enquiry($t, self::BETTY, 'Fiber please');                   // → Bol
        MessageLog::record($t->id, self::CHOL, $t->whatsapp_instance, 'in', 'customer', 'hello');   // unassigned chat
        foreach ([self::ALI, self::BETTY] as $p) {
            MessageLog::record($t->id, $p, $t->whatsapp_instance, 'in', 'customer', 'hi');
        }

        $see = function (User $u, string $screen) {
            $this->actingAs($u);
            app(TenantContext::class)->set($u->tenant_id);

            return json_encode($this->getJson('/api/v1/workspace/' . $screen)->assertOk()->json());
        };
        $chats = $see($akol, 'chats');
        $this->assertStringContainsString(self::ALI, $chats);
        $this->assertStringContainsString(self::CHOL, $chats, 'unassigned chats are visible so someone picks them up');
        $this->assertStringNotContainsString(self::BETTY, $chats, 'a colleague\'s customer is not');
        $this->assertStringNotContainsString(self::BETTY, $see($akol, 'customers'));
        $this->assertStringNotContainsString(self::BETTY, $see($akol, 'leads'));
        $this->assertStringContainsString(self::BETTY, $see($mary, 'chats'));
        $this->assertStringContainsString(self::ALI, $see($mary, 'customers'));

        // Opening a colleague's chat or customer directly is refused.
        $this->actingAs($akol);
        app(TenantContext::class)->set($t->id);
        $bettyChat = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::BETTY)->value('id');
        $bettyCust = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', self::BETTY)->value('id');
        $this->assertSame('not_found', $this->getJson('/api/v1/workspace/chat/' . $bettyChat)->json('meta.error'));
        $this->getJson('/api/v1/workspace/customer/' . $bettyCust)->assertNotFound();
        $this->getJson('/papi/chats/thread?phone=' . self::BETTY)->assertStatus(403);
    }

    public function test_a_new_chat_with_an_existing_customer_starts_with_their_salesperson(): void
    {
        $t = $this->business();
        [$akol] = $this->team($t);
        $this->enquiry($t, self::ALI, 'Starlink please');

        $c = Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::ALI, 'instance' => 'second_number', 'state' => [], 'cart' => []]);

        $this->assertSame($akol->id, (int) $c->owner_user_id);
    }

    // ---- reassignment -------------------------------------------------------------------------

    public function test_only_an_owner_or_manager_can_move_a_customer_and_every_move_is_logged(): void
    {
        $t = $this->business();
        [$akol, $bol, $mary] = $this->team($t);
        $this->enquiry($t, self::ALI, 'Starlink please');                  // → Akol
        MessageLog::record($t->id, self::ALI, $t->whatsapp_instance, 'in', 'customer', 'hi');
        $cust = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', self::ALI)->value('id');
        $move = fn (User $as, array $in) => $this->actingAs($as)->postJson('/api/v1/workspace/customer/' . $cust . '/reassign_owner', $in);

        app(TenantContext::class)->set($t->id);
        $move($akol, ['owner' => $bol->id . ' — Bol', 'reason' => 'Bol covers Juba town'])->assertJson(['ok' => false, 'error' => 'forbidden']);
        $move($mary, ['owner' => $bol->id . ' — Bol', 'reason' => ''])->assertJson(['ok' => false, 'error' => 'reason_required']);
        $outsider = $this->staff($this->business('isp'), 'sales', 'Outsider', '211933000000');
        app(TenantContext::class)->set($t->id);
        $move($mary, ['owner' => $outsider->id . ' — Outsider', 'reason' => 'x'])->assertJson(['ok' => false, 'error' => 'unknown_staff']);

        $move($mary, ['owner' => $bol->id . ' — Bol', 'reason' => 'Bol covers Juba town'])->assertJson(['ok' => true]);

        $this->assertSame($bol->id, $this->ownerOf($t, self::ALI));
        $this->assertSame($bol->id, (int) Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::ALI)->value('owner_user_id'));
        $this->assertSame($bol->id, (int) Lead::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::ALI)->value('owner_user_id'), 'the open lead moves too');
        $log = DB::table('audit_logs')->where('tenant_id', $t->id)->where('entity_type', 'customer_owner')->sole();
        $this->assertSame(['reassign', 'Bol covers Juba town', (int) $mary->id], [$log->action, $log->reason, (int) $log->actor_id]);
        $this->reply($akol, self::ALI)->assertStatus(403);                  // Akol no longer handles Ali
        $this->reply($bol, self::ALI)->assertOk();
    }

    // ---- quotations ---------------------------------------------------------------------------

    public function test_a_quotation_records_who_prepared_it(): void
    {
        $t = $this->business();
        [$akol, $bol] = $this->team($t);
        $this->enquiry($t, self::ALI, 'Starlink please');                  // → Akol
        $quote = ['currency' => 'USD', 'total' => 100, 'lines' => []];

        $this->actingAs($bol);
        $byStaff = app(QuotationService::class)->persist($t, self::ALI, 'Ali', $quote, ['no' => 'Q-1', 'total' => 100], 'panel');
        $this->assertSame($bol->id, (int) $byStaff->sales_exec_id, 'made in the panel: the person who made it');

        auth()->logout();
        $byBot = app(QuotationService::class)->persist($t, self::ALI, 'Ali', $quote, ['no' => 'Q-2', 'total' => 100], 'bot');
        $this->assertSame($akol->id, (int) $byBot->sales_exec_id, 'drafted by the bot: the customer\'s salesperson');
    }

    // ---- permissions and other businesses -----------------------------------------------------

    public function test_salespeople_get_the_inbox_only_on_an_internet_provider_business(): void
    {
        $isp = $this->business('isp');
        $shop = $this->business('grocery');

        $this->assertContains('whatsapp', $this->staff($isp, 'sales', 'S1', '211900000001')->effectiveMenus());
        $this->assertNotContains('whatsapp', $this->staff($shop, 'sales', 'S2', '256700000001')->effectiveMenus(), 'a grocery salesperson keeps the menus they had');
    }

    public function test_a_grocery_business_routes_and_shows_exactly_as_before(): void
    {
        $shop = $this->business('grocery', ['lead_recipients' => [['phone' => '256700000111', 'role' => 'sales', 'name' => 'Rep']]]);
        $s1 = $this->staff($shop, 'sales', 'GrocerySales1', '256700000222');
        $s2 = $this->staff($shop, 'sales', 'GrocerySales2', '256700000333');

        $this->enquiry($shop, '256711000001', 'I want 10 bags of sugar delivered');
        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $shop->id)->sole();
        $this->assertSame('256700000111', $lead->assigned_to, 'still the configured lead recipient');
        $this->assertNull($lead->owner_user_id);
        $this->assertNull($this->ownerOf($shop, '256711000001'));

        $this->reply($s1, '256711000001')->assertOk();
        $this->reply($s2, '256711000001')->assertOk();                     // no ownership rule for a shop
        $this->assertNull($this->ownerOf($shop, '256711000001'));
    }
}
