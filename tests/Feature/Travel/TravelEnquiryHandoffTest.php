<?php

namespace Tests\Feature\Travel;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryNotification;
use App\Models\User;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Enquiry\TravelPipeline;
use App\Services\Travel\Handoff\StaffHandoff;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Travel enquiry MVP (issue #145): the whole enquiry, a customer's confirmation, and the handoff to
 * the agency's own staff — through the real services and a real database.
 *
 * The AI model is not called. The WhatsApp provider is a recorder, so these tests read exactly which
 * numbers a staff alert was sent to and what it said. No real message is sent by any of them.
 */
class TravelEnquiryHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '256700111222';
    private const PUBLIC_NUMBER = '256788000111';      // the number customers write to
    private const STAFF_A = '256701000001';
    private const STAFF_B = '256702000002';

    /** When true the recorder answers "server error" to every send. */
    private bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NotifyOwner::class]);
        config(['whatsapp.default' => 'evolution', 'whatsapp.drivers.evolution.base_url' => 'http://evolution.test', 'whatsapp.drivers.evolution.api_key' => 'k', 'app.url' => 'https://panel.test']);
        Http::fake(fn () => $this->providerDown ? Http::response(['error' => 'down'], 500) : Http::response(['key' => ['id' => 'OUT']], 200));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function agency(?string $staff = self::STAFF_A . "\n" . self::STAFF_B, string $vertical = 'travel'): Tenant
    {
        $t = Tenant::factory()->create([
            'whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'whatsapp_number' => self::PUBLIC_NUMBER,
            'settings' => array_filter(['vertical' => $vertical, 'timezone' => 'Africa/Kampala', StaffHandoff::SETTING => $staff]),
        ]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function convo(Tenant $t, string $phone = self::CUSTOMER, string $instance = 'main', array $state = []): Conversation
    {
        return Conversation::create(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => $instance, 'state' => $state, 'cart' => []]);
    }

    private function say(Tenant $t, Conversation $c, string $customer, string $modelReply, string $channel = 'whatsapp'): string
    {
        return app(TravelTurn::class)->afterReply($t, $c, (string) $c->customer_phone, $channel, $customer, $modelReply);
    }

    /** A complete safari enquiry, summary shown, waiting for the customer's yes. */
    private function safari(Tenant $t, ?Conversation $c = null, string $channel = 'whatsapp'): array
    {
        $c ??= $this->convo($t);
        $this->say($t, $c, 'I want a gorilla safari', "Wonderful! Where would you like to go?\n<<TRIP {\"services\":[\"safari\"]}>>", $channel);
        $this->say($t, $c, 'Bwindi then Queen Elizabeth, in July', "Lovely.\n<<TRIP {\"destinations\":[\"Bwindi\",\"Queen Elizabeth National Park\"],\"date_text\":\"July\"}>>", $channel);
        $reply = $this->say($t, $c, '2 adults and 2 kids aged 6 and 9', "Noted.\n<<TRIP {\"adults\":2,\"children\":2,\"child_ages\":[6,9]}>>", $channel);

        return [$c, $reply];
    }

    /** @return array<int,array{number:string,text:string}> every WhatsApp message the code tried to send */
    private function sends(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/message/sendText/'))
            ->map(fn ($pair) => ['number' => (string) $pair[0]['number'], 'text' => (string) $pair[0]['text']])->values()->all();
    }

    private function user(Tenant $t, string $role = 'owner'): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => ucfirst($role), 'role' => $role, 'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    // ---- qualification over several messages ---------------------------------------------------

    public function test_a_safari_enquiry_is_built_over_several_messages_and_nothing_is_sent_before_the_customer_confirms(): void
    {
        $t = $this->agency();
        [$c, $reply] = $this->safari($t);

        $this->assertSame(1, TravelEnquiry::count(), 'one enquiry for the whole conversation');
        $e = TravelEnquiry::first();
        $state = $e->state();
        $this->assertSame(['safari'], $state['brief']['services']);
        $this->assertSame(['Bwindi', 'Queen Elizabeth National Park'], $state['brief']['destinations']);
        $this->assertSame('July', $state['brief']['date_text']);
        $this->assertSame([6, 9], $state['brief']['child_ages']);
        $this->assertSame([2, 2], [(int) $e->adults, (int) $e->children]);
        $this->assertSame([], $state['segments'], 'a safari is not turned into a flight');
        $this->assertSame('Bwindi → Queen Elizabeth National Park', $e->destination);
        $this->assertSame(TravelPipeline::DETAILS_COLLECTED, $e->stage);

        $this->assertStringContainsString('Destinations: Bwindi → Queen Elizabeth National Park', $reply);
        $this->assertStringContainsString("Children's ages: 6, 9", $reply);
        $this->assertStringContainsString(TravelTurn::ASK_TO_CONFIRM, $reply);
        $this->assertNull($e->submitted_at);
        $this->assertSame([], $this->sends(), 'no staff alert before the customer confirms');
    }

    public function test_a_correction_changes_one_detail_and_asks_for_confirmation_again(): void
    {
        $t = $this->agency();
        [$c] = $this->safari($t);

        $reply = $this->say($t, $c, 'actually 3 adults', "Updated.\n<<TRIP {\"adults\":3}>>");

        $e = TravelEnquiry::first();
        $this->assertSame(3, (int) $e->adults);
        $this->assertSame(['Bwindi', 'Queen Elizabeth National Park'], $e->state()['brief']['destinations'], 'everything else is kept');
        $this->assertStringContainsString('Updated trip request', $reply);
        $this->assertStringContainsString(TravelTurn::ASK_TO_CONFIRM, $reply);
        $this->assertSame([], $this->sends());
    }

    public function test_children_without_matching_ages_are_questioned_not_stored(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $reply = $this->say($t, $c, 'hotel in Zanzibar for 2 adults 1 child aged 5, 8 and 12', "Ok.\n<<TRIP {\"services\":[\"hotel\"],\"destinations\":[\"Zanzibar\"],\"adults\":2,\"children\":1,\"child_ages\":[5,8,12]}>>");

        $this->assertStringContainsString('does not match the number of children', $reply);
        $this->assertSame([], TravelEnquiry::first()->state()['brief']['child_ages']);
        $this->assertFalse(TravelEnquiry::first()->isSubmitted());
    }

    // ---- confirmation and the single submission ------------------------------------------------

    public function test_the_customers_yes_submits_once_and_alerts_only_the_staff_numbers(): void
    {
        $t = $this->agency();
        [$c] = $this->safari($t);

        $reply = $this->say($t, $c, 'Yes', "Thank you!\n<<TRIP {\"confirm\":true}>>");

        $e = TravelEnquiry::first();
        $this->assertNotNull($e->submitted_at);
        $this->assertSame('sent', $e->handoff_status);
        $this->assertStringContainsString('has been passed to our travel team', $reply);
        $this->assertStringContainsString($e->reference(), $reply);

        $sends = $this->sends();
        $this->assertEqualsCanonicalizing([self::STAFF_A, self::STAFF_B], array_column($sends, 'number'));
        $this->assertNotContains(self::PUBLIC_NUMBER, array_column($sends, 'number'), 'never the number customers write to');
        $this->assertNotContains(self::CUSTOMER, array_column($sends, 'number'), 'the staff brief never goes to the customer');
        $this->assertStringContainsString('New travel enquiry ' . $e->reference(), $sends[0]['text']);
        $this->assertStringContainsString('Destinations: Bwindi → Queen Elizabeth National Park', $sends[0]['text']);
        $this->assertStringContainsString('+' . self::CUSTOMER, $sends[0]['text']);
        $this->assertStringContainsString('https://panel.test/v3#/trip/' . $e->id, $sends[0]['text']);
        $this->assertStringContainsString('No price has been given to the customer', $sends[0]['text']);
        $this->assertSame(2, TravelEnquiryNotification::withoutGlobalScopes()->where('status', 'sent')->count());
        $this->assertStringStartsWith('Travel:', (string) Lead::find($e->lead_id)->interest);
    }

    public function test_a_plain_yes_is_enough_when_the_model_forgets_to_report_it(): void
    {
        $t = $this->agency();
        [$c] = $this->safari($t);

        $reply = $this->say($t, $c, 'yes please', 'Great, thank you.');          // no TRIP line at all

        $this->assertNotNull(TravelEnquiry::first()->submitted_at);
        $this->assertStringContainsString('has been passed to our travel team', $reply);
        $this->assertCount(2, $this->sends());
    }

    public function test_confirming_twice_does_not_create_or_send_anything_twice(): void
    {
        $t = $this->agency();
        [$c] = $this->safari($t);

        $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");
        $this->say($t, $c, 'yes', "Thanks again.\n<<TRIP {\"confirm\":true}>>");
        $this->say($t, $c, 'ok', 'You are welcome.');

        $this->assertSame(1, TravelEnquiry::count());
        $this->assertCount(2, $this->sends(), 'one alert per staff number, once');
        $this->assertSame(2, TravelEnquiryNotification::withoutGlobalScopes()->count());
        $this->assertSame(1, \App\Models\TravelEnquiryEvent::where('type', 'submitted')->count());
    }

    public function test_a_yes_that_carries_a_change_is_not_a_confirmation(): void
    {
        $t = $this->agency();
        [$c] = $this->safari($t);

        $reply = $this->say($t, $c, 'yes but make it 4 adults', "Sure.\n<<TRIP {\"adults\":4,\"confirm\":true}>>");

        $this->assertNull(TravelEnquiry::first()->submitted_at, 'the changed summary must be seen and agreed first');
        $this->assertStringContainsString(TravelTurn::ASK_TO_CONFIRM, $reply);
        $this->assertSame([], $this->sends());
    }

    public function test_a_change_after_submission_tells_staff_once_about_the_new_details(): void
    {
        $t = $this->agency(self::STAFF_A);
        [$c] = $this->safari($t);
        $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");

        $reply = $this->say($t, $c, 'we will be 3 adults', "Updated.\n<<TRIP {\"adults\":3}>>");

        $sends = $this->sends();
        $this->assertCount(2, $sends);
        $this->assertStringContainsString('Travel enquiry updated', $sends[1]['text']);
        $this->assertStringContainsString('3 adults', $sends[1]['text']);
        $this->assertStringContainsString('has been sent the updated details', $reply);
        $this->assertStringNotContainsString(TravelTurn::ASK_TO_CONFIRM, $reply, 'an enquiry already with the team is not confirmed again');
        $this->assertSame(1, TravelEnquiry::count());
    }

    // ---- recipients ----------------------------------------------------------------------------

    public function test_with_no_staff_number_the_enquiry_is_saved_nobody_is_messaged_and_the_customer_is_not_told_otherwise(): void
    {
        $t = $this->agency(null);
        [$c] = $this->safari($t);

        $reply = $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");

        $e = TravelEnquiry::first();
        $this->assertNotNull($e->submitted_at, 'the enquiry is kept');
        $this->assertSame('no_recipient', $e->handoff_status);
        $this->assertSame([], $this->sends(), 'nothing is sent anywhere — above all not to the public number');
        $this->assertStringContainsString('is recorded', $reply);
        $this->assertStringNotContainsString('passed to our travel team', $reply, 'the customer is not told staff were alerted when nobody was');

        $this->actingAs($this->user($t));
        $list = $this->getJson('/api/v1/workspace/trips')->assertOk()->json();
        $this->assertStringContainsString('no staff WhatsApp number is set', json_encode($list), 'the admin sees a warning on the list');
        $detail = json_encode($this->getJson('/api/v1/workspace/trip/' . $e->id)->assertOk()->json());
        $this->assertStringContainsString('No staff WhatsApp number is set', $detail);
    }

    public function test_the_public_customer_number_is_never_used_as_a_staff_number_even_if_someone_enters_it(): void
    {
        $t = $this->agency('+256 788 000 111, 0788000111, ' . self::STAFF_A);       // the public number twice, in two formats
        [$c] = $this->safari($t);

        $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");

        $this->assertSame([self::STAFF_A], array_column($this->sends(), 'number'));
        $status = app(StaffHandoff::class)->status($t);
        $this->assertSame([self::STAFF_A], $status['recipients']);
        $this->assertCount(2, $status['rejected']);
    }

    public function test_only_the_public_number_configured_means_nobody_is_alerted(): void
    {
        $t = $this->agency(self::PUBLIC_NUMBER);
        [$c] = $this->safari($t);

        $reply = $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");

        $this->assertSame([], $this->sends());
        $this->assertSame('no_recipient', TravelEnquiry::first()->handoff_status);
        $this->assertStringContainsString('is recorded', $reply);
    }

    // ---- failures and retries ------------------------------------------------------------------

    public function test_a_failed_alert_is_recorded_the_customer_is_not_misled_and_staff_can_retry(): void
    {
        $t = $this->agency(self::STAFF_A);
        [$c] = $this->safari($t);
        $this->providerDown = true;

        $reply = $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");

        $e = TravelEnquiry::first();
        $this->assertNotNull($e->submitted_at, 'the enquiry is not lost when the alert fails');
        $this->assertSame('failed', $e->handoff_status);
        $row = TravelEnquiryNotification::withoutGlobalScopes()->first();
        $this->assertSame(['failed', 1], [$row->status, (int) $row->attempts]);
        $this->assertNotEmpty($row->last_error);
        $this->assertStringNotContainsString('passed to our travel team', $reply);
        $this->assertStringContainsString('is recorded', $reply);

        // The list shows it; a member of staff retries from the trip.
        $this->actingAs($this->user($t));
        $this->assertStringContainsString('Alerts not delivered', json_encode($this->getJson('/api/v1/workspace/trips')->json()));
        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/retry_handoff')->assertJson(['ok' => false, 'error' => 'send_failed']);
        $this->assertSame(2, (int) $row->fresh()->attempts);

        $this->providerDown = false;
        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/retry_handoff')->assertJson(['ok' => true]);
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame('sent', $e->fresh()->handoff_status);
        $this->assertSame(1, TravelEnquiryNotification::withoutGlobalScopes()->count(), 'a retry reuses the record; it never duplicates it');
    }

    public function test_the_scheduled_retry_delivers_an_alert_that_failed_earlier(): void
    {
        $t = $this->agency(self::STAFF_A);
        [$c] = $this->safari($t);
        $this->providerDown = true;
        $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");
        $this->providerDown = false;

        $this->artisan('travel:retry-handoffs')->assertSuccessful();
        $this->assertSame('failed', TravelEnquiryNotification::withoutGlobalScopes()->first()->status, 'not retried within five minutes of the last attempt');

        TravelEnquiryNotification::withoutGlobalScopes()->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('travel:retry-handoffs')->assertSuccessful();
        $this->assertSame('sent', TravelEnquiryNotification::withoutGlobalScopes()->first()->status);
        $this->assertSame('sent', TravelEnquiry::first()->handoff_status);
    }

    public function test_staff_can_send_an_enquiry_once_a_number_has_been_added(): void
    {
        $t = $this->agency(null);
        [$c] = $this->safari($t);
        $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");
        $e = TravelEnquiry::first();
        $this->actingAs($this->user($t));

        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/retry_handoff')->assertJson(['ok' => false, 'error' => 'no_recipient']);

        $t->settings = array_merge($t->settings, [StaffHandoff::SETTING => self::STAFF_B]);
        $t->save();
        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/retry_handoff')->assertJson(['ok' => true]);
        $this->assertSame([self::STAFF_B], array_column($this->sends(), 'number'));
        $this->assertSame('sent', $e->fresh()->handoff_status);
    }

    // ---- website chat --------------------------------------------------------------------------

    public function test_a_website_enquiry_keeps_the_visitors_contact_details_and_is_handed_off_the_same_way(): void
    {
        $t = $this->agency(self::STAFF_A);
        $c = $this->convo($t, 'web:abcdef0123456789', 'web', ['contact_name' => 'Sarah Namusoke', 'contact_phone' => '256755123456']);
        $this->safari($t, $c, 'web');

        $reply = $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>", 'web');

        $e = TravelEnquiry::first();
        $this->assertSame('web', $e->channel);
        $this->assertSame(['name' => 'Sarah Namusoke', 'phone' => '256755123456', 'email' => null], $e->state()['brief']['contact']);
        $this->assertNotNull($e->lead_id, 'the visitor becomes a lead under the number they gave');
        $this->assertStringContainsString('has been passed to our travel team', $reply);
        $sends = $this->sends();
        $this->assertSame([self::STAFF_A], array_column($sends, 'number'));
        $this->assertStringContainsString('website chat', $sends[0]['text']);
        $this->assertStringContainsString('Sarah Namusoke · +256755123456', $sends[0]['text']);
    }

    // ---- isolation and permissions -------------------------------------------------------------

    public function test_one_agencys_enquiry_never_reaches_another_agencys_staff_or_screens(): void
    {
        $other = $this->agency('256709999999');
        $mine  = $this->agency(self::STAFF_A);
        [$c] = $this->safari($mine);
        $this->say($mine, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");
        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $mine->id)->first();

        $this->assertSame([self::STAFF_A], array_column($this->sends(), 'number'), 'only this agency\'s staff');

        app(TenantContext::class)->set($other->id);
        $this->actingAs($this->user($other));
        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/retry_handoff')->assertJson(['ok' => false, 'error' => 'not_found']);
        $this->assertStringNotContainsString('Bwindi', json_encode($this->getJson('/api/v1/workspace/trips')->json()));
        $this->assertCount(1, $this->sends(), 'the other agency cannot trigger a send');
    }

    public function test_a_role_without_access_to_customers_cannot_retry_a_staff_alert(): void
    {
        $t = $this->agency(self::STAFF_A);
        [$c] = $this->safari($t);
        $this->providerDown = true;
        $this->say($t, $c, 'Yes', "Thanks.\n<<TRIP {\"confirm\":true}>>");
        $this->providerDown = false;
        $before = count($this->sends());

        $this->actingAs($this->user($t, 'warehouse'));
        $this->postJson('/api/v1/workspace/trip/' . TravelEnquiry::first()->id . '/retry_handoff')->assertJson(['ok' => false, 'error' => 'forbidden']);

        $this->assertCount($before, $this->sends());
    }

    // ---- nothing invented ----------------------------------------------------------------------

    public function test_a_price_the_model_makes_up_for_a_safari_or_hotel_never_reaches_the_customer(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $reply = $this->say($t, $c, 'how much is a gorilla safari?',
            "A gorilla permit costs USD 800 per person and a mid-range lodge is about \$350 per night. Rooms are available in July.\n<<TRIP {\"services\":[\"safari\"]}>>");

        $this->assertStringNotContainsString('800', $reply);
        $this->assertStringNotContainsString('350', $reply);
        $this->assertStringNotContainsString('Rooms are available', $reply, 'availability is not the model\'s to state');
        $this->assertStringContainsString('Our travel team will confirm', $reply);
    }

    public function test_a_travel_agency_never_runs_the_retail_order_or_quotation_pipeline(): void
    {
        config(['openai.api_key' => 'test-key']);
        $answer = fn () => \OpenAI\Responses\Chat\CreateResponse::fake(['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant',
            'content' => "Here is your quotation.\n<<QUOTE {\"version\":2,\"items\":[{\"name\":\"Gorilla safari\",\"qty\":2}]}>>\n<<ORDER {\"version\":2,\"items\":[{\"name\":\"Gorilla safari\",\"quantity\":{\"value\":2,\"unit\":\"pcs\"}}]}>>\n<<TRIP {\"services\":[\"safari\"],\"adults\":2}>>"]]]]);
        \OpenAI\Laravel\Facades\OpenAI::fake([$answer(), $answer(), $answer(), $answer()]);
        $t = $this->agency();
        \App\Models\Product::create(['tenant_id' => $t->id, 'name' => 'Gorilla safari', 'price' => 5000000, 'base_price' => 5000000, 'stock' => 10, 'active' => true]);
        $c = $this->convo($t);
        $gateway = new \App\Services\Bot\CollectorGateway();

        app(\App\Services\Bot\AiBrain::class)->handle($t, $c, self::CUSTOMER, 'gorilla safari for 2 please, send me a quotation and order it', $gateway);

        $this->assertSame(0, \App\Models\Order::withoutGlobalScopes()->where('tenant_id', $t->id)->count(), 'no retail order');
        if (class_exists(\App\Models\Quotation::class)) {
            $this->assertSame(0, \App\Models\Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->count(), 'no computed quotation');
        }
        $this->assertSame([], (array) $c->fresh()->cart, 'no cart');
        $said = json_encode(\App\Models\Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'out')->pluck('body'));
        foreach (['<<QUOTE', '<<ORDER', '<<TRIP', '5000000', '5,000,000'] as $never) {
            $this->assertStringNotContainsString($never, $said);
        }
        $this->assertSame(['safari'], TravelEnquiry::first()->state()['brief']['services'], 'the enquiry is on the travel record instead');
    }

    // ---- website -------------------------------------------------------------------------------

    public function test_a_travel_agencys_website_is_an_enquiry_page_with_no_shop_or_cart(): void
    {
        $t = $this->agency();
        $t->settings = array_merge($t->settings, ['travel_services' => 'air ticketing, safaris, visa processing', 'web_chat' => true]);
        $t->save();

        foreach (['/' . $t->slug, '/' . $t->slug . '/shop', '/' . $t->slug . '/chat'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('Tell us about your trip', $html, $path);
            $this->assertStringContainsString('"services":["air ticketing","safaris","visa processing"]', $html);
            foreach (['Add to cart', 'View Cart', 'checkout', 'chatOnlyCss'] as $retail) {
                $this->assertStringNotContainsStringIgnoringCase($retail, $html, "$path must not show: $retail");
            }
        }
        $this->assertStringContainsString('"openChat":true', $this->get('/' . $t->slug . '/chat')->getContent());
    }

    public function test_grocery_and_restaurant_websites_are_exactly_the_shop_they_were(): void
    {
        foreach (['grocery', 'restaurant'] as $vertical) {
            $t = $this->agency(null, $vertical);
            $html = $this->get('/' . $t->slug)->assertOk()->getContent();
            $this->assertStringNotContainsString('Tell us about your trip', $html, $vertical);
            $this->assertStringContainsString('chatOnlyCss', $html, "$vertical still gets the shop page");
        }
    }

    public function test_the_customers_own_budget_is_recorded_as_theirs_and_may_be_repeated(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $reply = $this->say($t, $c, 'our budget is 3000 dollars per person', "Noted, a budget of 3000 dollars per person.\n<<TRIP {\"services\":[\"tour\"],\"budget\":3000,\"budget_currency\":\"USD\",\"budget_per\":\"person\"}>>");

        $this->assertSame(['amount' => 3000, 'currency' => 'USD', 'per' => 'person'], TravelEnquiry::first()->state()['brief']['budget']);
        $this->assertStringContainsString('3000', $reply, 'the customer\'s own figure is not treated as an invented price');
    }

    // ---- draft pilot profile -------------------------------------------------------------------

    public function test_the_draft_pilot_profile_sets_no_price_no_term_and_no_staff_number_and_is_refused_on_production(): void
    {
        $t = $this->agency(null, 'grocery');

        $this->artisan('travel:apply-profile', ['profile' => 'lazaros', '--tenant' => $t->id, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('grocery', $t->fresh()->setting('vertical'), 'a dry run changes nothing');

        $this->artisan('travel:apply-profile', ['profile' => 'lazaros', '--tenant' => $t->id, '--overwrite' => true])->assertSuccessful();
        $fresh = $t->fresh();
        $this->assertSame('travel', $fresh->setting('vertical'));
        $this->assertSame('draft', $fresh->setting('travel_profile_status'));
        $this->assertStringContainsString('safaris', (string) $fresh->setting('travel_services'));
        foreach ([StaffHandoff::SETTING, 'travel_booking_terms', 'travel_cancellation_policy', 'owner_alert_phone'] as $unset) {
            $this->assertEmpty($fresh->setting($unset), "$unset must come from the customer, not from the website");
        }
        $this->assertDoesNotMatchRegularExpression('/\b(USD|UGX|\$)\s?\d/', json_encode($fresh->settings), 'no price anywhere in the draft');

        $this->app['env'] = 'production';
        $this->artisan('travel:apply-profile', ['profile' => 'lazaros', '--tenant' => $t->id])->assertFailed();
        $this->artisan('travel:apply-profile', ['profile' => 'no-such', '--tenant' => $t->id])->assertFailed();
    }
}
