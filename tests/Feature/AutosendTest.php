<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\FollowupProposal;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Autonomy with rails (2026-07-19): the machine sends alone, but never rudely, never over cap. */
class AutosendTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $on = true): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('followups_auto_send', $on);
        $t->putSetting('watchdog_hours', '0-24');
        return $t;
    }

    private function proposal(Tenant $t, string $phone, string $conf = 'high'): FollowupProposal
    {
        return FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => $phone,
            'source' => 'goal_engine', 'trigger' => 'quote_chase', 'trigger_reason' => 'test',
            'evidence' => 'e', 'draft' => 'Hello from the plan', 'confidence' => $conf,
            'expected_outcome' => 'x', 'status' => 'proposed']);
    }

    public function test_flag_off_means_nothing_moves(): void
    {
        $t = $this->tenant(false);
        $this->proposal($t, '211900000081');
        $this->artisan('relationship:autosend', ['--tenant' => $t->id, '--dry' => true])->assertExitCode(0);
        $this->assertSame('proposed', FollowupProposal::first()->status);
    }

    public function test_rails_hold_the_right_ones(): void
    {
        $t = $this->tenant();
        $this->proposal($t, '211900000082', 'high');            // sendable
        $this->proposal($t, '211900000083', 'medium');          // held: confidence
        $this->proposal($t, '211900000084', 'high');            // held: human-active
        Conversation::withoutGlobalScope('tenant')->create(['tenant_id' => $t->id,
            'customer_phone' => '211900000084', 'instance' => 'i1', 'state' => [], 'cart' => [],
            'agent_active' => true]);

        $this->artisan('relationship:autosend', ['--tenant' => $t->id, '--dry' => true])
            ->expectsOutputToContain('would send quote_chase → 211900000082')
            ->expectsOutputToContain('1 low-confidence')
            ->expectsOutputToContain('1 human-active')
            ->assertExitCode(0);
    }

    public function test_daily_cap_is_a_hard_ceiling(): void
    {
        $t = $this->tenant();
        $t->putSetting('followups_auto_daily_cap', 2);
        foreach (['211900000085', '211900000086', '211900000087'] as $p) $this->proposal($t, $p);

        $this->artisan('relationship:autosend', ['--tenant' => $t->id, '--dry' => true])
            ->expectsOutputToContain('1 over cap')
            ->assertExitCode(0);
    }
}
