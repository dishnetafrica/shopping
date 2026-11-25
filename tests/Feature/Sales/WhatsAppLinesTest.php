<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * V3 Settings → WhatsApp & bot → WhatsApp numbers (DishNet, 25 Nov 2026: "where can I add the second salesperson's
 * WhatsApp in V3?"). Evolution is mocked; names and numbers are test values.
 */
class WhatsAppLinesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private User $owner;

    private User $aida;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(): void
    {
        $this->t = Tenant::factory()->create(['whatsapp_driver' => 'evolution', 'settings' => ['vertical' => 'isp', 'currency' => 'USD', 'bot_muted' => ['211900000777']]]);
        $this->t->whatsapp_instance = 'shopbot_t' . $this->t->id;
        $this->t->save();
        app(TenantContext::class)->set($this->t->id);
        $this->owner = User::create(['tenant_id' => $this->t->id, 'name' => 'Bhavin', 'role' => 'owner', 'email' => 'o' . $this->t->id . '@t.test', 'password' => 'secret']);
        $this->aida = User::create(['tenant_id' => $this->t->id, 'name' => 'Aida', 'role' => 'sales', 'phone' => '211921443004', 'email' => 'a' . $this->t->id . '@t.test', 'password' => 'secret']);

        $main = (string) $this->t->whatsapp_instance;
        $evo = Mockery::mock(EvolutionAdmin::class);
        $evo->shouldReceive('configured')->andReturn(true);
        $evo->shouldReceive('createIfMissing')->andReturn([]);
        $evo->shouldReceive('getWebhook')->andReturn(['url' => 'https://example.test/api/webhook/whatsapp/evolution']);
        $evo->shouldReceive('setWebhook')->with(Mockery::any(), 'https://example.test/api/webhook/whatsapp/evolution')->andReturnNull();
        $evo->shouldReceive('pairingCode')->andReturn('ABCD-1234');
        $evo->shouldReceive('instanceInfo')->andReturnUsing(fn ($i) => $i === $main ? ['number' => '211923400000', 'state' => 'open'] : ['state' => 'connecting']);
        $this->app->instance(EvolutionAdmin::class, $evo);
    }

    private function cmd(string $action, array $body)
    {
        return $this->postJson('/api/v1/workspace/settings/_/' . $action, $body);
    }

    public function test_an_owner_adds_a_salespersons_number_and_gets_a_link_code_on_the_screen(): void
    {
        $this->business();
        $this->actingAs($this->owner);

        $this->cmd('wa_add_number', ['number' => '+211 921 443 004', 'label' => '', 'belongs_to' => $this->aida->id . ' — Aida'])
            ->assertJson(['ok' => true])->assertJsonFragment(['message' => 'Added +211921443004. On that phone: WhatsApp → Linked devices → Link a device → Link with phone number instead, and type ABCD-1234.']);

        $c = WhatsappChannel::where('instance', 'shopbot_t' . $this->t->id . '_2')->firstOrFail();
        $this->assertSame(['211921443004', $this->aida->id, 'Aida', false, true], [$c->number, (int) $c->owner_user_id, $c->label, (bool) $c->is_primary, (bool) $c->active]);
        $muted = (array) $this->t->fresh()->setting('bot_muted', []);
        $this->assertContains('211921443004', $muted, 'a line of the business never gets bot replies (no two lines answering each other)');
        $this->assertContains('211900000777', $muted, 'what was muted stays muted');

        $screen = json_encode($this->getJson('/api/v1/workspace/settings')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        foreach (['WhatsApp numbers', '+211923400000 · main', '+211921443004', 'Aida', 'Waiting for the phone', 'Connected', 'ABCD-1234 — on that phone', 'Add a WhatsApp number', 'New link code', 'Who a number belongs to'] as $want) {
            $this->assertStringContainsString($want, $screen, $want);
        }

        $this->cmd('wa_add_number', ['number' => '211921443004', 'belongs_to' => 'The business'])->assertJson(['ok' => false]);
        $this->assertSame(1, WhatsappChannel::where('tenant_id', $this->t->id)->where('number', '211921443004')->count(), 'the same number is not added twice');
    }

    public function test_who_a_number_belongs_to_and_a_new_link_code(): void
    {
        $this->business();
        $this->actingAs($this->owner);
        $this->cmd('wa_add_number', ['number' => '211921443004', 'belongs_to' => 'The business'])->assertJson(['ok' => true]);
        $line = 'shopbot_t' . $this->t->id . '_2';
        $this->assertNull(WhatsappChannel::where('instance', $line)->value('owner_user_id'));

        $this->cmd('wa_line_owner', ['line' => $line . ' — +211921443004 (The business)', 'belongs_to' => $this->aida->id . ' — Aida'])->assertJson(['ok' => true]);
        $this->assertSame($this->aida->id, (int) WhatsappChannel::where('instance', $line)->value('owner_user_id'));

        $clerk = User::create(['tenant_id' => $this->t->id, 'name' => 'Clerk', 'role' => 'staff', 'phone' => '211922000009', 'email' => 'c' . $this->t->id . '@t.test', 'password' => 'secret']);
        $this->cmd('wa_line_owner', ['line' => $line, 'belongs_to' => $clerk->id . ' — Clerk'])->assertJson(['ok' => false]);
        $this->assertSame($this->aida->id, (int) WhatsappChannel::where('instance', $line)->value('owner_user_id'), 'only a salesperson can own a number');

        $this->cmd('wa_line_owner', ['line' => $line, 'belongs_to' => 'The business'])->assertJson(['ok' => true]);
        $this->assertNull(WhatsappChannel::where('instance', $line)->value('owner_user_id'));

        $this->cmd('wa_new_code', ['line' => $line . ' — +211921443004'])->assertJson(['ok' => true])->assertJsonFragment(['message' => 'Link code for +211921443004: ABCD-1234 (shown on this screen for 3 minutes).']);
        $this->cmd('wa_add_number', ['number' => '12345'])->assertJson(['ok' => false]);
    }

    public function test_a_salesperson_cannot_change_the_numbers(): void
    {
        $this->business();
        $this->actingAs($this->aida);

        $this->cmd('wa_add_number', ['number' => '211921443099', 'belongs_to' => 'The business'])
            ->assertJson(['ok' => false, 'message' => 'Only an owner or manager can change the WhatsApp numbers.']);
        $this->assertSame(0, WhatsappChannel::where('number', '211921443099')->count());
    }
}
