<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Bot\Opportunity\OpportunityTypes;
use Illuminate\Console\Command;

/**
 * Switch the Business Opportunity Engine on/off for a tenant and choose which
 * opportunity types it qualifies. Saves into the tenant settings JSON.
 *
 *   php artisan opportunity:enable 12 --types=distributor,wholesale,export
 *   php artisan opportunity:enable fs                       (all types)
 *   php artisan opportunity:enable 12 --off                 (disable)
 */
class OpportunityEnableCommand extends Command
{
    protected $signature = 'opportunity:enable {tenant : tenant id or slug}
        {--types= : comma-separated opportunity types (omit = all)}
        {--off : disable the engine for this tenant}';

    protected $description = 'Enable/disable the Business Opportunity Engine for a tenant.';

    public function handle(): int
    {
        $key    = (string) $this->argument('tenant');
        $tenant = ctype_digit($key)
            ? Tenant::find((int) $key)
            : Tenant::where('slug', $key)->first();

        if (! $tenant) {
            $this->error("Tenant '{$key}' not found.");
            return self::FAILURE;
        }

        if ($this->option('off')) {
            $tenant->putSetting('opportunity_mode', false);
            $this->info("Opportunity engine DISABLED for {$tenant->name} (#{$tenant->id}).");
            return self::SUCCESS;
        }

        $types = [];
        if ($raw = (string) $this->option('types')) {
            $req   = array_filter(array_map('trim', explode(',', strtolower($raw))));
            $types = OpportunityTypes::enabledFor($req);
            $bad   = array_diff($req, $types);
            if ($bad) {
                $this->warn('Ignored unknown types: ' . implode(', ', $bad));
            }
        }

        $tenant->putSetting('opportunity_mode', true);
        $tenant->putSetting('opportunity_types', $types);  // [] = all types

        $shown = $types ?: ['(all)'];
        $this->info("Opportunity engine ENABLED for {$tenant->name} (#{$tenant->id}).");
        $this->line('Types: ' . implode(', ', $shown));
        return self::SUCCESS;
    }
}
