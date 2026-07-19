<?php

namespace Tests\Feature;

use App\Models\FollowupProposal;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner-number exclusion (backlog polish): the machine never chases the owner. */
class AutosendOwnerExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_number_is_held_not_sent(): void
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('followups_auto_send', true);
        $t->putSetting('watchdog_hours', '0-24');
        $t->putSetting('owner_alert_phone', '211927797217');

        FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => '211927797217',
            'source' => 'goal_engine', 'trigger' => 'quote_chase', 'trigger_reason' => 't',
            'evidence' => 'e', 'draft' => 'd', 'confidence' => 'high',
            'expected_outcome' => 'x', 'status' => 'draft']);
        FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => '211900000099',
            'source' => 'goal_engine', 'trigger' => 'quote_chase', 'trigger_reason' => 't',
            'evidence' => 'e', 'draft' => 'd', 'confidence' => 'high',
            'expected_outcome' => 'x', 'status' => 'draft']);

        $this->artisan('relationship:autosend', ['--tenant' => $t->id, '--dry' => true])
            ->expectsOutputToContain('would send quote_chase → 211900000099')
            ->expectsOutputToContain('1 owner-number')
            ->assertExitCode(0);
    }
}
