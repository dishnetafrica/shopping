<?php

namespace App\Console\Commands;

use App\Http\Middleware\ClassicPanelRedirects;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * v3:retire {tenant} {--off} {--dry} — Phase D switch: classic panel pages 302 to v3.
 * Prints the full redirect map + the honest tail (classic screens with no v3 home)
 * before flipping, same QA-in-the-same-breath pattern as nav:v2 and v3:staff.
 */
class V3RetireCommand extends Command
{
    protected $signature   = 'v3:retire {tenant : Tenant id} {--off : Turn classic redirects off} {--dry : Preview only}';
    protected $description = 'Enable/disable 302 redirects from classic panel pages to their v3 workspaces';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('tenant not found');
            return self::FAILURE;
        }

        $this->info(count(ClassicPanelRedirects::MAP).' classic pages will redirect:');
        foreach (ClassicPanelRedirects::MAP as $from => $key) {
            $this->line(sprintf('  %-26s → /v3#/%s', $from, $key));
        }
        $this->warn('Unmapped (stay classic — the honest tail): /panel/audit, /panel/partners, '
            .'/panel/billing, /panel/diagnostics, /panel/onboard, '
            .'/panel/consignment (rich tool, no v3 port yet), /panel/scheduled (scheduled ORDERS, not campaigns)');
        $this->line('Escape hatch on every mapped page: ?classic=1 (kept one quarter).');

        if ($this->option('dry')) {
            $this->info('dry run — v3_redirects currently '.(((bool) $t->setting('v3_redirects', false)) ? 'ON' : 'OFF'));
            return self::SUCCESS;
        }

        $on = ! $this->option('off');
        $t->putSetting('v3_redirects', $on);
        $this->info('v3_redirects for tenant '.$t->id.' => '.($on ? 'ON — classic pages now 302 to /v3' : 'OFF — classic pages render again')
            .' — live immediately; rollback: v3:retire '.$t->id.' --off');

        return self::SUCCESS;
    }
}
