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

    public function test_rich_transcript_carries_media_and_send_photo_action(): void
    {
        $t = $this->login();
        $c = $this->convo($t, '256700000007', 0, false, [['in', 'customer', 'see photo']]);
        // attach media meta to the inbound row (what the webhook now records)
        \Illuminate\Support\Facades\DB::table('messages')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000007')
            ->update(['meta' => json_encode(['media' => ['kind' => 'image', 'key' => 'MK123', 'mime' => 'image/jpeg']])]);

        $j = $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk()->json();

        $m = collect($j['meta']['msgs'])->first();
        $this->assertSame('image', $m['media']['kind']);
        $this->assertStringContainsString('/papi/chats/media?key=MK123', $m['media']['src'],
            'media must resolve to the on-demand stream endpoint');
        $acts = collect($j['actions'])->keyBy('key');
        $this->assertSame('/papi/chats/send-media', $acts['send_image']['endpoint']);
        $this->assertSame('image', $acts['send_image']['schema']['image']);
    }

    public function test_opening_a_chat_auto_translates_arabic_without_any_tap(): void
    {
        $t = $this->login();
        $fake = new class extends \App\Services\Chat\ChatTranslator {
            public int $calls = 0;
            public function enabled(): bool { return true; }
            public function toEnglish(string $x): ?string { $this->calls++; return 'EN: '.mb_substr($x, 0, 10); }
        };
        $this->app->instance(\App\Services\Chat\ChatTranslator::class, $fake);

        $c = $this->convo($t, '256700000041', 0, false, [
            ['in', 'customer', 'عايز اسال سعر زيت'],
            ['in', 'customer', 'ok thanks'],           // English — must be skipped
        ]);

        $j = $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk()->json();

        $msgs = collect($j['meta']['msgs']);
        $ar = $msgs->first(fn ($m) => str_contains($m['body'], 'عايز'));
        $this->assertNotNull($ar['tr'], 'Arabic must arrive already translated — no tap');
        $en = $msgs->first(fn ($m) => $m['body'] === 'ok thanks');
        $this->assertNull($en['tr'], 'English messages must not be translated');
        $this->assertSame(1, $fake->calls);

        // persisted: a second load costs ZERO further AI calls
        $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk();
        $this->assertSame(1, $fake->calls, 'cache makes the second open free');
    }

    public function test_auto_translate_respects_the_tenant_switch(): void
    {
        $t = $this->login();
        $t->putSetting('chat_auto_translate', false);
        $fake = new class extends \App\Services\Chat\ChatTranslator {
            public int $calls = 0;
            public function enabled(): bool { return true; }
            public function toEnglish(string $x): ?string { $this->calls++; return 'EN'; }
        };
        $this->app->instance(\App\Services\Chat\ChatTranslator::class, $fake);

        $c = $this->convo($t, '256700000042', 0, false, [['in', 'customer', 'لو ممكن']]);
        $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk();

        $this->assertSame(0, $fake->calls, 'switch OFF = zero AI calls; the 🌐 button remains');
    }

    public function test_translate_caches_on_the_row_and_surfaces_in_transcript(): void
    {
        $t = $this->login();
        $fake = new class extends \App\Services\Chat\ChatTranslator {
            public int $calls = 0;
            public function enabled(): bool { return true; }
            public function toEnglish(string $x): ?string { $this->calls++; return 'If possible bring it to me now'; }
        };
        $this->app->instance(\App\Services\Chat\ChatTranslator::class, $fake);

        $c = $this->convo($t, '256700000031', 0, false, [['in', 'customer', 'لو ممكن جيب لي الان']]);
        $mid = \App\Models\Message::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000031')->value('id');

        $this->postJson('/papi/chats/translate', ['message_id' => $mid])
            ->assertOk()->assertJson(['ok' => true, 'translation' => 'If possible bring it to me now']);
        $this->postJson('/papi/chats/translate', ['message_id' => $mid])
            ->assertOk()->assertJson(['cached' => true]);
        $this->assertSame(1, $fake->calls, 'one AI call per message, EVER — the row caches it');

        $j = $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk()->json();
        $m = collect($j['meta']['msgs'])->first();
        $this->assertSame('If possible bring it to me now', $m['tr'], 'cached translation rides the transcript');
        $this->assertIsInt($m['id']);
    }

    public function test_translate_out_returns_arabic_draft(): void
    {
        $this->login();
        $fake = new class extends \App\Services\Chat\ChatTranslator {
            public function enabled(): bool { return true; }
            public function toArabic(string $x): ?string { return 'سأوصلها لك اليوم'; }
        };
        $this->app->instance(\App\Services\Chat\ChatTranslator::class, $fake);

        $this->postJson('/papi/chats/translate-out', ['text' => 'I will deliver it today'])
            ->assertOk()->assertJson(['ok' => true, 'translation' => 'سأوصلها لك اليوم']);
    }

    private function fakeGateway(): object
    {
        $gw = new class {
            public array $docs = []; public array $locs = [];
            public function sendDocument($i, $to, $media, $name, $cap = '') { $this->docs[] = [$to, $media, $name, $cap]; return ['ok' => true]; }
            public function sendLocation($i, $to, $lat, $lng, $name = '', $addr = '') { $this->locs[] = [$to, $lat, $lng, $name]; return ['ok' => true]; }
            public function sendImage($i, $to, $m, $c = '') { return ['ok' => true]; }
            public function sendText($i, $to, $m, $q = null) { return ['ok' => true]; }
        };
        $mgr = new class($gw) extends \App\Services\WhatsApp\WhatsAppManager {
            public function __construct(private object $g) {}
            public function forTenant(\App\Models\Tenant $t): object { return $this->g; }
        };
        $this->app->instance(\App\Services\WhatsApp\WhatsAppManager::class, $mgr);
        return $gw;
    }

    public function test_send_document_strips_data_url_and_keeps_filename(): void
    {
        $this->login();
        $gw = $this->fakeGateway();

        $this->postJson('/papi/chats/send-document', [
            'phone' => '256700000021', 'file' => 'data:application/pdf;base64,QUJDRA==',
            'filename' => 'pricelist.pdf', 'caption' => 'Latest prices',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['256700000021', 'QUJDRA==', 'pricelist.pdf', 'Latest prices'], $gw->docs[0],
            'Evolution must receive RAW base64, not the data-URL');
    }

    public function test_send_location_logs_a_maps_ready_pin_and_takes_over(): void
    {
        $t = $this->login();
        $gw = $this->fakeGateway();

        $this->postJson('/papi/chats/send-location', [
            'phone' => '256700000022', 'lat' => 0.3476, 'lng' => 32.5825, 'name' => 'Family Shoppers',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['256700000022', 0.3476, 32.5825, 'Family Shoppers'], $gw->locs[0]);
        $msg = \App\Models\Message::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000022')->first();
        $this->assertSame('location', $msg->meta['media']['kind']);
        $this->assertSame(0.3476, (float) $msg->meta['media']['lat']);
        $convo = \App\Models\Conversation::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000022')->first();
        $this->assertTrue((bool) $convo->agent_active);
    }

    public function test_location_video_and_documents_surface_in_the_transcript(): void
    {
        $t = $this->login();
        $c = $this->convo($t, '256700000011', 0, false, [
            ['in', 'customer', 'my location'], ['in', 'customer', 'see video'], ['in', 'customer', 'price list'],
        ]);
        $rows = \App\Models\Message::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000011')->orderBy('id')->get();
        $rows[0]->update(['meta' => ['media' => ['kind' => 'location', 'lat' => 4.85, 'lng' => 31.58, 'name' => 'Konyo Konyo']]]);
        $rows[1]->update(['meta' => ['media' => ['kind' => 'video', 'key' => ['id' => 'V1', 'remoteJid' => 'x'], 'mime' => 'video/mp4']]]);
        $rows[2]->update(['meta' => ['media' => ['kind' => 'file', 'key' => ['id' => 'D1', 'remoteJid' => 'x'], 'mime' => 'application/pdf', 'name' => 'pricelist.pdf']]]);

        $j = $this->getJson('/api/v1/workspace/chat/'.$c->id)->assertOk()->json();
        $msgs = collect($j['meta']['msgs']);

        $loc = $msgs->firstWhere('media.kind', 'location');
        $this->assertSame('Konyo Konyo', $loc['media']['name']);
        $this->assertStringContainsString('maps.google.com/?q=4.85,31.58', $loc['media']['maps']);

        $vid = $msgs->firstWhere('media.kind', 'video');
        $this->assertStringContainsString('/papi/chats/media?key=', $vid['media']['src']);
        $this->assertStringContainsString('video%2Fmp4', $vid['media']['src']);

        $doc = $msgs->firstWhere('media.kind', 'file');
        $this->assertSame('pricelist.pdf', $doc['media']['name'], 'documents keep their filename');
    }

    public function test_send_media_endpoint_sends_logs_and_takes_over(): void
    {
        $t = $this->login();
        $gw = new class {
            public array $sent = [];
            public function sendImage($i, $to, $media, $cap = '') { $this->sent[] = [$to, $media, $cap]; return ['ok' => true]; }
            public function sendText($i, $to, $m, $q = null) { return ['ok' => true]; }
        };
        $mgr = new class($gw) extends \App\Services\WhatsApp\WhatsAppManager {
            public function __construct(private object $g) {}
            public function forTenant(\App\Models\Tenant $t): object { return $this->g; }
        };
        $this->app->instance(\App\Services\WhatsApp\WhatsAppManager::class, $mgr);

        $this->postJson('/papi/chats/send-media', [
            'phone' => '256700000008', 'image' => 'https://cdn.example/x.jpg', 'caption' => 'Your order',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['256700000008', 'https://cdn.example/x.jpg', 'Your order'], $gw->sent[0]);
        $msg = \App\Models\Message::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000008')->first();
        $this->assertSame('Your order', $msg->body);
        $this->assertSame('image', $msg->meta['media']['kind']);
        $convo = \App\Models\Conversation::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('customer_phone', '256700000008')->first();
        $this->assertTrue((bool) $convo->agent_active, 'sending a photo takes the chat over, same as text');
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
