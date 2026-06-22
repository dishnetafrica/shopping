<?php

namespace App\Console\Commands;

use App\Jobs\ProgressiveDiscovery;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Kick off Phase-2 progressive learning (90/180/365-day widening) for a tenant, or all tenants.
 * Usage: php artisan discovery:progressive {tenant?} [--now]
 */
class ProgressiveDiscoveryCommand extends Command
{
    protected $signature = 'discovery:progressive {tenant? : Tenant id (omit for all)} {--now : Run inline instead of queued}';
    protected $description = 'Background-widen Business Discovery windows (90/180/365 days)';

    public function handle(): int
    {
        $tenants = $this->argument('tenant')
            ? Tenant::where('id', (int) $this->argument('tenant'))->get()
            : Tenant::query()->get();

        foreach ($tenants as $t) {
            if ($this->option('now')) {
                ProgressiveDiscovery::dispatchSync($t->id, 0);
                $this->info("Ran progressive discovery inline for tenant {$t->id}.");
            } else {
                ProgressiveDiscovery::dispatch($t->id, 0)->delay(now()->addMinutes(1));
                $this->info("Queued progressive discovery for tenant {$t->id}.");
            }
        }
        return self::SUCCESS;
    }
}
