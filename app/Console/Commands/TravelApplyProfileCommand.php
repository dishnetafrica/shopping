<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Apply a DRAFT travel-agency profile to an existing tenant (issue #145). For staging and onboarding.
 *
 * It never creates a tenant, never sets a staff alert number, never sets a price or a term, and
 * refuses to run on production. A setting the tenant already has is left alone unless --overwrite.
 */
class TravelApplyProfileCommand extends Command
{
    protected $signature = 'travel:apply-profile {profile : e.g. lazaros} {--tenant= : id of an EXISTING tenant} {--dry-run : show what would change, change nothing} {--overwrite : replace values the tenant already has}';

    protected $description = 'Apply a draft travel-agency profile to an existing tenant (never on production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refused: draft profiles are not applied on production. Onboard the customer with confirmed details instead.');

            return self::FAILURE;
        }
        $name = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $this->argument('profile')));
        $file = app_path('Services/Travel/Profiles/' . $name . '.php');
        if ($name === '' || ! is_file($file)) {
            $this->error('Unknown profile "' . $this->argument('profile') . '".');

            return self::FAILURE;
        }
        $tenant = Tenant::find((int) $this->option('tenant'));
        if (! $tenant) {
            $this->error('Give the id of an existing tenant with --tenant=. This command does not create tenants.');

            return self::FAILURE;
        }

        $profile  = require $file;
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $changes  = [];
        foreach ((array) ($profile['settings'] ?? []) as $key => $value) {
            $has = array_key_exists($key, $settings) && trim((string) (is_scalar($settings[$key]) ? $settings[$key] : 'x')) !== '';
            if ($has && ! $this->option('overwrite')) {
                $this->line("  keep   {$key} (already set)");
                continue;
            }
            $changes[$key] = $value;
            $this->line("  set    {$key} = " . mb_substr((string) $value, 0, 90));
        }

        $this->newLine();
        $this->warn('DRAFT — taken from ' . ($profile['source'] ?? 'the public website') . '. To confirm with the customer:');
        foreach ((array) ($profile['to_confirm_with_the_customer'] ?? []) as $item) {
            $this->line('  - ' . $item);
        }

        if ($this->option('dry-run') || ! $changes) {
            $this->info($changes ? 'Dry run: nothing was changed.' : 'Nothing to change.');

            return self::SUCCESS;
        }
        $tenant->settings = array_merge($settings, $changes);
        $tenant->save();
        $this->info('Applied ' . count($changes) . ' setting(s) to tenant ' . $tenant->id . ' (' . $tenant->name . ').');

        return self::SUCCESS;
    }
}
