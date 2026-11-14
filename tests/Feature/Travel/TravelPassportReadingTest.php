<?php

namespace Tests\Feature\Travel;

use App\Jobs\NotifyOwner;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Models\TravelTraveller;
use App\Models\User;
use App\Services\Travel\Conversation\TravelTurn;
use App\Services\Travel\Handoff\StaffHandoff;
use App\Services\Travel\Passport\Mrz;
use App\Services\Travel\Passport\MrzReader;
use App\Services\Travel\Passport\PassportInbox;
use App\Services\Travel\Privacy\PassportGuard;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\TestCase;

/**
 * Travel enquiry (issue #145) — reading a passport or ID card the customer sends, through the real
 * webhook, the real message job and a real database.
 *
 * Only the OCR program is replaced (by a stub that "reads" the machine-readable lines of the ICAO 9303
 * specimen passport — the fictional Anna Maria Eriksson of Utopia); the parsing, check digits,
 * storage, encryption, party rules, replies and permissions are the real code. The real OCR program
 * is exercised separately on test images by qa/travel_mrz_ocr.php.
 *
 * What must hold: the traveller lands on the trip; the document number is stored encrypted and shown
 * masked; the file is never stored, never queued and never sent to the AI model; staff alerts mask the
 * number; only an owner or manager can reveal it, and that is logged; grocery shops are unaffected.
 */
class TravelPassportReadingTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '256700777888';
    private const STAFF = '256701000001';

    /** What the stub OCR "reads" next (machine-readable lines). */
    private string $ocrText = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 10:00:00', 'UTC'));   // ages and "expired" are dated
        Bus::fake([NotifyOwner::class]);
        config(['whatsapp.default' => 'evolution', 'whatsapp.drivers.evolution.base_url' => 'http://evolution.test',
            'whatsapp.drivers.evolution.api_key' => 'k', 'app.url' => 'https://panel.test', 'openai.api_key' => 'test-key']);
        Http::fake(fn () => Http::response(['key' => ['id' => 'OUT']], 200));
        OpenAI::fake([]);
        $this->ocrText = self::td3('ERIKSSON', 'ANNA MARIA', 'L898902C3', '740812', 'F', '120415');   // the ICAO specimen (expired 2012)
        $this->app->instance(MrzReader::class, new MrzReader(fn (string $path) => $this->ocrText));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    /** Valid TD3 (passport) machine-readable lines for a fictional Utopian traveller. */
    private static function td3(string $surname, string $given, string $number, string $dob, string $sex, string $exp): string
    {
        $l1 = str_pad('P<UTO' . $surname . '<<' . str_replace(' ', '<', $given), 44, '<');
        $num = str_pad($number, 9, '<');
        $l2 = $num . Mrz::checkDigit($num) . 'UTO' . $dob . Mrz::checkDigit($dob) . $sex . $exp . Mrz::checkDigit($exp) . str_repeat('<', 14) . '0';
        $l2 .= (string) Mrz::checkDigit(substr($l2, 0, 10) . substr($l2, 13, 7) . substr($l2, 21, 22));

        return "REPUBLIC OF UTOPIA PASSPORT\n" . $l1 . "\n" . $l2 . "\n";
    }

    private function agency(string $vertical = 'travel', array $settings = []): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'whatsapp_number' => '256788000111',
            'settings' => array_merge(['vertical' => $vertical, 'bot_mode' => 'ai', 'timezone' => 'Africa/Kampala'], $settings)]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    /** A real JPEG (a synthetic image of the specimen passport page), base64. */
    private static function photo(): string
    {
        return base64_encode((string) file_get_contents(base_path('qa/fixtures/mrz/passport-clean.jpg')));
    }

    /** The customer sends a photo: the real webhook queues the job; the real job is then run. */
    private function sendPhoto(Tenant $t, string $id = 'MSG1', string $caption = ''): void
    {
        $body = ['event' => 'messages.upsert', 'instance' => $t->whatsapp_instance, 'data' => [
            'key' => ['remoteJid' => self::CUSTOMER . '@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Test Customer', 'message' => ['imageMessage' => ['mimetype' => 'image/jpeg', 'caption' => $caption], 'base64' => self::photo()],
        ]];
        Bus::fake([ProcessIncomingMessage::class, NotifyOwner::class]);
        $this->postJson('/api/webhook/whatsapp/evolution', $body)->assertOk();
        $jobs = Bus::dispatched(ProcessIncomingMessage::class)->all();
        $this->assertCount(1, $jobs);
        $job = $jobs[0];

        // What went on the queue: a reference to the message, never the photo.
        $this->assertArrayNotHasKey('image_b64', $job->incoming);
        $this->assertArrayNotHasKey('raw', $job->incoming);
        $this->assertStringNotContainsString(substr(self::photo(), 100, 40), serialize($job), 'the photo is not on the queue');
        $this->assertTrue($job->incoming['travel_media']['stashed']);

        app()->call([$job, 'handle']);
        app(TenantContext::class)->set($t->id);
    }

    private function sends(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/message/sendText/'))
            ->map(fn ($pair) => ['number' => (string) $pair[0]['number'], 'text' => (string) $pair[0]['text']])->values()->all();
    }

    private function user(Tenant $t, string $role): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => ucfirst($role), 'role' => $role, 'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    // ---------------------------------------------------------------------------------------------

    public function test_a_passport_photo_is_read_saved_to_the_trip_and_the_file_is_kept_nowhere(): void
    {
        $t = $this->agency();

        $this->sendPhoto($t);

        // The traveller is on a trip, with the number encrypted at rest.
        $tv = TravelTraveller::withoutGlobalScopes()->where('tenant_id', $t->id)->sole();
        $this->assertSame(['ERIKSSON', 'ANNA MARIA', '1974-08-12', 'F', 'UTO', '02C3'],
            [$tv->surname, $tv->given_names, $tv->date_of_birth->format('Y-m-d'), $tv->sex, $tv->nationality, $tv->document_last4]);
        $this->assertSame('L898902C3', $tv->document_number);
        $this->assertStringNotContainsString('L898902C3', (string) DB::table('travel_travellers')->value('document_number'), 'encrypted in the database');
        $this->assertArrayNotHasKey('document_number', $tv->toArray(), 'hidden from arrays and JSON');
        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $t->id)->sole();
        $this->assertSame(1, (int) $e->adults, 'the party came from the passport because the customer had not stated one');
        $this->assertSame([['name' => 'ANNA MARIA ERIKSSON', 'age' => 52]], $e->state()['travellers']);

        // The customer is told what was read, so a misread name can be corrected.
        $sends = $this->sends();
        $this->assertCount(1, $sends);
        $this->assertSame(self::CUSTOMER, $sends[0]['number']);
        $this->assertStringContainsString('Passport read: *ANNA MARIA ERIKSSON*, age 52', $sends[0]['text']);
        $this->assertStringContainsString('has not been kept', $sends[0]['text']);
        $this->assertStringContainsString('which has passed', $sends[0]['text'], 'the specimen expired in 2012');
        $this->assertStringContainsString('To prepare your quotation I still need', $sends[0]['text']);
        $this->assertStringContainsString('the passport number is deleted 90 days after the booking is closed', $sends[0]['text'], 'the customer is told how long it is kept');
        $this->assertStringNotContainsString('L898902C3', $sends[0]['text']);

        // The file and the number are nowhere else: not in the chat, the trace, the timeline, the AI.
        $in = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->sole();
        $this->assertSame(PassportGuard::READ_PLACEHOLDER, $in->body);
        $this->assertEmpty($in->meta, 'no media reference is stored with the chat message');
        $this->assertSame(0, Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('body', 'like', '%L898902C3%')->count());
        $this->assertSame(0, DB::table('bot_events')->where('detail', 'like', '%L898902C3%')->count());
        $this->assertStringNotContainsString('L898902C3', json_encode(TravelEnquiryEvent::withoutGlobalScopes()->get()->toArray()));
        $this->assertStringNotContainsString('ERIKSSON', json_encode(TravelEnquiryEvent::withoutGlobalScopes()->get()->toArray()), 'the timeline says a document was read, not whose');
        OpenAI::assertNothingSent();
        Bus::assertNotDispatched(NotifyOwner::class);
        $this->assertNull(PassportInbox::take((int) $t->id, 'MSG1'), 'the encrypted copy was taken and deleted');
        $this->assertSame([], glob(rtrim(sys_get_temp_dir(), '/') . '/cbss-mrz/*') ?: [], 'no temporary file is left');
    }

    public function test_the_same_passport_sent_twice_is_one_traveller(): void
    {
        $t = $this->agency();

        $this->sendPhoto($t, 'MSG1');
        $this->sendPhoto($t, 'MSG2');

        $this->assertSame(1, TravelTraveller::withoutGlobalScopes()->where('tenant_id', $t->id)->count());
        $this->assertStringStartsWith('I already have this passport', $this->sends()[1]['text']);
    }

    public function test_a_childs_passport_gives_the_age_of_the_child_the_customer_mentioned_and_leaves_their_party_alone(): void
    {
        $t = $this->agency();
        $c = Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER, 'instance' => $t->whatsapp_instance, 'state' => [], 'cart' => []]);
        app(TravelTurn::class)->afterReply($t, $c, self::CUSTOMER, 'whatsapp', 'a safari for 2 adults and 1 child',
            "Lovely.\n<<TRIP {\"services\":[\"safari\"],\"adults\":2,\"children\":1}>>");

        $this->ocrText = self::td3('ERIKSSON', 'LINA', 'X12345678', '180305', 'F', '300101');
        $this->sendPhoto($t);

        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $t->id)->sole();
        $this->assertSame([2, 1], [(int) $e->adults, (int) $e->children], 'the customer\'s own numbers stand');
        $this->assertSame([8], $e->state()['brief']['child_ages']);
        $this->assertStringContainsString('Passport read: *LINA ERIKSSON*, age 8', $this->sends()[0]['text']);
    }

    public function test_a_passport_sent_while_staff_handle_the_chat_is_saved_but_the_bot_stays_quiet(): void
    {
        $t = $this->agency();
        Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER, 'instance' => $t->whatsapp_instance, 'state' => [], 'cart' => [], 'agent_active' => true]);

        $this->sendPhoto($t);

        $this->assertSame(1, TravelTraveller::withoutGlobalScopes()->where('tenant_id', $t->id)->count());
        $this->assertSame([], $this->sends(), 'a person is handling the chat: the bot says nothing');
    }

    public function test_a_photo_that_is_not_a_passport_is_not_stored_and_the_customer_is_asked_to_resend_or_type(): void
    {
        $t = $this->agency();
        $this->ocrText = "HOTEL BOOKING CONFIRMATION\nGuest: Sarah N.";

        $this->sendPhoto($t);

        $this->assertSame(0, TravelTraveller::withoutGlobalScopes()->count());
        $this->assertSame([PassportGuard::NOTICE_UNREADABLE], array_column($this->sends(), 'text'));
        $this->assertSame(PassportGuard::PHOTO_PLACEHOLDER, Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->value('body'));
        Bus::assertDispatched(NotifyOwner::class, fn ($job) => str_contains($job->text, 'could not be read'));
    }

    public function test_staff_see_the_number_masked_and_only_an_owner_or_manager_can_reveal_it_which_is_logged(): void
    {
        $t = $this->agency();
        $this->sendPhoto($t);
        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $t->id)->sole();
        $tv = TravelTraveller::withoutGlobalScopes()->sole();
        $base = '/api/v1/workspace/trip/' . $e->id;

        $this->actingAs($this->user($t, 'sales'));             // sales can open Customers, so sees the trip
        $j = $this->getJson($base)->assertOk()->json();
        $text = json_encode($j['answers'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ANNA MARIA ERIKSSON', $text);
        $this->assertStringContainsString('…02C3', $text);
        $this->assertStringContainsString('EXPIRED', $text);
        $this->assertStringNotContainsString('L898902C3', json_encode($j));
        $this->assertFalse(collect($j['actions'] ?? [])->contains(fn ($a) => $a['key'] === 'reveal_document'), 'not offered to a sales user');
        $this->postJson($base . '/reveal_document', ['traveller' => $tv->id . ' — x'])->assertJson(['ok' => false, 'error' => 'forbidden']);

        $owner = $this->user($t, 'owner');
        $this->actingAs($owner);
        $this->postJson($base . '/reveal_document', ['traveller' => $tv->id . ' — x'])
            ->assertJson(['ok' => true, 'message' => 'Passport number for ANNA MARIA ERIKSSON: L898902C3']);
        app(TenantContext::class)->set($t->id);
        $ev = TravelEnquiryEvent::where('enquiry_id', $e->id)->where('type', 'document_revealed')->sole();
        $this->assertSame((int) $owner->id, (int) $ev->actor_user_id, 'the timeline records who looked');
        $this->assertStringNotContainsString('L898902C3', json_encode($ev->meta));
    }

    public function test_the_staff_alert_lists_the_traveller_with_the_number_masked(): void
    {
        $t = $this->agency('travel', [StaffHandoff::SETTING => self::STAFF]);
        $c = Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::CUSTOMER, 'instance' => $t->whatsapp_instance, 'state' => [], 'cart' => []]);
        $turn = app(TravelTurn::class);
        $turn->afterReply($t, $c, self::CUSTOMER, 'whatsapp', 'gorilla safari in Bwindi in July', "Lovely.\n<<TRIP {\"services\":[\"safari\"],\"destinations\":[\"Bwindi\"],\"date_text\":\"July\"}>>");

        $this->sendPhoto($t);                                   // 1 adult from the passport → the trip is complete: summary shown
        $this->assertStringContainsString(TravelTurn::ASK_TO_CONFIRM, $this->sends()[0]['text']);
        $this->assertStringContainsString('Travellers: ANNA MARIA ERIKSSON (52)', $this->sends()[0]['text']);

        $turn->afterReply($t, $c, self::CUSTOMER, 'whatsapp', 'Yes', "Thank you!\n<<TRIP {\"confirm\":true}>>");

        $staff = collect($this->sends())->firstWhere('number', self::STAFF);
        $this->assertNotNull($staff);
        $this->assertStringContainsString('ANNA MARIA ERIKSSON · 52 yrs · born 12 Aug 1974', $staff['text']);
        $this->assertStringContainsString('passport …02C3', $staff['text']);
        $this->assertStringContainsString('⚠ EXPIRED', $staff['text']);
        $this->assertStringNotContainsString('L898902C3', $staff['text']);
    }

    public function test_one_agency_cannot_see_or_reveal_another_agencys_traveller(): void
    {
        $a = $this->agency();
        $this->sendPhoto($a);
        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $a->id)->sole();
        $tv = TravelTraveller::withoutGlobalScopes()->sole();

        $b = $this->agency();
        $this->actingAs($this->user($b, 'owner'));
        $this->assertStringNotContainsString('ERIKSSON', json_encode($this->getJson('/api/v1/workspace/trip/' . $e->id)->json()));
        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/reveal_document', ['traveller' => $tv->id . ' — x'])->assertJson(['ok' => false, 'error' => 'not_found']);
    }

    // ---- retention -------------------------------------------------------------------------------

    private function closeTrip(Tenant $t): TravelEnquiry
    {
        $e = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $t->id)->sole();
        $this->assertTrue(app(\App\Services\Travel\Enquiry\EnquiryService::class)->changeStage($e, \App\Services\Travel\Enquiry\TravelPipeline::LOST, 'staff', null, 'Booked elsewhere'));

        return $e->fresh();
    }

    private function rawNumber(): ?string
    {
        return DB::table('travel_travellers')->value('document_number');
    }

    public function test_a_closed_trips_document_numbers_are_deleted_after_90_days_and_not_before(): void
    {
        $t = $this->agency();
        $this->sendPhoto($t);
        $e = $this->closeTrip($t);                                  // closed 14 Nov 2026 (Kampala)

        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-11 10:00:00', 'UTC'));   // day 89
        $this->artisan('travel:purge-documents')->assertExitCode(0);
        $this->assertNotNull($this->rawNumber(), 'kept until the 90th day');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-12 10:00:00', 'UTC'));   // day 90
        $this->artisan('travel:purge-documents --dry-run')->assertExitCode(0);
        $this->assertNotNull($this->rawNumber(), 'a dry run changes nothing');
        $this->artisan('travel:purge-documents')->assertExitCode(0);

        $tv = TravelTraveller::withoutGlobalScopes()->sole();
        $this->assertNull($this->rawNumber(), 'the number is gone from the database');
        $this->assertTrue($tv->numberDeleted());
        $this->assertSame('deleted:' . $tv->id, DB::table('travel_travellers')->value('fingerprint'), 'and the fingerprint derived from it');
        $this->assertSame(['ERIKSSON', 'ANNA MARIA', '02C3'], [$tv->surname, $tv->given_names, $tv->document_last4], 'the booking history stays');

        app(TenantContext::class)->set($t->id);
        $ev = TravelEnquiryEvent::where('enquiry_id', $e->id)->where('type', 'document_numbers_deleted')->sole();
        $this->assertEquals(['count' => 1, 'rule' => 'closed', 'days' => 90], $ev->meta);   // jsonb reorders keys

        // Staff see that it was deleted, and cannot reveal it.
        $this->actingAs($this->user($t, 'owner'));
        $j = $this->getJson('/api/v1/workspace/trip/' . $e->id)->assertOk()->json();
        $this->assertStringContainsString('(number deleted)', json_encode($j['answers'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertFalse(collect($j['actions'] ?? [])->contains(fn ($a) => $a['key'] === 'reveal_document'));
        $this->postJson('/api/v1/workspace/trip/' . $e->id . '/reveal_document', ['traveller' => $tv->id . ' — x'])->assertJson(['ok' => false, 'error' => 'deleted']);

        // Running again deletes nothing more and writes no second timeline entry.
        $this->artisan('travel:purge-documents')->assertExitCode(0);
        app(TenantContext::class)->set($t->id);
        $this->assertSame(1, TravelEnquiryEvent::where('enquiry_id', $e->id)->where('type', 'document_numbers_deleted')->count());
    }

    public function test_an_agency_can_set_a_shorter_period_and_the_customer_is_told_it(): void
    {
        $t = $this->agency('travel', ['travel_document_retention_days' => 30]);
        $this->sendPhoto($t);
        $this->assertStringContainsString('deleted 30 days after the booking is closed', $this->sends()[0]['text']);
        $this->closeTrip($t);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-14 10:00:00', 'UTC'));   // day 30
        $this->artisan('travel:purge-documents')->assertExitCode(0);

        $this->assertNull($this->rawNumber());
    }

    public function test_an_open_trip_keeps_its_numbers_until_180_days_without_activity(): void
    {
        $t = $this->agency();
        $this->sendPhoto($t);                                       // the trip stays open

        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-05-12 10:00:00', 'UTC'));   // day 179
        $this->artisan('travel:purge-documents')->assertExitCode(0);
        $this->assertNotNull($this->rawNumber());

        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-05-13 10:00:00', 'UTC'));   // day 180
        $this->artisan('travel:purge-documents')->assertExitCode(0);
        $this->assertNull($this->rawNumber());
        app(TenantContext::class)->set($t->id);
        $this->assertSame('inactive', TravelEnquiryEvent::where('type', 'document_numbers_deleted')->sole()->meta['rule']);
    }

    public function test_a_grocery_shop_photo_is_handled_exactly_as_before(): void
    {
        $shop = $this->agency('grocery', ['bot_mode' => 'off']);

        $this->postJson('/api/webhook/whatsapp/evolution', ['event' => 'messages.upsert', 'instance' => $shop->whatsapp_instance, 'data' => [
            'key' => ['remoteJid' => self::CUSTOMER . '@s.whatsapp.net', 'fromMe' => false, 'id' => 'G1'],
            'message' => ['imageMessage' => ['mimetype' => 'image/jpeg', 'caption' => 'do you have this'], 'base64' => self::photo()],
        ]])->assertOk();

        // QUEUE_CONNECTION=sync in tests unless faked: Bus was faked for NotifyOwner only, so the job ran.
        $this->assertSame(0, TravelTraveller::withoutGlobalScopes()->count(), 'a shop never reads documents');
        $in = Message::withoutGlobalScopes()->where('tenant_id', $shop->id)->where('direction', 'in')->first();
        $this->assertSame('image', $in->meta['media']['kind'] ?? null, 'the shop photo keeps its media reference for the inbox');
    }
}
