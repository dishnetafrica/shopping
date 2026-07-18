<?php

namespace Tests\Feature\Capability;

use App\Capability\Registry\CapabilityRegistry;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration plan G1 — the WhatsApp inbox as v3 capabilities. Read paths only
 * (writes are queryWrite delegations to the already-tested /papi/chats/* inbox
 * endpoints, so there is nothing new to test on the write side).
 */
class ChatsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function login(): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst-test']);
        app(\App\Support\TenantContext::class)->set($t->id);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);
        return $t;
    }

    private function convo(Tenant $t, string $phone, int $unread, bool $agent, array $msgs): Conversation
    {
        $c = Conversation::create([
            'tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'inst-test',
            'state' => [], 'cart' => [], 'unread' => $unread, 'agent_active' => $agent,
            'last_message_at' => now(),
        ]);
        foreach ($msgs as [$dir, $sender, $body]) {
            DB::table('messages')->insert([
                'tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'inst-test',
                'direction' => $dir, 'sender' => $sender, 'body' => $body,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $c;
    }

    public function test_registry_has_chat_capabilities(): void
    {
        $r = app(CapabilityRegistry::class);
        $this->assertTrue($r->has('chats'));
        $this->assertTrue($r->has('chat'));
        $this->assertSame('menu:whatsapp', $r->manifest('chats')->permission);
        $this->assertSame('/api/v1/workspace/chat/{id}', $r->manifest('chat')->api);
        $r->assertDependencies();
    }

    public function test_chats_list_projects_rows_kpis_and_links(): void
    {
        $t = $this->login();
        $a = $this->convo($t, '256700000001', 2, false, [['in', 'customer', 'price for rice?']]);
        $this->convo($t, '256700000002', 0, true,  [['out', 'agent', 'on the way']]);

        $j = $this->getJson('/api/v1/workspace/chats')->assertOk()->json();

        $this->assertSame('process:chats', $j['object']['type']);
        $kpi = collect($j['financials'])->keyBy('key');
        $this->assertSame('1', $kpi['unread']['value']);
        $this->assertSame('1', $kpi['agent']['value']);

        $rows = collect($j['table']['rows']);
        $this->assertCount(2, $rows);
        $row = $rows->firstWhere('link', '/api/v1/workspace/chat/'.$a->id);
        $this->assertNotNull($row, 'row must deep-link to the chat object workspace');
        $this->assertSame('2', $row['unread']['value']);
        $this->assertStringContainsString('price for rice', $row['last']['value']);
    }

    public function test_contact_names_fall_back_to_their_latest_order(): void
    {
        $t = $this->login();
        // No CustomerProfile — but this phone ordered before, so we KNOW the name.
        \App\Models\Order::create([
            'tenant_id' => $t->id, 'order_no' => 'FS-5001', 'customer_name' => 'Mary Akech',
            'customer_phone' => '211920000009', 'status' => 'Delivered', 'total' => 40,
        ]);
        $this->convo($t, '211920000009', 1, false, [['in', 'customer', 'hello again']]);

        $j = $this->getJson('/api/v1/workspace/chats')->assertOk()->json();
        $row = collect($j['table']['rows'])->first();
        $this->assertSame('Mary Akech', $row['contact']['value'],
            'a phone that ever ordered must show a NAME, not digits');
    }

    public function test_chat_object_renders_thread_and_delegated_actions(): void
    {
        $t = $this->login();
        $c = $this->convo($t, '256700000001', 1, false, [
            ['in', 'customer', 'hello'], ['out', 'bot', 'Hi! How can I help?'], ['in', 'customer', 'need sugar 50kg'],
        ]);

        $j = $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk()->json();

        $this->assertSame('chat', $j['object']['type']);
        $this->assertTrue((bool) ($j['meta']['thread'] ?? false), 'thread flag drives the bubble renderer');
        $this->assertCount(3, $j['timeline']);
        $this->assertSame('inbound',  $j['timeline'][0]['type']);
        $this->assertSame('outbound', $j['timeline'][1]['type']);
        $this->assertStringContainsString('need sugar', $j['timeline'][2]['detail']);

        $acts = collect($j['actions'])->keyBy('key');
        $this->assertSame('/papi/chats/send', $acts['reply']['endpoint'], 'reply must delegate to the proven inbox endpoint');
        $this->assertSame(['phone' => '256700000001'], $acts['reply']['body']);
        $this->assertArrayHasKey('takeover', $acts, 'bot-mode chat offers Take over');
    }

    public function test_chat_permissions_and_missing_id(): void
    {
        $t = $this->login();
        // product_staff has no menu:whatsapp
        $staff = User::factory()->create(['tenant_id' => $t->id, 'role' => 'product_staff']);
        $this->actingAs($staff);
        $this->getJson('/api/v1/workspace/chats')->assertStatus(403);

        $owner = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($owner);
        $j = $this->getJson('/api/v1/workspace/chat/999999')->assertOk()->json();
        $this->assertSame('not_found', $j['meta']['error']);
    }
}
