<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Support\OnboardingState;
use App\Support\OnboardingTrace;
use Illuminate\Console\Command;

/**
 * onboarding:recover — recovery tool only (Stage 0 rev 2).
 *
 * The normal onboarding path is ProvisionTenantJob dispatched at signup. This command
 * exists for two situations:
 *
 *   1. A tenant is stuck in state=Failed (all 5 retries exhausted) — re-dispatch.
 *   2. Legacy tenants created before the state machine existed — assign a state and,
 *      if they lack a working WhatsApp instance, re-dispatch.
 *
 * Idempotent. Safe to run repeatedly. Never touches tenants already Operational.
 *
 *   php artisan onboarding:recover                     # dry run across all tenants
 *   php artisan onboarding:recover --apply             # re-dispatch where needed
 *   php artisan onboarding:recover --tenant=17         # target one tenant
 *   php artisan onboarding:recover --tenant=17 --apply
 */
class OnboardingRecoverCommand extends Command
{
    protected $signature   = 'onboarding:recover {--tenant=0} {--apply : Actually dispatch; default is dry-run}';
    protected $description = 'Recover tenants stuck in Failed state or lacking provisioning';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $one   = (int) $this->option('tenant');

        $query = Tenant::query();
        if ($one > 0) $query->whereKey($one);
        $tenants = $query->get();

        $needsWorkCount = 0;
        $dispatchedCount = 0;
        $backfilledCount = 0;
        $skipped = 0;

        foreach ($tenants as $t) {
            $state = OnboardingState::current($t);
            $hasInstance = ! empty($t->whatsapp_instance);

            // Already terminal in the state machine → leave alone.
            if (OnboardingState::isProvisioned($t)) {
                $skipped++;
                continue;
            }

            // Legacy tenant (state=Pending because the field predates the state machine)
            // that ALREADY has a WhatsApp instance is provisioned — just backfill its
            // state to WhatsAppReady. Do NOT re-dispatch (that would re-provision a
            // healthy production tenant).
            if ($hasInstance && $state === OnboardingState::PENDING) {
                $this->line(sprintf('  tenant %d (%s): has instance %s, state=Pending → %s',
                    $t->id, $t->name, $t->whatsapp_instance,
                    $apply ? 'BACKFILLING state=WhatsAppReady' : 'would backfill state'));
                if ($apply) {
                    // Direct set (bypass monotonic guard: Pending→WhatsAppReady is a valid
                    // forward jump, and transition() allows it since ranks increase).
                    OnboardingState::transition($t, OnboardingState::WHATSAPP_READY);
                    OnboardingTrace::log($t->id, 'recover_backfilled', null,
                        OnboardingTrace::newCorrelationId(), 0, 'operator',
                        ['instance' => $t->whatsapp_instance], $t->whatsapp_number);
                    $backfilledCount++;
                }
                continue;
            }

            // Genuinely needs provisioning: Failed state, OR no instance at all.
            $needsWork = $state === OnboardingState::FAILED
                || (! $hasInstance);

            if (! $needsWork) {
                $skipped++;
                continue;
            }

            $needsWorkCount++;
            $this->line(sprintf('  tenant %d (%s): state=%s instance=%s → %s',
                $t->id, $t->name, $state, $hasInstance ? $t->whatsapp_instance : '(none)',
                $apply ? 'RE-DISPATCHING' : 'would re-dispatch'));

            if ($apply) {
                $corr = OnboardingTrace::newCorrelationId();
                OnboardingTrace::log($t->id, 'recover_dispatched', null, $corr, 0, 'operator',
                    ['from_state' => $state], $t->whatsapp_number);
                ProvisionTenantJob::dispatch($t->id, $corr);
                $dispatchedCount++;
            }
        }

        $this->line('');
        if ($apply) {
            $this->info("Backfilled {$backfilledCount} · re-dispatched {$dispatchedCount} · skipped {$skipped} (healthy).");
        } else {
            $this->info("{$needsWorkCount} would re-dispatch, {$backfilledCount} would backfill state only, {$skipped} healthy.");
            $this->comment('Dry-run. Re-run with --apply to execute. Backfill only sets state on tenants that already have a working instance — it does NOT re-provision them.');
        }

        return self::SUCCESS;
    }
}
