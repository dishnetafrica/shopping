<?php

namespace App\Console\Commands;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Registry\CapabilityRegistry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * v3:staff {tenant} {--off} {--dry} — Phase C rollout switch.
 *
 * Controls whether purchaser/field roles land on /v3 (tenant setting v3_all_roles,
 * default ON after this deploy). Prints, per staff role, exactly which v3 workspaces
 * that role can open — QA in the same breath as the flip, same pattern as nav:v2.
 * --off restores the old tap-tile home instantly (flag read live; no deploy).
 */
class V3StaffCommand extends Command
{
    protected $signature   = 'v3:staff {tenant : Tenant id} {--off : Send purchaser/field back to the classic app home} {--dry : Preview only}';
    protected $description = 'Enable/disable v3 landing for purchaser & field roles, with a per-role workspace preview';

    public function handle(CapabilityRegistry $registry): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('tenant not found');
            return self::FAILURE;
        }

        $roles = User::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereIn('role', ['purchaser', 'field'])->pluck('role')->unique()->values();
        if ($roles->isEmpty()) {
            $this->warn('tenant has no purchaser/field users — flag still applies to future staff');
        }

        foreach ($roles as $role) {
            $u = User::withoutGlobalScopes()->where('tenant_id', $t->id)->where('role', $role)->first();
            $ctx = CapabilityContext::fromUser($u);
            $keys = [];
            foreach ($registry->all() as $m) {
                if ($ctx->can($m->permission)) $keys[] = $m->key;
            }
            sort($keys);
            $this->line(sprintf('  %-10s %2d workspaces — %s', $role, count($keys), implode(', ', $keys)));
        }

        if ($this->option('dry')) {
            $this->info('dry run — v3_all_roles currently '.(((bool) $t->setting('v3_all_roles', true)) ? 'ON' : 'OFF'));
            return self::SUCCESS;
        }

        $on = ! $this->option('off');
        $t->putSetting('v3_all_roles', $on);
        $this->info('v3_all_roles for tenant '.$t->id.' => '.($on ? 'ON (staff land on /v3)' : 'OFF (staff land on /panel/app)')
            .' — takes effect on next page load; rollback: v3:staff '.$t->id.' --off');

        return self::SUCCESS;
    }
}
