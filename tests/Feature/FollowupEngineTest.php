<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\FollowupProposal;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Relationship\GoalEngine;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V4.1 Goal Engine: deterministic WHEN, drafted WHAT, human-approved WHO, structural anti-spam. */
class FollowupEngineTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('followups_ai', true);
        return $t;
    }

    /** AI unavailable on purpose — the deterministic template must carry the candidate. */
    private function engine(): GoalEngine
    {
        return new class extends GoalEngine {
            protected function draft(Tenant $t, array $c): ?string { return null; }
        };
    }

    public function test_open_quotation_becomes_a_proposal_once_and_only_once(): void
    {
        $t = $this->tenant();
        Quotation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'quote_no' => 'Q-100',
            'customer_name' => 'Shop 58', 'customer_phone' => '211900000021', 'currency' => 'USD',
            'total' => 480, 'status' => 'sent', 'created_at' => now()->subDays(3)]);

        $this->assertSame(1, $this->engine()->plan($t));

        $p = FollowupProposal::where('tenant_id', $t->id)->first();
        $this->assertSame('quote_chase', $p->trigger);
        $this->assertSame('high', $p->confidence, '3-day-old quote = high confidence, BY RULE');
        $this->assertStringContainsString('Q-100', $p->trigger_reason);
        $this->assertNotSame('', trim($p->draft), 'template fallback — no candidate lost to an AI outage');

        $this->assertSame(0, $this->engine()->plan($t), 'one open proposal per customer — rerun creates nothing');
    }

    public function test_abandoned_cart_becomes_a_hot_proposal_but_never_after_an_order(): void
    {
        $t = $this->tenant();
        $mk = function (string $phone, $updatedAt, array $cart) use ($t) {
            $c = \App\Models\Conversation::withoutGlobalScope('tenant')->create([
                'tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'i1',
                'state' => [], 'cart' => $cart, 'agent_active' => false]);
            \App\Models\Conversation::withoutGlobalScope('tenant')->where('id', $c->id)
                ->update(['updated_at' => $updatedAt]);
            return $c;
        };
        $p = \App\Models\Product::withoutGlobalScopes()->create(['tenant_id' => $t->id,
            'name' => 'Marhaba Soap', 'price' => 5000, 'base_price' => 5000, 'active' => true]);

        $mk('211900000051', now()->subHours(5), [$p->id => 3]);              // idle 5h → candidate
        $mk('211900000052', now()->subMinutes(30), [$p->id => 2]);           // too fresh → skip
        $mk('211900000053', now()->subHours(6), [$p->id => 1]);              // ordered after → skip
        \App\Models\Order::withoutGlobalScopes()->create(['tenant_id' => $t->id,
            'customer_phone' => '211900000053', 'customer_name' => 'X', 'items' => [],
            'total' => 5000, 'status' => 'completed']);

        $this->assertSame(1, $this->engine()->plan($t));
        $fp = \App\Models\FollowupProposal::where('tenant_id', $t->id)->first();
        $this->assertSame('cart_abandoned', $fp->trigger);
        $this->assertSame('high', $fp->confidence, 'a basket from today = the hottest lead, BY RULE');
        $this->assertSame('211900000051', $fp->customer_phone);
        $this->assertStringContainsString('15,000', $fp->evidence, 'cart value computed from panel prices');
    }

    public function test_global_touch_cap_blocks_recently_contacted_customers(): void
    {
        $t = $this->tenant();
        Quotation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'quote_no' => 'Q-101',
            'customer_name' => 'Azam', 'customer_phone' => '211900000022', 'currency' => 'USD',
            'total' => 100, 'status' => 'sent', 'created_at' => now()->subDays(3)]);
        CustomerProfile::withoutGlobalScopes()->create(['tenant_id' => $t->id,
            'phone' => '211900000022', 'last_contacted_at' => now()->subDay()]);   // cadence touched them yesterday

        $this->assertSame(0, $this->engine()->plan($t),
            'ANY engine touched them < cap days ago → the Goal Engine stays silent');
    }

    public function test_approve_sends_marks_sent_and_stamps_the_touch_cap(): void
    {
        $t = $this->tenant();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);

        $gw = new class implements \App\Contracts\WhatsAppGateway {
            public array $sent = [];
            public function sendText(string $i, string $to, string $m, ?array $q = null): array { $this->sent[] = [$to, $m]; return ['ok' => true]; }
            public function sendImage(string $i, string $to, string $m, string $c = ''): array { return ['ok' => true]; }
            public function markRead(string $i, string $id): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $i): array { return []; }
        };
        $mgr = new class($gw) extends WhatsAppManager {
            public function __construct(private \App\Contracts\WhatsAppGateway $g) {}
            public function forTenant(Tenant $t): \App\Contracts\WhatsAppGateway { return $this->g; }
        };
        $this->app->instance(WhatsAppManager::class, $mgr);

        $p = FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => '211900000023',
            'trigger' => 'reorder', 'trigger_reason' => 'cycle due', 'draft' => 'Original draft', 'confidence' => 'medium']);

        $this->postJson('/papi/followups/'.$p->id.'/approve', ['draft' => 'Edited by Mecklyne'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['211900000023', 'Edited by Mecklyne'], $gw->sent[0], 'the EDITED draft is what goes out');
        $p->refresh();
        $this->assertSame('sent', $p->status);
        $this->assertNotNull($p->sent_message_id, 'linked to the transcript — V4.3 attribution ready');
        $prof = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('phone', '211900000023')->first();
        $this->assertNotNull($prof->last_contacted_at, 'the send stamps the global touch cap');

        $this->postJson('/papi/followups/'.$p->id.'/approve')->assertStatus(422);   // never double-send
    }

    public function test_skip_records_the_teaching_reason(): void
    {
        $t = $this->tenant();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);
        $p = FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => '211900000024',
            'trigger' => 'winback', 'trigger_reason' => 'dormant buyer', 'draft' => 'd', 'confidence' => 'low']);

        $this->postJson('/papi/followups/'.$p->id.'/skip', ['skip_reason' => 'He visits the shop weekly anyway'])
            ->assertOk();
        $this->assertSame('He visits the shop weekly anyway', $p->fresh()->skip_reason,
            'every skip reason is V4.3 training data');
    }
}
