<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Isp\CallbackNumber;
use App\Services\Isp\IspSalesTurn;
use App\Services\Sales\SalesOwnership;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * A salesperson alert must carry a number they can call (DishNet, 18 Nov 2026: "when it sends a message to
 * sales or support staff they don't get real numbers"). WhatsApp hides many customers' numbers; the chat is
 * keyed by a hidden id. Ids and numbers are test values.
 */
class CallbackNumberTest extends TestCase
{
    use RefreshDatabase;

    private const HIDDEN = '234239681400001';

    private const REAL = '211912345678';

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical = 'isp'): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution',
            'settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'business_hours' => '24/7']]);
        app(TenantContext::class)->set($t->id);
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'a' . $t->id . '@t.test', 'password' => 'secret']);

        return $t;
    }

    private function received(Tenant $t, array $key, string $text = 'Hello'): array
    {
        Bus::fake([ProcessIncomingMessage::class]);
        $this->postJson('/api/webhook/whatsapp/evolution', ['event' => 'messages.upsert', 'instance' => $t->whatsapp_instance, 'data' => [
            'key' => $key + ['fromMe' => false, 'id' => 'IN' . uniqid()], 'message' => ['conversation' => $text], 'pushName' => 'Jel',
        ]])->assertOk();

        return Bus::dispatched(ProcessIncomingMessage::class)->first()->incoming;
    }

    private function convo(Tenant $t): Conversation
    {
        return Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::HIDDEN, 'instance' => $t->whatsapp_instance, 'state' => [], 'cart' => []]);
    }

    public function test_a_hidden_chat_keeps_the_real_number_whatsapp_attaches(): void
    {
        $t = $this->business();
        $in = $this->received($t, ['remoteJid' => self::HIDDEN . '@lid', 'remoteJidAlt' => self::REAL . '@s.whatsapp.net']);
        $this->assertSame([self::HIDDEN, true, self::REAL], [$in['from'], $in['hidden'], $in['phone_alt']], 'the chat stays keyed by its id');

        CallbackNumber::fromIncoming($t, $this->convo($t), $in);

        $this->assertTrue(CallbackNumber::isHidden($t, self::HIDDEN));
        $this->assertSame(self::REAL, CustomerProfile::where('phone', self::HIDDEN)->value('alt_phone'));
        $this->assertSame('+' . self::REAL . ' (WhatsApp hides their number in the chat — call this one)', CallbackNumber::label($t, self::HIDDEN));
        $this->assertSame('', IspSalesTurn::contactBlock($t, self::HIDDEN), 'a number is known: the bot need not ask');

        // A visible number: nothing hidden, the alert shows it as before.
        $visible = $this->received($t, ['remoteJid' => '211955000111@s.whatsapp.net']);
        $this->assertSame([false, ''], [$visible['hidden'], $visible['phone_alt']]);
        $this->assertSame('+211955000111', CallbackNumber::label($t, '211955000111'));
    }

    public function test_with_no_number_the_bot_asks_and_the_typed_number_reaches_the_alert(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        CallbackNumber::fromIncoming($t, $this->convo($t), ['from' => self::HIDDEN, 'hidden' => true, 'phone_alt' => '']);

        $this->assertSame("WhatsApp hides this customer's number — reply to them in the ShopBot inbox (chat " . self::HIDDEN . ')', CallbackNumber::label($t, self::HIDDEN));
        $this->assertSame(IspSalesTurn::CALLBACK_RULE, IspSalesTurn::contactBlock($t, self::HIDDEN), 'the bot is told to ask once');

        $promise = 'Thank you! Your salesperson will call you to confirm coverage.';
        app(IspSalesTurn::class)->afterReply($t, self::HIDDEN, 'Munuki. My number is 0912 345 678', $promise);

        $this->assertSame('0912345678', CustomerProfile::where('phone', self::HIDDEN)->value('alt_phone'));
        $this->assertSame('', IspSalesTurn::contactBlock($t, self::HIDDEN), 'asked and answered: never again');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => str_contains($j->text, 'Bot promised a follow-up* — 0912345678 (WhatsApp hides their number in the chat — call this one)'));
    }

    public function test_a_number_staff_typed_is_never_replaced_and_other_businesses_are_untouched(): void
    {
        $t = $this->business();
        CustomerProfile::create(['tenant_id' => $t->id, 'phone' => self::HIDDEN, 'alt_phone' => '0955000000']);
        CallbackNumber::fromIncoming($t, $this->convo($t), ['from' => self::HIDDEN, 'hidden' => true, 'phone_alt' => self::REAL]);
        $this->assertSame('0955000000', CustomerProfile::where('phone', self::HIDDEN)->value('alt_phone'));

        // A visible-number customer typing a number changes nothing: their own number is callable.
        app(IspSalesTurn::class)->afterReply($t, '211955000111', 'call 0912345678', 'Sure.');
        $this->assertNull(CustomerProfile::where('phone', '211955000111')->value('alt_phone'));

        $shop = $this->business('grocery');
        $c = Conversation::create(['tenant_id' => $shop->id, 'customer_phone' => self::HIDDEN, 'instance' => $shop->whatsapp_instance, 'state' => [], 'cart' => []]);
        CallbackNumber::fromIncoming($shop, $c, ['from' => self::HIDDEN, 'hidden' => true, 'phone_alt' => self::REAL]);
        $this->assertFalse(SalesOwnership::enabled($shop));
        $this->assertEmpty($c->fresh()->state['wa_hidden'] ?? null, 'a shop is not an Internet provider: nothing stored');
        $this->assertNull(CustomerProfile::withoutGlobalScopes()->where('tenant_id', $shop->id)->where('phone', self::HIDDEN)->value('alt_phone'));
    }
}
