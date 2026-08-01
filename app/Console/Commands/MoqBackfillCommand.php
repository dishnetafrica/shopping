<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Support\MoqPatterns;
use Illuminate\Console\Command;

/**
 * Copies minimum-order quantities that are written INSIDE product names ("… MIN 5 CARTON …") into
 * the products.moq column — the column is what MOQ enforcement (moq_mode=roundup in Settings →
 * WhatsApp & bot → Selling rules) actually reads; text in the name enforces nothing.
 *
 * Safe by design: dry-run unless --write; only fills products whose moq column is EMPTY (a
 * deliberately-set moq is never overwritten); prints every decision so the owner can eyeball the
 * list before writing. Idempotent — a second run finds nothing to do.
 *
 *   php artisan moq:backfill --tenant=17            # preview
 *   php artisan moq:backfill --tenant=17 --write    # apply
 */
class MoqBackfillCommand extends Command
{
    protected $signature = 'moq:backfill {--tenant= : Tenant id (required)} {--write : Apply changes (default: dry-run preview)}';

    protected $description = 'Fill the products.moq column from minimums written in product names (MIN 5 CARTON → moq=5). Dry-run unless --write.';

    public function handle(): int
    {
        $tid = (int) $this->option('tenant');
        if ($tid <= 0) {
            $this->error('Pass --tenant=<id> (e.g. --tenant=17).');

            return self::FAILURE;
        }
        $tenant = Tenant::find($tid);
        if (! $tenant) {
            $this->error("Tenant {$tid} not found.");

            return self::FAILURE;
        }
        $write = (bool) $this->option('write');

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tid)->orderBy('name')->get();

        $filled = 0;
        $already = 0;
        $none = 0;
        $rows = [];
        foreach ($products as $p) {
            $found = MoqPatterns::fromName((string) $p->name);
            if ($p->moq) {
                $already++;
                if ($found && (int) $found !== (int) $p->moq) {
                    // Never overwrite — but do surface the disagreement for a human decision.
                    $rows[] = ['KEPT', $p->name, "column={$p->moq}, name says {$found}"];
                }
                continue;
            }
            if (! $found) {
                $none++;
                continue;
            }
            $rows[] = [$write ? 'SET' : 'WOULD SET', $p->name, "moq={$found}"];
            if ($write) {
                $p->moq = $found;
                $p->save();
            }
            $filled++;
        }

        foreach ($rows as [$tag, $name, $note]) {
            $this->line(sprintf('%-10s %s  →  %s', $tag, mb_substr($name, 0, 70), $note));
        }
        $this->info(($write ? 'Filled ' : 'Would fill ') . $filled . ' product(s); ' . $already . ' already had a MOQ (untouched); ' . $none . ' state no minimum in the name.');
        if (! $write && $filled > 0) {
            $this->comment('Preview only — re-run with --write to apply.');
        }
        if ($write && $filled > 0) {
            $this->comment('Now switch enforcement on: Settings → WhatsApp & bot → Selling rules → "Enforced — round below-minimum requests up to the MOQ".');
        }

        return self::SUCCESS;
    }
}
