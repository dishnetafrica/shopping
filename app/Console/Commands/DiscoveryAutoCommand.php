<?php

namespace App\Console\Commands;

use App\Jobs\AutoLearnTenant;
use App\Models\BusinessDiscovery;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Learn (or re-learn) every tenant automatically — so you never run discovery per tenant by hand.
 *
 *   php artisan discovery:auto                # queue a full-history re-learn for all tenants with chats
 *   php artisan discovery:auto --new-only     # only tenants that have NO discovery yet (auto-onboard)
 *   php artisan discovery:auto --sync         # run inline instead of queuing (handy for testing)
 *   php artisan discovery:auto --tenant=8     # just one tenant
 *
 * The scheduler runs `--new-only` daily (new tenants onboard within a day) and a full refresh weekly.
 */
class DiscoveryAutoCommand extends Command
{
    protected $signature = 'discovery:auto {--new-only : only tenants with no discovery yet} {--sync : run inline} {--tenant= : limit to one tenant id}';
    protected $description = 'Auto-learn every tenant from full message history (Discovery + readiness + team)';

    public function handle(): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::query()->get();

        $queued = 0; $skippedNoMsgs = 0; $skippedHasDiscovery = 0;

        foreach ($tenants as $t) {
            // Count without the tenant global scope so we don't depend on context here.
            $msgs = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->count();
            if ($msgs === 0) { $skippedNoMsgs++; continue; }

            if ($this->option('new-only')) {
                $has = BusinessDiscovery::withoutGlobalScopes()->where('tenant_id', $t->id)->exists();
                if ($has) { $skippedHasDiscovery++; continue; }
            }

            if ($this->option('sync')) {
                AutoLearnTenant::dispatchSync($t->id);
                $this->info("Learned tenant {$t->id} ({$t->name}) from {$msgs} messages.");
            } else {
                AutoLearnTenant::dispatch($t->id)->delay(now()->addSeconds($queued * 20));
                $this->info("Queued learning for tenant {$t->id} ({$t->name}) — {$msgs} messages.");
            }
            $queued++;
        }

        $this->newLine();
        $this->line("Done: {$queued} tenant(s) " . ($this->option('sync') ? 'learned' : 'queued')
            . ", {$skippedNoMsgs} skipped (no messages)"
            . ($this->option('new-only') ? ", {$skippedHasDiscovery} skipped (already learned)" : '') . '.');

        return self::SUCCESS;
    }
}
