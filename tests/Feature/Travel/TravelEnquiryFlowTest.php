<?php

namespace Tests\Feature\Travel;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\FollowupProposal;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Services\Bot\AiBrain;
use App\Services\Bot\CollectorGateway;
use App\Services\Bot\PartRequestService;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Enquiry\EnquiryService;
use App\Services\Travel\Enquiry\TravelPipeline;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Travel Vertical · M1 — the conversation side, through the real services and a real database.
 *
 * The AI model itself is not called (CI has no model). What IS exercised is everything the code
 * does with the model's hidden <<TRIP {json}>> line: one enquiry per conversation, edits that keep
 * the other details, refused details explained, the lead link, the staff alert, the wording guards,
 * and that none of it touches a business that is not a travel agency.
 */
class TravelEnquiryFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '256700111222';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NotifyOwner::class]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function agency(array $settings = [], string $vertical = 'travel'): Tenant
    {
        $t = Tenant::factory()->create(['settings' => array_merge(['vertical' => $vertical, 'timezone' => 'Africa/Kampala'], $settings)]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function convo(Tenant $t, string $phone = self::PHONE): Conversation
    {
        return Conversation::create(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'main', 'state' => [], 'cart' => []]);
    }

    private function say(Tenant $t, Conversation $c, string $customer, string $modelReply, string $channel = 'whatsapp'): string
    {
        return app(TravelTurn::class)->afterReply($t, $c, (string) $c->customer_phone, $channel, $customer, $modelReply);
    }

    private function day(int $ahead): string
    {
        return now('Africa/Kampala')->addDays($ahead)->format('Y-m-d');
    }

    public function test_first_trip_detail_opens_one_enquiry_with_a_lead(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $reply = $this->say($t, $c, 'I want to fly to Dubai', "Lovely, Dubai it is! When would you like to travel?\n<<TRIP {\"to\":\"Dubai\"}>>");

        $this->assertStringNotContainsString('<<TRIP', $reply, 'the hidden line must never reach the customer');
        $this->assertStringContainsString('When would you like to travel?', $reply);

        $this->assertSame(1, TravelEnquiry::count());
        $e = TravelEnquiry::first();
        $this->assertSame(TravelPipeline::NEW_ENQUIRY, $e->stage);
        $this->assertSame('Dubai, United Arab Emirates', $e->destination);
        $this->assertSame((int) $c->id, (int) $e->conversation_id);
        $this->assertNotNull($e->lead_id, 'every enquiry is a lead');
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, TravelEnquiryEvent::where('type', 'created')->count());
        Bus::assertNotDispatched(NotifyOwner::class, fn ($job) => str_contains($job->text, 'Trip request'));
    }

    public function test_details_arrive_over_several_messages_then_a_change_keeps_everything_else(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $out = $this->day(30);
        $back = $this->day(45);
        $newOut = $this->day(32);

        $this->say($t, $c, 'ticket to Dubai', "Sure!\n<<TRIP {\"to\":\"Dubai\"}>>");
        $this->say($t, $c, 'from Entebbe, 2 adults', "Noted.\n<<TRIP {\"from\":\"Entebbe\",\"adults\":2}>>");
        $reply = $this->say($t, $c, "leaving $out back $back", "Great.\n<<TRIP {\"depart_date\":\"$out\",\"return_date\":\"$back\"}>>");

        $this->assertSame(1, TravelEnquiry::count(), 'later messages edit the same enquiry');
        $e = TravelEnquiry::first();
        $this->assertSame(TravelPipeline::DETAILS_COLLECTED, $e->stage);
        $this->assertNotNull($e->details_completed_at);
        $this->assertSame('return', $e->trip_type);
        $this->assertStringContainsString('Your trip request', $reply, 'the summary is written by code, not the model');
        $this->assertStringContainsString('Entebbe (EBB), Uganda → Dubai, United Arab Emirates', $reply);
        Bus::assertDispatched(NotifyOwner::class, fn ($job) => str_contains($job->text, 'Trip request ready to price'));
        $this->assertStringStartsWith('Flights:', (string) Lead::find($e->lead_id)->interest);

        // "make it two days later" — only the date changes.
        $reply = $this->say($t, $c, 'make it two days later', "Of course, updated.\n<<TRIP {\"depart_date\":\"$newOut\"}>>");
        $e->refresh();
        $this->assertSame(1, TravelEnquiry::count());
        $this->assertSame($newOut, $e->depart_date->format('Y-m-d'));
        $this->assertSame($back, $e->return_date->format('Y-m-d'));
        $this->assertSame(2, (int) $e->adults);
        $this->assertSame('Dubai, United Arab Emirates', $e->destination);
        $this->assertSame(TravelPipeline::DETAILS_COLLECTED, $e->stage);
        $this->assertStringContainsString('Updated trip request', $reply);
        Bus::assertDispatched(NotifyOwner::class, fn ($job) => str_contains($job->text, 'Trip request changed'));

        // A change that leaves a gap moves it back and asks, without losing the rest.
        $this->say($t, $c, 'actually one way... no, return but later', "Sure.\n<<TRIP {\"depart_date\":\"" . $this->day(60) . "\"}>>");
        $e->refresh();
        $this->assertNull($e->return_date, 'the old return date no longer fits, so it is asked again');
        $this->assertSame(TravelPipeline::NEW_ENQUIRY, $e->stage);
        $this->assertSame(2, (int) $e->adults);
    }

    public function test_a_refused_detail_is_explained_and_not_stored(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $past = now('Africa/Kampala')->subDays(3)->format('Y-m-d');

        $reply = $this->say($t, $c, 'to Dubai last week', "Noted, travelling then.\n<<TRIP {\"to\":\"Dubai\",\"depart_date\":\"$past\"}>>");

        $this->assertStringContainsString('has already passed', $reply);
        $e = TravelEnquiry::first();
        $this->assertNull($e->depart_date);
        $this->assertSame('Dubai, United Arab Emirates', $e->destination, 'the valid part of the message is still kept');
        $this->assertSame(1, TravelEnquiryEvent::where('type', 'validator_reject')->count());
    }

    public function test_an_ambiguous_city_becomes_a_question_and_is_cleared_by_the_answer(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $reply = $this->say($t, $c, 'flight to Birmingham', "Sure.\n<<TRIP {\"to\":\"Birmingham\"}>>");
        $this->assertStringContainsString('Which one do you mean', $reply);
        $this->assertNull(TravelEnquiry::first()->destination);

        $block = app(TravelTurn::class)->contextBlock($t, $c, self::PHONE, 'whatsapp');
        $this->assertStringContainsString('OPEN QUESTION', $block);

        $this->say($t, $c, 'the one in England', "Thanks.\n<<TRIP {\"to\":\"Birmingham UK\"}>>");
        $e = TravelEnquiry::first();
        $this->assertStringContainsString('BHX', (string) $e->destination);
        $this->assertSame([], $e->pending);
    }

    public function test_a_different_trip_closes_the_old_enquiry_instead_of_overwriting_it(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $this->say($t, $c, 'to Dubai', "Ok\n<<TRIP {\"to\":\"Dubai\",\"adults\":2}>>");
        $this->say($t, $c, 'forget that, a new trip to London for one', "A new trip then!\n<<TRIP {\"new_trip\":true,\"to\":\"London\",\"adults\":1}>>");

        $this->assertSame(2, TravelEnquiry::count());
        $old = TravelEnquiry::orderBy('id')->first();
        $new = TravelEnquiry::orderByDesc('id')->first();
        $this->assertSame(TravelPipeline::CANCELLED, $old->stage);
        $this->assertNotNull($old->closed_at);
        $this->assertSame('Dubai, United Arab Emirates', $old->destination, 'the old trip is kept as it was');
        $this->assertSame('London, United Kingdom', $new->destination);
        $this->assertSame(1, (int) $new->adults);
    }

    public function test_an_invented_fare_and_a_passport_request_never_reach_the_customer(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $this->say($t, $c, 'to Dubai', "Ok\n<<TRIP {\"to\":\"Dubai\"}>>");

        $reply = $this->say($t, $c, 'how much?', 'A return ticket is about USD 480 per person. Please send a photo of your passport. Which dates suit you?');

        $this->assertStringNotContainsString('480', $reply);
        $this->assertStringNotContainsString('send a photo of your passport', $reply);
        $this->assertStringContainsString('Which dates suit you?', $reply);
        $this->assertStringContainsString('Our travel team will confirm the fare', $reply);
        $this->assertStringContainsString('do not send passport', $reply);
        $guards = TravelEnquiryEvent::where('type', 'guard_hit')->get()->map(fn ($e) => $e->meta['guard'])->sort()->values()->all();
        $this->assertSame(['fare_stripped', 'passport_request_stripped'], $guards);
    }

    public function test_a_real_fixed_fee_from_the_catalogue_may_be_quoted(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        Product::create(['tenant_id' => $t->id, 'name' => 'Visa service', 'price' => 350000, 'active' => true]);

        $reply = $this->say($t, $c, 'how much is the visa service?', 'Our visa service is UGX 350,000. Shall I add it?');

        $this->assertSame('Our visa service is UGX 350,000. Shall I add it?', $reply);
    }

    public function test_a_cut_off_marker_never_leaks_and_is_counted(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $this->say($t, $c, 'to Dubai', "Ok\n<<TRIP {\"to\":\"Dubai\"}>>");

        $reply = $this->say($t, $c, 'for 2', "Two travellers, noted.\n<<TRIP {\"adults\":");

        $this->assertSame('Two travellers, noted.', $reply);
        $this->assertSame(1, TravelEnquiryEvent::where('type', 'guard_hit')->get()->filter(fn ($e) => $e->meta['guard'] === 'marker_malformed')->count());
    }

    public function test_a_returning_customer_is_recognised_only_when_the_agency_allows_it(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $this->say($t, $c, 'to Dubai', "Ok\n<<TRIP {\"from\":\"Entebbe\",\"to\":\"Dubai\",\"adults\":2}>>");
        app(EnquiryService::class)->changeStage(TravelEnquiry::first(), TravelPipeline::LOST, 'staff', null, 'went elsewhere');

        $block = app(TravelTurn::class)->contextBlock($t, $c, self::PHONE, 'whatsapp');
        $this->assertStringContainsString('RETURNING CUSTOMER', $block);
        $this->assertStringContainsString('Dubai', $block);
        $this->assertStringContainsString('nothing recorded yet', $block, 'the closed trip is history, not the current record');

        $t->settings = array_merge($t->settings, ['travel_use_history' => false]);
        $t->save();
        $this->assertStringNotContainsString('RETURNING CUSTOMER', app(TravelTurn::class)->contextBlock($t->fresh(), $c, self::PHONE, 'whatsapp'));
    }

    public function test_one_agency_never_sees_another_agencys_trips_or_history(): void
    {
        $a = $this->agency();
        $ca = $this->convo($a);
        $this->say($a, $ca, 'to Dubai', "Ok\n<<TRIP {\"from\":\"Entebbe\",\"to\":\"Dubai\",\"adults\":2}>>");
        $id = TravelEnquiry::first()->id;

        $b = $this->agency();                         // context is now agency B
        $cb = $this->convo($b);

        $this->assertSame(0, TravelEnquiry::count(), 'tenant scope hides agency A');
        $this->assertSame(0, TravelEnquiryEvent::count());
        $this->assertNull(app(EnquiryService::class)->current($b, self::PHONE, 'whatsapp'), 'same phone number, different agency');
        $this->assertNull(\App\Services\Travel\Panel\TripBoard::forTenant($b)->find($id));
        $this->assertStringNotContainsString('RETURNING CUSTOMER', app(TravelTurn::class)->contextBlock($b, $cb, self::PHONE, 'whatsapp'));
        $this->assertStringContainsString('tenant_id', TravelEnquiry::query()->toSql());
        $this->assertStringContainsString('tenant_id', TravelEnquiryEvent::query()->toSql());

        // The same customer writing to agency B gets a separate enquiry and a separate lead.
        $this->say($b, $cb, 'to Nairobi', "Ok\n<<TRIP {\"to\":\"Nairobi\"}>>");
        $this->assertSame(1, TravelEnquiry::count());
        $this->assertSame('Nairobi, Kenya', TravelEnquiry::first()->destination);
        app(TenantContext::class)->set($a->id);
        $this->assertSame(1, TravelEnquiry::count());
        $this->assertSame('Dubai, United Arab Emirates', TravelEnquiry::first()->destination);
    }

    public function test_the_timeline_cannot_be_edited_or_deleted(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $this->say($t, $c, 'to Dubai', "Ok\n<<TRIP {\"to\":\"Dubai\"}>>");
        $ev = TravelEnquiryEvent::first();

        $ev->type = 'tampered';
        $this->assertFalse($ev->save());
        $this->assertFalse((bool) $ev->delete());
        $this->assertSame('created', TravelEnquiryEvent::find($ev->id)->type);
    }

    public function test_takeover_reason_is_recorded_for_a_travel_agency_and_ignored_elsewhere(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $this->say($t, $c, 'to Dubai', "Ok\n<<TRIP {\"to\":\"Dubai\"}>>");

        $ev = app(EnquiryService::class)->recordHandover($t, self::PHONE, true, 'Complaint', 7);
        $this->assertSame('handover_started', $ev->type);
        $this->assertSame('Complaint', $ev->meta['reason']);
        $this->assertSame(7, (int) $ev->actor_user_id);
        $this->assertSame('handover_released', app(EnquiryService::class)->recordHandover($t, self::PHONE, false)->type);
        $this->assertNull(app(EnquiryService::class)->recordHandover($t, '256799999999', true, 'x'), 'no open trip for that customer');

        $shop = $this->agency([], 'grocery');
        $this->assertNull(app(EnquiryService::class)->recordHandover($shop, self::PHONE, true, 'Complaint'));
        $this->assertNull(app(EnquiryService::class)->recordHandover(null, self::PHONE, true));
    }

    public function test_a_shop_that_is_not_a_travel_agency_is_untouched(): void
    {
        $shop = $this->agency([], 'grocery');
        $travel = $this->agency();

        $this->assertFalse(EnquiryService::isTravel($shop));
        $this->assertTrue(EnquiryService::isTravel($travel));

        $prompt = new \ReflectionMethod(AiBrain::class, 'systemPrompt');
        $prompt->setAccessible(true);
        app(TenantContext::class)->set($shop->id);
        $shopPrompt = (string) $prompt->invoke(app(AiBrain::class), $shop);
        app(TenantContext::class)->set($travel->id);
        $travelPrompt = (string) $prompt->invoke(app(AiBrain::class), $travel);

        $this->assertStringNotContainsString('TRAVEL CONSULTANT', $shopPrompt);
        $this->assertStringNotContainsString('<<TRIP', $shopPrompt);
        $this->assertStringContainsString('TRAVEL CONSULTANT', $travelPrompt);
        $this->assertStringContainsString('<<TRIP', $travelPrompt);
        $this->assertStringContainsString('NEVER STATE A FARE', $travelPrompt);
        $this->assertStringContainsString('NEVER ask for a passport', $travelPrompt);
    }

    public function test_an_unpriced_trip_no_longer_pages_the_owner_as_a_spare_part_request(): void
    {
        $quote = ['lines' => [['name' => 'Return flight to Dubai', 'qty' => 2, 'matched' => false]]];

        $travel = $this->agency();
        app(PartRequestService::class)->captureUnmatched($travel, self::PHONE, $quote, 'ticket to dubai for 2');
        $this->assertSame(0, FollowupProposal::withoutGlobalScopes()->where('tenant_id', $travel->id)->where('trigger', 'part_request')->count());

        // Unchanged for every other business type: the same call still records the request.
        $shop = $this->agency([], 'auto_parts');
        app(PartRequestService::class)->captureUnmatched($shop, self::PHONE, ['lines' => [['name' => 'Air filter', 'qty' => 1, 'matched' => false]]], 'air filter');
        $this->assertSame(1, FollowupProposal::withoutGlobalScopes()->where('tenant_id', $shop->id)->where('trigger', 'part_request')->count());
    }

    public function test_the_prompt_tells_the_model_what_is_stored_and_what_is_still_needed(): void
    {
        $t = $this->agency(['travel_home_airport' => 'Entebbe']);
        $c = $this->convo($t);
        $turn = app(TravelTurn::class);

        $this->assertStringContainsString('nothing recorded yet', $turn->contextBlock($t, $c, self::PHONE, 'whatsapp'));
        $this->assertStringContainsString('offering Entebbe as the likely answer', TravelTurn::promptBlock($t));

        $this->say($t, $c, 'to Dubai for 2', "Ok\n<<TRIP {\"to\":\"Dubai\",\"adults\":2}>>");
        $block = $turn->contextBlock($t, $c, self::PHONE, 'whatsapp');
        $this->assertStringContainsString('TODAY: ' . now('Africa/Kampala')->format('Y-m-d'), $block);
        $this->assertStringContainsString('Dubai, United Arab Emirates', $block);
        $this->assertStringContainsString('2 adults', $block);
        $this->assertStringContainsString('STILL NEEDED: where they are flying from; the travel date; whether it is one way or return', $block);
        $this->assertStringNotContainsString('where they are flying to', $block, 'never ask again for what is stored');
    }

    public function test_every_turn_records_two_yes_no_facts_and_no_customer_data(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);

        $this->say($t, $c, 'I want to fly to Dubai', "Lovely! When?\n<<TRIP {\"to\":\"Dubai\"}>>");
        $this->say($t, $c, 'on 20 December, my passport is A1234567', 'Thank you, noted.');   // the model forgot the line
        $this->say($t, $c, 'thanks', 'You are welcome.');                                      // nothing to report

        $rows = \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'travel_turn')->orderBy('id')->get();
        $this->assertSame(
            ['marker=yes details=yes', 'marker=no details=yes', 'marker=no details=no'],
            $rows->pluck('detail')->all(),
            'only two yes/no facts per turn: none of the customer\'s words'
        );
        $this->assertSame([null, null, null], $rows->pluck('phone')->all(), 'no phone number is stored with the measurement');
    }

    public function test_a_travel_reply_that_mentions_a_quotation_is_not_replaced(): void
    {
        $t = $this->agency();
        $c = $this->convo($t);
        $reply = 'Thank you. Our travel team is checking the best fares and will send your quotation shortly.';

        $m = new \ReflectionMethod(AiBrain::class, 'captureQuote');
        $m->setAccessible(true);
        $res = $m->invoke(app(AiBrain::class), $t, $c, self::PHONE, 'ok thanks', $reply, new CollectorGateway());

        $this->assertSame($reply, $res['reply']);
        $this->assertFalse($res['sent']);
    }
}
