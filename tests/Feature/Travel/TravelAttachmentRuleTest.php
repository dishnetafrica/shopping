<?php

namespace Tests\Feature\Travel;

use App\Jobs\NotifyOwner;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Travel\Privacy\PassportGuard;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Travel Vertical — the passport rule for attachments, through the real webhook and the real
 * message job.
 *
 * Two questions, each asked of a PDF, a photo and a voice note, with a grocery shop as the control:
 *   1. What is put on the queue?   (the real webhook endpoint; the job is captured, not run)
 *   2. What is fetched, stored and said?   (the real job; the WhatsApp provider is a recorder)
 *
 * On a travel agency the attachment's bytes, its file name and the provider's raw body must be gone
 * before queueing; a photo or PDF travels only as a reference (`travel_media`), its inline bytes set
 * aside encrypted for the job that reads it as a passport or ID card (TravelPassportReadingTest). The
 * chat log holds a placeholder, never a media reference. A file that is not a passport is not stored
 * and the customer is asked to resend or type. A grocery shop must be exactly as before: its photo is
 * queued with its bytes and stored with its key, and its PDF is still dropped.
 */
class TravelAttachmentRuleTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '256700555666';

    /** "PASSPORTBYTES" in base64 — stands in for the content of an attachment. */
    private const BYTES = 'UEFTU1BPUlRCWVRFUw==';

    /** What the transcription service returns for the voice note in the voice test. */
    private const SPOKEN = 'My passport number is A1234567. I need a ticket to Dubai for two adults.';

    /** The queued jobs exactly as the queue would store them (also what a failed job keeps). */
    private string $stored = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.default' => 'evolution', 'whatsapp.drivers.evolution.base_url' => 'http://evolution.test', 'whatsapp.drivers.evolution.api_key' => 'k']);
        Http::fake([
            'api.openai.com/v1/audio/*' => Http::response(['text' => self::SPOKEN], 200),   // voice transcription
            '*' => Http::response(['key' => ['id' => 'OUT1']], 200),                         // the WhatsApp provider
        ]);
        // The OCR program is replaced by one that finds nothing: these attachments are not passports.
        $this->app->instance(\App\Services\Travel\Passport\MrzReader::class, new \App\Services\Travel\Passport\MrzReader(fn (string $path) => ''));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private int $businessId = 0;

    private function business(string $vertical, string $botMode = 'ai'): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . $vertical, 'whatsapp_driver' => 'evolution',
            'settings' => ['vertical' => $vertical, 'bot_mode' => $botMode, 'timezone' => 'Africa/Kampala']]);
        $this->businessId = (int) $t->id;

        return $t;
    }

    /** A provider webhook body, as Evolution sends it. */
    private function body(Tenant $t, array $message, string $id = 'MSG1'): array
    {
        return ['event' => 'messages.upsert', 'instance' => $t->whatsapp_instance, 'data' => [
            'key' => ['remoteJid' => self::PHONE . '@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Test Customer', 'message' => $message,
        ]];
    }

    private static function pdf(string $caption = ''): array
    {
        return ['documentMessage' => ['mimetype' => 'application/pdf', 'fileName' => 'passport-scan.pdf', 'caption' => $caption, 'jpegThumbnail' => self::BYTES], 'base64' => self::BYTES];
    }

    private static function photo(string $caption = ''): array
    {
        return ['imageMessage' => ['mimetype' => 'image/jpeg', 'caption' => $caption, 'jpegThumbnail' => self::BYTES], 'base64' => self::BYTES];
    }

    private static function voice(): array
    {
        return ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus'], 'base64' => self::BYTES];
    }

    /** Post to the real webhook and return what it put on the queue. */
    private function queued(Tenant $t, array $message): array
    {
        Bus::fake([ProcessIncomingMessage::class, NotifyOwner::class]);
        $this->postJson('/api/webhook/whatsapp/evolution', $this->body($t, $message))->assertOk();

        $jobs = Bus::dispatched(ProcessIncomingMessage::class);
        $this->stored = $jobs->map(fn ($job) => serialize($job))->implode("\n");

        return $jobs->map(fn ($job) => $job->incoming)->all();
    }

    private static function leaksText(string $text): bool
    {
        return str_contains($text, 'UEFTU1BPUlRCWVRFUw') || str_contains($text, 'A1234567') || str_contains($text, 'passport-scan');
    }

    /** Run the real message job on a message exactly as the provider's parser produced it (NOT pre-scrubbed). */
    private function process(Tenant $t, array $message): void
    {
        Bus::fake([NotifyOwner::class]);
        $incoming = app(WhatsAppManager::class)->driver('evolution')->parseIncoming($this->body($t, $message));
        $this->assertIsArray($incoming);
        $incoming['trace'] = 'T1';
        app()->call([new ProcessIncomingMessage($t->id, 'evolution', $incoming), 'handle']);
    }

    private static function leaks(array $incoming): bool
    {
        $json = (string) json_encode($incoming);

        return str_contains($json, 'UEFTU1BPUlRCWVRFUw') || str_contains($json, 'A1234567') || str_contains($json, 'passport-scan');
    }

    private static function askedProviderForTheFile(): bool
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'getBase64FromMediaMessage'))->isNotEmpty();
    }

    // ---- 1. what is put on the queue -------------------------------------------------------------

    public function test_a_pdf_sent_to_a_travel_agency_is_queued_with_nothing_that_can_reach_it(): void
    {
        $jobs = $this->queued($this->business('travel'), self::pdf('my passport A1234567'));

        $this->assertCount(1, $jobs, 'the message goes through so the customer can be told and the team alerted');
        $in = $jobs[0];
        $this->assertTrue($in['has_document']);
        foreach (['media_key', 'raw', 'doc_name', 'image_b64'] as $gone) {
            $this->assertArrayNotHasKey($gone, $in);
        }
        $this->assertFalse(self::leaks($in), 'no bytes, no file name and no passport number anywhere in the queued message');
        $this->assertNotSame('', $this->stored);
        $this->assertFalse(self::leaksText($this->stored), 'nor in the job as the queue (and the failed-jobs table) would store it');
        $this->assertSame('my passport ' . PassportGuard::MASK, $in['media_caption']);
        // Only a reference to the message travels, for the job that reads the document on the server.
        $this->assertSame(['id', 'remoteJid', 'participant', 'fromMe'], array_keys($in['travel_media']['ref']));
        $this->assertTrue($in['travel_media']['stashed'], 'the inline bytes wait encrypted, off the queue');
        $this->assertSame(self::BYTES, \App\Services\Travel\Passport\PassportInbox::take($this->businessId, 'MSG1'));
        $this->assertNull(\App\Services\Travel\Passport\PassportInbox::take($this->businessId, 'MSG1'), 'and can be taken only once');
    }

    public function test_a_photo_sent_to_a_travel_agency_is_queued_without_its_bytes_or_its_key(): void
    {
        $jobs = $this->queued($this->business('travel'), self::photo());

        $this->assertCount(1, $jobs);
        $this->assertTrue($jobs[0]['has_image']);
        foreach (['media_key', 'raw', 'image_b64'] as $gone) {
            $this->assertArrayNotHasKey($gone, $jobs[0]);
        }
        $this->assertFalse(self::leaks($jobs[0]));
        $this->assertFalse(self::leaksText($this->stored));
    }

    public function test_a_typed_passport_number_is_masked_before_it_is_queued(): void
    {
        $jobs = $this->queued($this->business('travel'), ['conversation' => 'My passport number is A1234567, ticket to Dubai please']);

        $this->assertCount(1, $jobs);
        $this->assertFalse(self::leaks($jobs[0]), 'not in the text and not in a raw copy of the webhook body');
        $this->assertStringContainsString(PassportGuard::MASK, $jobs[0]['text']);
        $this->assertFalse(self::leaksText($this->stored));
    }

    public function test_a_voice_note_to_a_travel_agency_can_still_be_transcribed(): void
    {
        $jobs = $this->queued($this->business('travel'), self::voice());

        $this->assertCount(1, $jobs);
        $this->assertSame(self::BYTES, $jobs[0]['audio_b64']);
        $this->assertIsArray($jobs[0]['media_key']);
        $this->assertArrayNotHasKey('raw', $jobs[0]);
    }

    public function test_a_grocery_shop_is_queued_exactly_as_before(): void
    {
        $shop = $this->business('grocery');

        $this->assertCount(0, $this->queued($shop, self::pdf('price list')), 'a document with no typed text is still dropped for a shop');

        $jobs = $this->queued($shop, self::photo('do you have this'));
        $this->assertCount(1, $jobs);
        $this->assertSame(self::BYTES, $jobs[0]['image_b64'], 'a shop photo keeps its bytes for product matching');
        $this->assertIsArray($jobs[0]['media_key']);
        $this->assertArrayHasKey('raw', $jobs[0]);
    }

    // ---- 2. what is downloaded, stored and said --------------------------------------------------

    public function test_a_travel_pdf_that_is_not_a_passport_is_never_stored_and_the_customer_is_told_once(): void
    {
        $t = $this->business('travel');

        $this->process($t, self::pdf());

        $this->assertFalse(self::askedProviderForTheFile(), 'the bytes came with the message; the provider is not asked again');
        $this->assertSame(0, \App\Models\TravelTraveller::withoutGlobalScopes()->count());

        $in = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->get();
        $this->assertCount(1, $in);
        $this->assertSame(PassportGuard::DOCUMENT_PLACEHOLDER, $in[0]->body);
        $this->assertEmpty($in[0]->meta, 'no media reference is stored with the chat message');

        $out = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'out')->pluck('body')->all();
        $this->assertSame([PassportGuard::NOTICE_UNREADABLE], $out);
        Bus::assertDispatched(NotifyOwner::class, fn ($job) => str_contains($job->text, 'sent a document'));
    }

    public function test_a_travel_video_is_withheld_unread_as_before(): void
    {
        $t = $this->business('travel');

        $this->process($t, ['videoMessage' => ['mimetype' => 'video/mp4', 'caption' => ''], 'base64' => self::BYTES]);

        $this->assertFalse(self::askedProviderForTheFile());
        $this->assertSame(PassportGuard::VIDEO_PLACEHOLDER, Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->value('body'));
        $this->assertSame([PassportGuard::NOTICE], Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'out')->pluck('body')->all());
    }

    public function test_a_travel_photo_that_is_not_a_passport_is_never_stored(): void
    {
        $t = $this->business('travel');

        $this->process($t, self::photo());

        $this->assertFalse(self::askedProviderForTheFile());
        $in = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->first();
        $this->assertSame(PassportGuard::PHOTO_PLACEHOLDER, $in->body);
        $this->assertEmpty($in->meta);
        Bus::assertDispatched(NotifyOwner::class, fn ($job) => str_contains($job->text, 'sent a photo'));
    }

    public function test_a_voice_note_still_opens_a_trip_and_a_spoken_passport_number_goes_nowhere(): void
    {
        config(['openai.api_key' => 'test-key']);
        $answer = fn () => \OpenAI\Responses\Chat\CreateResponse::fake(['choices' => [['index' => 0, 'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => "Lovely, Dubai! When would you like to travel?\n<<TRIP {\"to\":\"Dubai\",\"adults\":2}>>"]]]]);
        \OpenAI\Laravel\Facades\OpenAI::fake([$answer(), $answer(), $answer(), $answer()]);
        $t = $this->business('travel');

        $audio = base64_encode(str_repeat('voice-note-bytes ', 40));   // long enough to be treated as audio
        $this->process($t, ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus'], 'base64' => $audio]);

        // The existing behaviour still works: the voice note was transcribed and opened a trip.
        $trip = \App\Models\TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $t->id)->get();
        $this->assertCount(1, $trip);
        $this->assertSame('Dubai, United Arab Emirates', $trip[0]->destination);
        $this->assertStringContainsString('2 adults', \App\Services\Travel\Enquiry\EnquirySlots::paxLine($trip[0]->state()));

        // The passport number that was spoken reached neither the model, the chat log nor the trace.
        \OpenAI\Laravel\Facades\OpenAI::assertSent(\OpenAI\Resources\Chat::class);
        \OpenAI\Laravel\Facades\OpenAI::assertNotSent(\OpenAI\Resources\Chat::class,
            fn (string $method, array $request) => str_contains((string) json_encode($request), 'A1234567'));
        $this->assertSame(0, Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('body', 'like', '%A1234567%')->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->where('detail', 'like', '%A1234567%')->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'voice_transcribed')->count());
    }

    public function test_a_grocery_shop_photo_is_stored_with_its_media_reference_as_before(): void
    {
        $shop = $this->business('grocery', 'off');   // bot off: only the storing of the message is under test

        $this->process($shop, self::photo('do you have this'));

        $in = Message::withoutGlobalScopes()->where('tenant_id', $shop->id)->where('direction', 'in')->first();
        $this->assertSame('do you have this', $in->body);
        $this->assertSame('image', $in->meta['media']['kind'] ?? null);
        $this->assertNotEmpty($in->meta['media']['key'] ?? null, 'staff can still open a shop customer\'s photo in the inbox');
    }
}
