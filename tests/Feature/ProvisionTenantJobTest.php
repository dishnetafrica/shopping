<?php

namespace Tests\Feature;

use App\Jobs\NotifyOwner;
use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\OnboardingState;
use App\Support\OnboardingTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * ProvisionTenantJob (Stage 0 rev 2): three canonical paths — happy path, retryable
 * failure, permanent failure. Plus the monotonic-safety property: a late failure
 * arriving after success never marks the tenant Failed.
 */
class ProvisionTenantJobTest extends TestCase
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

    public function test_happy_path_reaches_whatsapp_ready(): void
    {
        $t = $this->makeTenant();

        $evo = Mockery::mock(EvolutionAdmin::class);
        $evo->shouldReceive('createIfMissing')->once()->andReturn(['exists' => false]);
        $evo->shouldReceive('setWebhook')->once()->andReturnNull();
        $evo->shouldReceive('state')->andReturn('connecting');
        $this->app->instance(EvolutionAdmin::class, $evo);

        (new ProvisionTenantJob($t->id, 'testcorr01'))->handle(
            app(\App\Support\TenantContext::class), $evo
        );

        $t->refresh();
        $this->assertSame(OnboardingState::WHATSAPP_READY, OnboardingState::current($t));
        $this->assertSame('shopbot_t' . $t->id, $t->whatsapp_instance);

        $done = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'provision_done')->first();
        $this->assertNotNull($done, 'provision_done trace written');
        $this->assertGreaterThanOrEqual(0, (int) $done->ms, 'duration recorded');
    }

    public function test_retryable_failure_throws_and_leaves_state_provisioning(): void
    {
        $t = $this->makeTenant();

        $evo = Mockery::mock(EvolutionAdmin::class);
        $evo->shouldReceive('createIfMissing')->once()->andReturn(['error' => 'evolution down']);
        $evo->shouldReceive('setWebhook')->never();
        $this->app->instance(EvolutionAdmin::class, $evo);

        $threw = false;
        try {
            (new ProvisionTenantJob($t->id, 'testcorr02'))->handle(
                app(\App\Support\TenantContext::class), $evo
            );
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertStringContainsString('createIfMissing failed', $e->getMessage());
        }
        $this->assertTrue($threw, 'job throws on transient failure to trigger queue retry');

        $t->refresh();
        // State remains Provisioning — queue driver will retry the job.
        $this->assertSame(OnboardingState::PROVISIONING, OnboardingState::current($t));

        $fail = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'wa_create_failed')->first();
        $this->assertNotNull($fail);
    }

    public function test_permanent_failure_via_failed_handler_marks_failed(): void
    {
        Queue::fake();
        $t = $this->makeTenant();
        $t->putSetting('onboarding_state', OnboardingState::PROVISIONING);

        $job = new ProvisionTenantJob($t->id, 'testcorr03');
        $job->failed(new \RuntimeException('exhausted all 5 retries'));

        $t->refresh();
        $this->assertSame(OnboardingState::FAILED, OnboardingState::current($t));

        Queue::assertPushed(NotifyOwner::class);

        $trace = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'provision_failed')->first();
        $this->assertNotNull($trace);
    }

    public function test_stale_late_failure_does_not_mark_tenant_failed(): void
    {
        Queue::fake();
        $t = $this->makeTenant();
        // Simulate: an earlier attempt already succeeded.
        $t->putSetting('onboarding_state', OnboardingState::WHATSAPP_READY);

        $job = new ProvisionTenantJob($t->id, 'testcorr04');
        $job->failed(new \RuntimeException('late failure from stale worker'));

        $t->refresh();
        // Monotonic guarantee: state stays at WhatsAppReady.
        $this->assertSame(OnboardingState::WHATSAPP_READY, OnboardingState::current($t));

        // Owner is NOT alerted for a spurious late failure.
        Queue::assertNotPushed(NotifyOwner::class);

        $ignored = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'provision_late_failure_ignored')->first();
        $this->assertNotNull($ignored, 'late failure is traced but not acted on');
    }
}
