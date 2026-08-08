<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\LegacySource;
use App\Services\Inventory\MisfiledStock;
use App\Services\Inventory\StockLedger;
use Illuminate\Console\Command;

/**
 * v4.65.6 — clear a location the shop does not use.
 *
 * Separate from `stock:reconcile` on purpose. That command writes nothing, ever — three contracts
 * hold it to that — and the reason it can be run by anyone at any time on production is precisely
 * that it cannot change a number. The moment a check can also fix things, running it stops being
 * free and people stop running it.
 *
 * DRY RUN by default. `--apply` is required to delete anything, same as `purchase:relink`.
 */
class StockClearMisfiled extends Command
{
    private const VERSION = 'v4.65.6';

    protected $signature = 'stock:clear-misfiled
        {tenant : Tenant id}
        {--location= : Which location to clear (default: every location with no legacy figure)}
        {--tolerance=0 : Per-product drift tolerated at home before the guard refuses}
        {--apply : Actually delete. Without this nothing is written.}';

    protected $description = 'Remove stock movements filed at a location this shop does not use (guarded, dry-run by default).';

    public function handle(): int
    {
        $t = Tenant::where('id', (int) $this->argument('tenant'))->first();
        if (! $t) {
            $this->error('No such tenant.');
            return self::FAILURE;
        }

        $home    = StockLedger::home($t);
        $tol     = max(0.0, (float) $this->option('tolerance'));
        $service = new MisfiledStock();
        $apply   = (bool) $this->option('apply');

        $this->line('<fg=gray>stock:clear-misfiled · ' . self::VERSION . ($apply ? '' : ' · DRY RUN') . '</>');
        $this->line("<options=bold>Tenant #{$t->id} — " . ($t->name ?? '') . "</>");
        $this->line("  <fg=gray>home={$home}</>");

        $found = $service->locations((int) $t->id, $home);
        if ($only = $this->option('location')) {
            $found = array_intersect_key($found, [strtolower(trim((string) $only)) => true]);
        }

        if (! $found) {
            $this->newLine();
            $this->info('Nothing misfiled. Every movement is at a location this shop uses.');
            return self::SUCCESS;
        }

        // ── the guard, evaluated once and shown before anything is proposed ────────────────────
        $parity = $service->homeParity((int) $t->id, $home, $tol);
        $this->newLine();
        if ($parity['ok']) {
            $this->line(sprintf('  <fg=green>✓</> home is in parity — all %d products match their product screen, so '
                . 'nothing outside %s contributes to a number this shop reads.', $parity['products'], $home));
        } else {
            $this->error(sprintf('  ✗ home is NOT in parity — %d of %d products disagree with their product screen.',
                $parity['drifting'], $parity['products']));
            $this->line('  <fg=yellow>Refusing. Some product may be depending on a movement filed in the wrong place, and '
                . "\n  clearing would be the thing that broke it. Run `stock:reconcile {$t->id}` and fix home first.</>");
            return self::FAILURE;
        }

        $total = 0;
        foreach ($found as $loc => $stat) {
            $this->newLine();
            $this->line(sprintf('  <options=bold>%s</> — %d movement(s), %s unit(s) net', $loc, $stat['rows'], $this->num($stat['units'])));

            $rows = $service->wroteThese((int) $t->id, $loc);
            $this->table(
                ['Written by', 'Movement', 'Hand', 'Rows', 'Units', 'First', 'Last'],
                array_map(fn ($w) => [$w['ref_type'], $w['type'], $w['created_by'],
                    $w['n'], $w['units'], $w['first_at'], $w['last_at']], $rows)
            );

            if (! LegacySource::mayClear($home, $loc, true)) {
                $this->warn("  Refusing {$loc}: it is not a location without a legacy figure.");
                continue;
            }

            if (! $apply) {
                $this->line("  <fg=yellow>Would delete {$stat['rows']} movement(s) at {$loc}. Nothing written — add --apply.</>");
                $total += $stat['rows'];
                continue;
            }

            $n = $service->clear((int) $t->id, $home, $loc, $tol);
            if ($n < 0) {
                $this->error("  Guard refused {$loc}. Nothing was deleted.");
                continue;
            }
            $this->line("  <fg=green>Deleted {$n} movement(s) at {$loc}.</>");
            $total += $n;
        }

        // ── what this does and does not throw away, said before it is done ─────────────────────
        $this->newLine();
        $this->line('<fg=gray>What is lost: the ledger\'s MIRROR of these events. The source records are untouched —</>');
        $this->line('<fg=gray>the products still exist, the orders still exist, and every net effect is already inside</>');
        $this->line("<fg=gray>the opening balances at {$home}, which were seeded from products.stock after they happened.</>");

        $this->newLine();
        if (! $apply) {
            $this->warn("DRY RUN — {$total} movement(s) would be deleted. Re-run with --apply to do it.");
            return self::SUCCESS;
        }
        $this->info("Removed {$total} movement(s). Now run:  php artisan stock:reconcile {$t->id}");
        return self::SUCCESS;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
