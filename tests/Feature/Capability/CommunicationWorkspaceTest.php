<?php

namespace Tests\Feature\Capability;

use App\Capability\Registry\CapabilityRegistry;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migration plan G2 — the Email Center as a v3 workspace. Read projection + the five
 * thin papi adapters (the heavy write paths are already covered by CommsEmailTest /
 * CommsWhatsAppTest / CommsCampaignTest).
 */
class CommunicationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function login(string $role = 'owner'): User
    {
        $this->t ??= Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($this->t->id);
        $u = User::factory()->create(['tenant_id' => $this->t->id, 'role' => $role]);
        $this->actingAs($u);
        return $u;
    }

    private function msg(array $over = []): CommMessage
    {
        return CommMessage::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->t->id, 'channel' => 'email', 'token' => bin2hex(random_bytes(12)),
            'to_email' => 'x@example.com', 'subject' => 's', 'text_body' => 'b',
            'status' => 'pending', 'attempts' => 0, 'max_attempts' => 5, 'template_key' => 'invoice',
        ], $over));
    }

    public function test_registry_and_projection_shape(): void
    {
        $this->login();
        $r = app(CapabilityRegistry::class);
        $this->assertTrue($r->has('communication'));
        $this->assertSame('menu:settings', $r->manifest('communication')->permission);

        $this->msg(['status' => 'failed', 'last_error' => 'bounced hard']);
        $this->msg(['status' => 'sent', 'sent_at' => now()]);
        CommSuppression::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->t->id, 'channel' => 'email', 'address' => 'gone@example.com',
            'reason' => 'bounce', 'source' => 'webhook',
        ]);

        $j = $this->getJson('/api/v1/workspace/communication')->assertOk()->json();

        $this->assertSame('process:communication', $j['object']['type']);
        $tabs = collect($j['tabs'])->pluck('key');
        foreach (['overview', 'queue', 'templates', 'providers', 'routing', 'campaigns', 'suppressions', 'setup'] as $k) {
            $this->assertTrue($tabs->contains($k), "tab {$k} missing");
        }
        $kpi = collect($j['financials'])->keyBy('key');
        $this->assertSame('1', $kpi['failed']['value']);
        $this->assertSame('1', $kpi['sent24']['value']);
        $this->assertSame('1', $kpi['supp']['value']);

        // Suppression tab lists the address; quick actions include the un-suppress combo.
        $suppRows = $j['tabSections']['suppressions'][0]['rows'];
        $this->assertStringContainsString('gone@example.com', json_encode($suppRows));
        $keys = collect($j['quickActions'])->pluck('key');
        $this->assertTrue($keys->contains('retry_msg'));
        $this->assertTrue($keys->contains('suppress_rm'));
    }

    public function test_permission_gate(): void
    {
        $this->login('sales');   // sales has no menu:settings
        $this->getJson('/api/v1/workspace/communication')->assertStatus(403);
    }

    public function test_suppression_add_and_remove_adapters(): void
    {
        $this->login();
        $this->postJson('/papi/comms/suppressions/add', [
            'channel' => 'email', 'address' => 'STOP@Example.com', 'reason' => 'asked us',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, CommSuppression::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->t->id)->where('address', 'stop@example.com')->count());

        $this->postJson('/papi/comms/suppressions/remove', ['address' => 'stop@example.com'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, CommSuppression::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->t->id)->count());
    }

    public function test_body_id_retry_accepts_combo_labels(): void
    {
        $this->login();
        $m = $this->msg(['status' => 'failed', 'last_error' => 'x']);

        // The v3 combo sends the label verbatim; (int) cast takes the leading digits.
        $this->postJson('/papi/comms/messages/retry', ['id' => $m->id.' — x@example.com · bounced'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('retry', $m->fresh()->status);
    }

    public function test_edit_template_chooser_carries_prefilled_forms(): void
    {
        $this->login();
        $tpl = \App\Models\CommTemplate::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->t->id, 'channel' => 'email', 'key' => 'invoice', 'locale' => 'en',
            'name' => 'Invoice', 'subject' => 'Invoice {{invoice_no}}', 'text_body' => 'Dear {{name}}', 'is_active' => true,
        ]);

        $j = $this->getJson('/api/v1/workspace/communication')->assertOk()->json();
        $edit = collect($j['quickActions'])->firstWhere('key', 'edit_template');
        $this->assertNotNull($edit, 'round 2: template editing must exist');
        $tile = collect($edit['chooser'])->first(fn ($x) => str_contains($x['title'], 'invoice'));
        $this->assertNotNull($tile);
        $a = $tile['action'];
        $this->assertSame('/papi/comms/templates/save', $a['endpoint']);
        $this->assertSame($tpl->id, $a['body']['id']);
        $this->assertSame('Dear {{name}}', $a['prefill']['text_body'], 'the form must open PREFILLED');
        $this->assertSame('textarea', $a['schema']['text_body']);
    }

    public function test_campaign_save_simple_translates_segment(): void
    {
        $this->login();
        $this->postJson('/papi/comms/campaigns/save-simple', [
            'name' => 'Winback July', 'channel' => 'email',
            'segment_type' => 'not_ordered_within_days', 'segment_value' => '30',
            'subject' => 'We miss you', 'text_body' => 'Hi {{name}}', 'throttle_per_minute' => 60,
        ])->assertOk()->assertJson(['ok' => true]);

        $c = \App\Models\CommCampaign::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->t->id)->where('name', 'Winback July')->first();
        $this->assertNotNull($c);
        $this->assertSame(['not_ordered_within_days' => 30], (array) $c->segment);

        $est = $this->postJson('/papi/comms/campaigns/estimate-simple', [
            'channel' => 'email', 'segment_type' => 'all', 'segment_value' => '',
        ])->assertOk()->json();
        $this->assertStringContainsString('Audience', $est['message']);
    }

    public function test_flat_routing_save_translates_and_persists(): void
    {
        $this->login();
        $this->postJson('/papi/comms/routes/save-simple', [
            'ev_invoice' => 'both', 'ev_receipt' => 'off', 'ev_otp' => 'whatsapp',
        ])->assertOk()->assertJson(['ok' => true]);

        $routes = (array) $this->t->fresh()->setting('comms_routes', []);
        $this->assertSame(['email', 'whatsapp'], $routes['invoice']);
        $this->assertSame([], $routes['receipt']);
        $this->assertSame(['whatsapp'], $routes['otp']);
    }
}
