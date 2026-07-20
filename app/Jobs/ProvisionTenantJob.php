<?php

namespace App\Jobs;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\OnboardingState;
use App\Support\OnboardingTrace;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Async tenant provisioning (Stage 0 rev 2).
 *
 * Runs after DB commit, so signup returns to the user immediately regardless of
 * Evolution's availability. Idempotent (safe to run twice; second run is a no-op
 * once the tenant reaches WhatsAppReady). Monotonic state transitions.
 *
 * Retry ladder: 30s → 2m → 5m → 15m → 1h. All retries are transient by design.
 * After 5 attempts, failed() marks state=Failed (if not already succeeded) and
 * alerts the owner. OnboardingRecoverCommand can re-dispatch manually.
 *
 * WithoutOverlapping ensures two accidental dispatches for the same tenant
 * serialize instead of racing.
 */
class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 300, 900, 3600];

    public function __construct(
        public int $tenantId,
        public string $correlationId,
    ) {}

    public function middleware(): array
    {
        // Concurrency guard: only one provisioning job runs per tenant at a time.
        return [(new WithoutOverlapping('provision-tenant-' . $this->tenantId))->releaseAfter(120)];
    }

    public function handle(TenantContext $ctx, EvolutionAdmin $evo): void
    {
        $ctx->set($this->tenantId);
        $t = Tenant::find($this->tenantId);
        if (! $t) return;

        // Idempotency short-circuit: already provisioned means nothing to do.
        if (OnboardingState::isProvisioned($t)) {
            OnboardingTrace::log($t->id, 'provision_skipped_already_ready', null,
                $this->correlationId, $this->attempts() - 1, 'system', null, $t->whatsapp_number);
            return;
        }

        $startedAt = microtime(true);
        OnboardingState::transition($t, OnboardingState::PROVISIONING);
        OnboardingTrace::log($t->id, 'provision_start', null,
            $this->correlationId, $this->attempts() - 1, 'system', null, $t->whatsapp_number);

        // Assign the canonical instance name if not already set.
        $instance = $t->whatsapp_instance ?: ('shopbot_t' . $t->id);
        if ($t->whatsapp_instance !== $instance) {
            $t->whatsapp_instance = $instance;
            $t->whatsapp_driver   = $t->whatsapp_driver ?: 'evolution';
            $t->save();
        }

        // Step: createIfMissing (idempotent by design in EvolutionAdmin)
        $stepStart = microtime(true);
        $create = $evo->createIfMissing($instance);
        $createMs = (int) round((microtime(true) - $stepStart) * 1000);

        if (isset($create['error'])) {
            OnboardingTrace::log($t->id, 'wa_create_failed', $createMs,
                $this->correlationId, $this->attempts() - 1, 'system',
                ['error' => substr((string) $create['error'], 0, 160)], $t->whatsapp_number);
            // Throw to trigger retry with backoff.
            throw new \RuntimeException('createIfMissing failed: ' . $create['error']);
        }
        OnboardingTrace::log($t->id, 'wa_created', $createMs,
            $this->correlationId, $this->attempts() - 1, 'system',
            ['exists' => (bool) ($create['exists'] ?? false)], $t->whatsapp_number);

        // Step: setWebhook (idempotent — Evolution accepts re-registration)
        $stepStart = microtime(true);
        $evo->setWebhook($instance, url('/api/webhook/whatsapp/evolution'));
        $hookMs = (int) round((microtime(true) - $stepStart) * 1000);
        OnboardingTrace::log($t->id, 'webhook_set', $hookMs,
            $this->correlationId, $this->attempts() - 1, 'system', null, $t->whatsapp_number);

        // Step: verify state — if still 'missing', trigger retry.
        $stepStart = microtime(true);
        $state = $evo->state($instance);
        $stateMs = (int) round((microtime(true) - $stepStart) * 1000);
        if ($state === 'missing') {
            OnboardingTrace::log($t->id, 'wa_verify_missing', $stateMs,
                $this->correlationId, $this->attempts() - 1, 'system', null, $t->whatsapp_number);
            throw new \RuntimeException('Evolution instance not visible after create');
        }

        // Mirror the primary channel record.
        try {
            $t->syncPrimaryChannel();
        } catch (Throwable $e) {
            // Non-fatal; log and continue.
            OnboardingTrace::log($t->id, 'channel_sync_warning', null,
                $this->correlationId, $this->attempts() - 1, 'system',
                ['error' => substr($e->getMessage(), 0, 160)], $t->whatsapp_number);
        }

        // Terminal success. Monotonic transition: this will no-op if state has
        // already advanced past WhatsAppReady (defensive against stale retries).
        OnboardingState::transition($t, OnboardingState::WHATSAPP_READY);
        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        OnboardingTrace::log($t->id, 'provision_done', $totalMs,
            $this->correlationId, $this->attempts() - 1, 'system',
            ['state' => $state], $t->whatsapp_number);
    }

    /**
     * Terminal failure after all retries. Do NOT overwrite a successful state
     * — the transition method itself protects this, but we also check here to
     * skip the owner alert on a spurious late failure.
     */
    public function failed(Throwable $e): void
    {
        try {
            $t = Tenant::find($this->tenantId);
            if (! $t) return;

            if (OnboardingState::isProvisioned($t)) {
                // A late failure arrived after a successful attempt. Trace it,
                // but do not alarm the owner and do not touch the state.
                OnboardingTrace::log($t->id, 'provision_late_failure_ignored', null,
                    $this->correlationId, $this->attempts(), 'system',
                    ['error' => substr($e->getMessage(), 0, 160)], $t->whatsapp_number);
                return;
            }

            OnboardingState::transition($t, OnboardingState::FAILED);
            OnboardingTrace::log($t->id, 'provision_failed', null,
                $this->correlationId, $this->attempts(), 'system',
                ['error' => substr($e->getMessage(), 0, 160)], $t->whatsapp_number);

            NotifyOwner::dispatch($t->id,
                "\u{26A0}\u{FE0F} Onboarding: WhatsApp provisioning failed after "
                . $this->tries . " retries. Run: php artisan onboarding:recover --tenant=" . $t->id);
        } catch (Throwable $inner) {
            // Never throw from failed(); the queue system is already handling one exception.
        }
    }
}
