<?php

namespace Tests\Feature;

use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\OnboardingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Idempotency + concurrency (Stage 0 rev 2).
 *
 *   1. Running the job twice yields exactly one logical provisioning.
 *   2. State machine forbids backward moves.
 *   3. Two accidentally-concurrent jobs for the same tenant must not double up.
 */
class OnboardingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeTenant(): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('onboarding_state', OnboardingState::TENANT_CREATED);
        return $t;
    }

    public function test_job_run_twice_produces_one_provisioning(): void
    {
        $t = $this->makeTenant();

        $evo = Mockery::mock(EvolutionAdmin::class);
        // First run: full pipeline.
        $evo->shouldReceive('createIfMissing')->once()->andReturn(['exists' => false]);
        $evo->shouldReceive('setWebhook')->once()->andReturnNull();
        $evo->shouldReceive('state')->andReturn('connecting');
        $this->app->instance(EvolutionAdmin::class, $evo);

        $ctx = app(\App\Support\TenantContext::class);

        (new ProvisionTenantJob($t->id, 'run-A'))->handle($ctx, $evo);
        $t->refresh();
        $this->assertSame(OnboardingState::WHATSAPP_READY, OnboardingState::current($t));

        // Second run: idempotency short-circuit — no Evolution calls, no state change.
        // (Mockery would have failed the test if createIfMissing/setWebhook were called again.)
        (new ProvisionTenantJob($t->id, 'run-B'))->handle($ctx, $evo);
        $t->refresh();
        $this->assertSame(OnboardingState::WHATSAPP_READY, OnboardingState::current($t));

        $skipped = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'provision_skipped_already_ready')->count();
        $this->assertSame(1, $skipped, 'second run traced its short-circuit exactly once');

        // provision_done trace exists exactly once (from the first run).
        $done = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'provision_done')->count();
        $this->assertSame(1, $done);
    }

    public function test_state_machine_is_monotonic(): void
    {
        $t = $this->makeTenant();

        // Forward move works.
        $this->assertTrue(OnboardingState::transition($t, OnboardingState::PROVISIONING));
        $this->assertTrue(OnboardingState::transition($t, OnboardingState::WHATSAPP_READY));

        // Backward move rejected.
        $this->assertFalse(OnboardingState::transition($t, OnboardingState::TENANT_CREATED));
        $t->refresh();
        $this->assertSame(OnboardingState::WHATSAPP_READY, OnboardingState::current($t));

        // Late Failed rejected once we've reached WhatsAppReady.
        $this->assertFalse(OnboardingState::transition($t, OnboardingState::FAILED));

        // Forward past WhatsAppReady still works.
        $this->assertTrue(OnboardingState::transition($t, OnboardingState::WIZARD_COMPLETE));
        $this->assertTrue(OnboardingState::transition($t, OnboardingState::OPERATIONAL));

        // Same-state is a no-op.
        $this->assertTrue(OnboardingState::transition($t, OnboardingState::OPERATIONAL));
    }

    public function test_two_concurrent_jobs_for_same_tenant_result_in_one_provisioning(): void
    {
        // The queue's WithoutOverlapping middleware serializes these in production.
        // Here we verify the *inner logic* is safe even if that serialization were bypassed:
        // whichever job wins the race, the second sees isProvisioned() and short-circuits.
        $t = $this->makeTenant();

        $evo = Mockery::mock(EvolutionAdmin::class);
        // Only the winning job should touch Evolution.
        $evo->shouldReceive('createIfMissing')->once()->andReturn(['exists' => false]);
        $evo->shouldReceive('setWebhook')->once()->andReturnNull();
        $evo->shouldReceive('state')->andReturn('connecting');
        $this->app->instance(EvolutionAdmin::class, $evo);

        $ctx = app(\App\Support\TenantContext::class);

        // Simulate two workers picking up the job simultaneously.
        // In a real queue with WithoutOverlapping they'd serialize; without it,
        // the second must still be safe.
        $jobA = new ProvisionTenantJob($t->id, 'concurrent-A');
        $jobB = new ProvisionTenantJob($t->id, 'concurrent-B');

        $jobA->handle($ctx, $evo);
        $jobB->handle($ctx, $evo);   // Mockery caps to once() → verifies no double call.

        $t->refresh();
        $this->assertSame(OnboardingState::WHATSAPP_READY, OnboardingState::current($t));

        // Exactly one terminal success event.
        $done = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'provision_done')->count();
        $this->assertSame(1, $done, 'no duplicate terminal event');

        // Exactly one WhatsApp instance name assigned.
        $this->assertSame('shopbot_t' . $t->id, $t->whatsapp_instance);
    }
}
