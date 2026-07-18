<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Services\Relationship\EscalationService;
use App\Services\Relationship\PlanParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V4.2: the bot thinks first — and silence + escalation are honored, not just parsed. */
class PlanningEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_block_is_parsed_validated_and_stripped(): void
    {
        $r = PlanParser::parse('<<PLAN {"purpose":"escalate","escalate_reason":"angry_customer","note":"refund demand"}>>'."\nI'm connecting you with our team right away.");
        $this->assertSame('escalate', $r['plan']['purpose']);
        $this->assertSame('angry_customer', $r['plan']['escalate_reason']);
        $this->assertSame("I'm connecting you with our team right away.", $r['reply'],
            'the customer never sees the machine block');

        $silent = PlanParser::parse('<<PLAN {"purpose":"silent"}>>');
        $this->assertSame('silent', $silent['plan']['purpose']);
        $this->assertSame('', $silent['reply']);

        $legacy = PlanParser::parse('Just a normal reply.');
        $this->assertNull($legacy['plan'], 'no plan block → passthrough, fully backward compatible');
        $this->assertSame('Just a normal reply.', $legacy['reply']);

        $bad = PlanParser::parse('<<PLAN {"purpose":"world_domination"}>>Hello');
        $this->assertNull($bad['plan'], 'invalid purpose → ignored');
        $this->assertSame('Hello', $bad['reply'], '…but the block is still stripped');
    }

    public function test_escalation_uses_real_mechanisms_takeover_stamp_trace(): void
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        Conversation::withoutGlobalScope('tenant')->create(['tenant_id' => $t->id,
            'customer_phone' => '211900000031', 'instance' => 'i1', 'state' => [], 'cart' => [],
            'agent_active' => false]);

        app(EscalationService::class)->raise($t, '211900000031', 'credit_request', 'wants 30-day terms');

        $c = Conversation::withoutGlobalScope('tenant')->where('tenant_id', $t->id)
            ->where('customer_phone', '211900000031')->first();
        $this->assertTrue((bool) $c->agent_active, 'escalation TAKES OVER — the bot stops, the proven mechanic');

        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('phone', '211900000031')->first();
        $this->assertStringContainsString('ESCALATED: Credit request', (string) $p->follow_up);
        $this->assertStringContainsString('30-day terms', (string) $p->follow_up);

        $this->assertDatabaseHas('bot_traces', ['tenant_id' => $t->id, 'trace' => 'escalation',
            'stage' => 'credit_request'], 'the WHY is recorded — V4.3 reads these');
    }

    public function test_the_brain_is_actually_wired_flag_gated(): void
    {
        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString("setting('ai_planning', false)", $src, 'per-tenant flag, default OFF');
        $this->assertStringContainsString('PlanParser::instructions()', $src, 'the prompt teaches the plan block');
        $this->assertStringContainsString("in_array(\$plan['purpose'], ['silent', 'wait']", $src,
            'silence must be HONORED — return without sending');
        $this->assertStringContainsString('EscalationService::class', $src);

        $ctx = file_get_contents(app_path('Services/Relationship/ProfileContext.php'));
        $this->assertStringContainsString('Playbook for this stage', $ctx,
            'stage playbooks ride the same context block into AiBrain AND GoalEngine drafts');
    }
}
