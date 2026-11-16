<?php

namespace Tests\Feature\Sales;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SalesCoachSuggestion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductWriteService;
use App\Services\Isp\SalesCoach;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Sales Coach (DishNet plan, Phase 4) through the real chat workspace API, with the one AI call
 * replaced by a fixed answer: the suggestion is guarded, explained and recorded, nothing is sent, a
 * salesperson cannot be coached on a colleague's customer, other business types have no coach.
 */
class SalesCoachTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '211911000001';

    /** @var object */
    private $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openai.api_key' => 'test-key-not-used']);
        $this->fake = new class(app(SalesBrain::class)) extends SalesCoach {
            public array $seen = [];
            public string $answer = '{"reply":"Fibre Standard 40 is $75 a month. Installation is $90. We cover Gudele Two.","why":"The customer asked the price and gave the area.","next_step":"quote","used":["Fibre Standard 40"],"check":""}';

            protected function ask(\App\Models\Tenant $tenant, array $messages): ?string
            {
                $this->seen = $messages;

                return $this->answer;
            }
        };
        app()->instance(SalesCoach::class, $this->fake);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function user(Tenant $t, string $role, string $name): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => $name, 'role' => $role, 'phone' => '2119220' . random_int(10000, 99999),
            'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    private function chat(string $vertical, ?User $owner = null): array
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);
        $c = Conversation::create(['tenant_id' => $t->id, 'customer_phone' => self::PHONE, 'instance' => 'main', 'state' => [], 'cart' => []]);
        foreach ([['in', 'customer', 'How much is fibre?'], ['out', 'bot', 'Which area are you in?'], ['in', 'customer', 'Gudele Two']] as [$d, $s, $b]) {
            Message::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => self::PHONE, 'instance' => 'main', 'direction' => $d, 'sender' => $s, 'body' => $b]);
        }

        return [$t, $c];
    }

    private function signIn(User $u): void
    {
        app(TenantContext::class)->set($u->tenant_id);
        $this->actingAs($u);
    }

    public function test_the_salesperson_gets_a_guarded_explained_suggestion_and_nothing_is_sent(): void
    {
        [$t, $c] = $this->chat('isp');
        $akol = $this->user($t, 'sales', 'Akol Deng');
        $c->update(['owner_user_id' => $akol->id]);
        app(ProductWriteService::class)->create($t, ['name' => 'Fibre Standard 40', 'price' => 75, 'billing' => 'monthly', 'service_line' => 'fiber']);
        app(ProductWriteService::class)->create($t, ['name' => 'Switched Off Item', 'price' => 90, 'active' => false]);
        $this->signIn($akol);

        $chat = $this->getJson('/api/v1/workspace/chat/' . $c->id)->assertOk()->json();
        $this->assertContains('coach', collect($chat['actions'])->pluck('key')->all());
        $sentBefore = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'out')->count();

        $r = $this->postJson('/api/v1/workspace/chat/' . $c->id . '/coach')->assertOk()->json();

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('Fibre Standard 40 is $75 a month.', strtok($r['suggestion'], "\n"), 'the catalogue price stays');
        $this->assertStringNotContainsString('$90', $r['suggestion'], 'a switched-off product\'s price is not approved for chat');
        $this->assertStringNotContainsString('We cover Gudele', $r['suggestion'], 'no published coverage → no coverage claim');
        $this->assertSame(2, $r['removed']);
        $this->assertSame('The customer asked the price and gave the area.', $r['why']);
        $this->assertStringContainsString('removed', $r['check']);
        $this->assertSame($sentBefore, Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'out')->count(), 'the coach never sends');

        $this->assertStringContainsString('Customer: Gudele Two', $this->fake->seen[1]['content'], 'the coach read the conversation');
        $this->assertStringNotContainsString('Switched Off Item', $this->fake->seen[0]['content'], 'only switched-on products are offered to it');

        $row = SalesCoachSuggestion::withoutGlobalScopes()->findOrFail($r['id']);
        $this->assertSame([(int) $akol->id, (int) $c->id, 'quote', 2], [(int) $row->user_id, (int) $row->conversation_id, $row->next_step, (int) $row->guard_removed]);
    }

    public function test_no_coaching_on_a_colleagues_customer_but_a_manager_may(): void
    {
        [$t, $c] = $this->chat('isp');
        $akol = $this->user($t, 'sales', 'Akol Deng');
        $bob = $this->user($t, 'sales', 'Bob Lado');
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $c->update(['owner_user_id' => $akol->id]);

        $this->signIn($bob);
        $this->postJson('/api/v1/workspace/chat/' . $c->id . '/coach')->assertOk()->assertJson(['ok' => false, 'error' => 'not_found']);
        $this->signIn($mgr);
        $this->postJson('/api/v1/workspace/chat/' . $c->id . '/coach')->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, SalesCoachSuggestion::withoutGlobalScopes()->where('tenant_id', $t->id)->count());
    }

    public function test_another_business_type_has_no_coach(): void
    {
        [$t, $c] = $this->chat('grocery');
        $this->signIn($this->user($t, 'owner', 'Shop Owner'));

        $chat = $this->getJson('/api/v1/workspace/chat/' . $c->id)->assertOk()->json();
        $this->assertNotContains('coach', collect($chat['actions'])->pluck('key')->all());
        $this->postJson('/api/v1/workspace/chat/' . $c->id . '/coach')->assertOk()->assertJson(['ok' => false, 'error' => 'not_available']);
        $this->assertSame([], $this->fake->seen, 'no AI call');
    }
}
