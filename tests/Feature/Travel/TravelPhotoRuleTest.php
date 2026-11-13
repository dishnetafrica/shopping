<?php

namespace Tests\Feature\Travel;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use App\Services\Bot\CollectorGateway;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * Travel Vertical · M1 — the passport rule at the point that matters: what is handed to the AI model.
 *
 * The model is replaced by a recorder, so these tests read the exact request AiBrain::handle() builds.
 * On a travel agency a customer's photo and a typed passport number must never be in that request.
 * The same call on any other business must still carry the photo (the control), which also proves
 * the recorder can see an image when one is sent.
 */
class TravelPhotoRuleTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '256700333444';

    /** "ABCD" in base64 — stands in for the bytes of a customer photo. */
    private const PHOTO = 'QUJDRA==';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NotifyOwner::class]);
        config(['openai.api_key' => 'test-key']);
        $answer = fn () => CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Thank you, noted.'], 'finish_reason' => 'stop']]]);
        OpenAI::fake([$answer(), $answer(), $answer(), $answer()]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'timezone' => 'Africa/Kampala']]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function convo(Tenant $t): Conversation
    {
        return Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::PHONE, 'instance' => 'main', 'state' => [], 'cart' => []]);
    }

    private static function carriesPhoto(array $request): bool
    {
        $json = (string) json_encode($request);

        return str_contains($json, 'image_url') || str_contains($json, 'QUJDRA');
    }

    public function test_a_photo_and_a_passport_number_never_reach_the_model_on_a_travel_agency(): void
    {
        $t = $this->business('travel');

        app(AiBrain::class)->handle($t, $this->convo($t), self::PHONE, 'My passport number is A1234567, photo attached. Ticket to Dubai please.',
            new CollectorGateway(), ['imageB64' => self::PHOTO, 'mime' => 'image/jpeg']);

        OpenAI::assertSent(Chat::class);   // the model WAS asked — about the text only
        OpenAI::assertNotSent(Chat::class, fn (string $method, array $request) => self::carriesPhoto($request));
        OpenAI::assertNotSent(Chat::class, fn (string $method, array $request) => str_contains((string) json_encode($request), 'A1234567'));
        OpenAI::assertSent(Chat::class, fn (string $method, array $request) => str_contains((string) json_encode($request), 'passport number removed'));
    }

    public function test_a_photo_with_no_words_is_not_sent_to_the_model_at_all_on_a_travel_agency(): void
    {
        $t = $this->business('travel');

        app(AiBrain::class)->handle($t, $this->convo($t), self::PHONE, '', new CollectorGateway(), ['imageB64' => self::PHOTO, 'mime' => 'image/jpeg']);

        OpenAI::assertNothingSent();
    }

    public function test_every_other_business_still_has_its_photos_read(): void
    {
        $t = $this->business('grocery');

        try {
            app(AiBrain::class)->handle($t, $this->convo($t), self::PHONE, 'Do you have this one?', new CollectorGateway(), ['imageB64' => self::PHOTO, 'mime' => 'image/jpeg']);
        } catch (\Throwable $e) {
            // Only the request to the model is under test here; what a shop does with the answer is not.
        }

        OpenAI::assertSent(Chat::class, fn (string $method, array $request) => self::carriesPhoto($request));
    }
}
