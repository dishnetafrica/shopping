<?php

namespace Tests\Feature;

use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OnboardingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Self-serve signup (Stage 0 rev 2): the HTTP endpoint creates the tenant + user,
 * initialises the state machine, traces the event, and dispatches provisioning
 * without waiting for it.
 */
class SelfServeOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_creates_tenant_dispatches_job_and_returns_immediately(): void
    {
        Queue::fake();

        $response = $this->postJson('/papi/start-provision', [
            'name'       => 'Mahmoud Coffee Trading',
            'kind'       => 'grocery',
            'owner_name' => 'Mahmoud Khemis',
            'whatsapp'   => '+211923871551',
            'email'      => '',
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true, 'redirect' => '/v3']);

        $tenant = Tenant::where('name', 'Mahmoud Coffee Trading')->first();
        $this->assertNotNull($tenant, 'tenant row created');
        $this->assertNotEmpty($tenant->slug);

        $this->assertSame(OnboardingState::TENANT_CREATED, OnboardingState::current($tenant),
            'state machine initialised at TenantCreated');

        $this->assertNotEmpty($tenant->setting('onboarding_corr'),
            'correlation id persisted on tenant');

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'role'      => 'owner',
        ]);

        // Session was authenticated for the new owner.
        $this->assertAuthenticated();

        // Async: the job was queued, not executed inline.
        Queue::assertPushed(ProvisionTenantJob::class, function ($job) use ($tenant) {
            return $job->tenantId === $tenant->id && ! empty($job->correlationId);
        });

        // Trace was written after commit (not swallowed by rollback).
        $trace = DB::table('bot_events')
            ->where('tenant_id', $tenant->id)
            ->where('trace', 'onboarding')
            ->where('stage', 'signup_complete')
            ->first();
        $this->assertNotNull($trace, 'signup_complete trace written');
    }

    public function test_duplicate_signup_returns_409(): void
    {
        Queue::fake();

        // First signup
        $this->postJson('/papi/start-provision', [
            'name' => 'First Shop', 'kind' => 'grocery',
            'owner_name' => 'A', 'whatsapp' => '+211900000001', 'email' => '',
        ])->assertStatus(200);

        // Log out so the second attempt is a fresh signup, not an already-authenticated user.
        auth()->logout();

        // Second signup with same phone → 409
        $this->postJson('/papi/start-provision', [
            'name' => 'Second Shop', 'kind' => 'grocery',
            'owner_name' => 'B', 'whatsapp' => '+211900000001', 'email' => '',
        ])->assertStatus(409)->assertJsonPath('error', 'exists');
    }
}
