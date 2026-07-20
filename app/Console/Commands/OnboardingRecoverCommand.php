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
        $skipped = 0;

        foreach ($tenants as $t) {
            $state = OnboardingState::current($t);
            $hasInstance = ! empty($t->whatsapp_instance);
            $needsWork = $state === OnboardingState::FAILED
                || (! OnboardingState::isProvisioned($t) && ! $hasInstance)
                || (! OnboardingState::isProvisioned($t) && $state === OnboardingState::PENDING);

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
            $this->info("Recovered {$dispatchedCount} tenant(s). Skipped {$skipped} (healthy or terminal).");
        } else {
            $this->info("{$needsWorkCount} tenant(s) would be re-dispatched. Skipped {$skipped}.");
            $this->comment('Dry-run. Re-run with --apply to execute.');
        }

        return self::SUCCESS;
    }
}
