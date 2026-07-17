<?php

namespace App\Console\Commands;

use App\Http\Controllers\Panel\NavController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * nav:v2 {tenant} {--off} {--dry} — the rollout switch for the grouped sidebar.
 *
 * There is deliberately no panel toggle for nav_v2 (it is a rollout flag, not a
 * user preference). This command flips it per tenant AND prints the pre-flight:
 * the config self-audit (NavController::menuIssues) plus the exact menu every
 * role on that tenant will see — so QA happens in the same breath as the flip.
 *
 *   php artisan nav:v2 1 --dry     # preview only
 *   php artisan nav:v2 1           # enable for tenant 1
 *   php artisan nav:v2 1 --off     # instant rollback (flag read live per request)
 */
class NavV2Command extends Command
{
    protected $signature   = 'nav:v2 {tenant : Tenant id} {--off : Disable instead of enable} {--dry : Preview menus without changing the flag}';
    protected $description = 'Enable/disable the config-driven grouped sidebar (nav_v2) for a tenant, with a per-role menu preview';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('tenant not found');
            return self::FAILURE;
        }

        // 1) Config self-audit — refuse to enable a menu that fails its own guard.
        $issues = NavController::menuIssues();
        if ($issues) {
            foreach ($issues as $i) $this->error('menu issue: '.$i);
            if (! $this->option('off')) {
                $this->error('Fix config/navigation.php before enabling nav_v2.');
                return self::FAILURE;
            }
        } else {
            $this->info('menu config: clean (no duplicate labels, no retired routes)');
        }

        // 2) Per-role preview with THIS tenant's real flags + gates.
        $flags = (array) ($t->setting('modules', []) ?: []);
        $settings = ['order_system_enabled' => (bool) $t->setting('order_system_enabled', false)];

        $roles = User::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->pluck('role')->unique()->filter()->values();
        foreach ($roles as $role) {
            $u = User::withoutGlobalScopes()->where('tenant_id', $t->id)->where('role', $role)->first();
            $menus = $u && method_exists($u, 'effectiveMenus') ? $u->effectiveMenus() : [];
            $groups = NavController::menuFor($menus, $flags, null, $settings);
            $n = array_sum(array_map(fn ($g) => count($g['items']), $groups));
            $line = collect($groups)->map(fn ($g) => $g['label'].'('.count($g['items']).')')->implode(' · ');
            $this->line(sprintf('  %-14s %2d items — %s', $role, $n, $line ?: '(EMPTY MENU — check role/gates)'));
        }

        if ($this->option('dry')) {
            $this->info('dry run — flag unchanged (currently '.(($t->setting('nav_v2', false)) ? 'ON' : 'OFF').')');
            return self::SUCCESS;
        }

        // 3) Flip.
        $on = ! $this->option('off');
        $t->putSetting('nav_v2', $on);
        $this->info('nav_v2 for tenant '.$t->id.' ('.$t->name.') => '.($on ? 'ON' : 'OFF')
            .'  — takes effect on next page load; rollback: nav:v2 '.$t->id.' --off');

        return self::SUCCESS;
    }
}
