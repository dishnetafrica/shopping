<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OrderCost;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * v4.64.3 — attach historical purchase lines to the products they were always for.
 *
 * A purchase line records `product_id` only when the browser could map the typed item to a
 * catalogue entry exactly. Everything else recorded the quantity with no product attached — which
 * was harmless while purchases only meant money, and stops being harmless the moment a purchase
 * moves stock, because those goods never reach the warehouse count and nothing says so.
 *
 * This finds them and links what it safely can.
 *
 * DRY RUN BY DEFAULT. It prints what it would do and changes nothing until `--apply`.
 *
 * ── what "safely" means ────────────────────────────────────────────────────────────────────────
 *
 * An EXACT name match, case and whitespace insensitive, and only when exactly ONE product matches.
 * Nothing fuzzy. The temptation here is obvious — "Golden Foil 45cm" against "Golden Foil 45CM
 * (Carton of 6)" is surely the same thing — and it is exactly how a hundred cartons of foil end up
 * counted against the wrong product, which is worse than not counting them at all. What cannot be
 * matched is LISTED, so a person can rename the product or fix the line and run it again.
 *
 * It does NOT post stock. Linking a two-month-old purchase would receive goods that were sold weeks
 * ago. This only repairs the link so that the reconciliation can see it and so that any FUTURE edit
 * of that line behaves correctly.
 */
class PurchaseRelinkCommand extends Command
{
    protected $signature = 'purchase:relink
        {tenant? : Tenant id (omit for all)}
        {--apply : Actually write the links. Without this it only reports.}';

    protected $description = 'Link purchase lines to products by exact name. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only  = (int) $this->argument('tenant');

        $tenants = Tenant::query()->when($only > 0, fn ($q) => $q->whereKey($only))->get();
        $totLinked = 0; $totStuck = 0;

        foreach ($tenants as $t) {
            $rows = OrderCost::withoutGlobalScopes()
                ->where('tenant_id', $t->id)->where('stage', 'purchase')
                ->whereNull('product_id')->whereNotNull('qty')->where('qty', '>', 0)
                ->orderBy('id')->get(['id', 'note', 'qty', 'status', 'created_at']);

            if ($rows->isEmpty()) {
                continue;
            }

            // The catalogue, keyed by normalised name. A name held by two products is deliberately
            // dropped from the map: ambiguous is not the same as unmatched, and guessing between
            // them is the one outcome worse than leaving the line alone.
            $byName = []; $dupes = [];
            Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->get(['id', 'name'])
                ->each(function ($p) use (&$byName, &$dupes) {
                    $k = self::key((string) $p->name);
                    if ($k === '') { return; }
                    if (isset($byName[$k])) { $dupes[$k] = true; }
                    $byName[$k] = (int) $p->id;
                });
            foreach (array_keys($dupes) as $k) {
                unset($byName[$k]);
            }

            $linked = []; $stuck = [];
            foreach ($rows as $r) {
                $k = self::key((string) $r->note);
                $pid = $k !== '' ? ($byName[$k] ?? null) : null;
                if ($pid) {
                    $linked[] = [$r->id, (string) $r->note, (float) $r->qty, $pid];
                    if ($apply) {
                        OrderCost::withoutGlobalScopes()->where('id', $r->id)->update(['product_id' => $pid]);
                    }
                } else {
                    $stuck[] = [$r->id, (string) $r->note ?: '(no item name)', (float) $r->qty,
                                optional($r->created_at)->format('d M Y'), (string) $r->status];
                }
            }

            $this->line('');
            $this->info($t->name . ' (tenant ' . $t->id . ')');
            $this->line('  ' . count($rows) . ' purchase line(s) with a quantity and no product link');

            if ($linked) {
                $this->line('  ' . ($apply ? 'LINKED' : 'would link') . ' ' . count($linked) . ':');
                $this->table(['Line', 'Item', 'Qty', '→ product'], array_slice($linked, 0, 40));
            }
            if ($stuck) {
                $this->warn('  ' . count($stuck) . ' cannot be matched — rename the product to match, '
                    . 'or edit the purchase line, then run this again:');
                $this->table(['Line', 'Item as recorded', 'Qty', 'Date', 'Status'], array_slice($stuck, 0, 40));
            }

            $totLinked += count($linked); $totStuck += count($stuck);
        }

        $this->line('');
        if (! $apply) {
            $this->comment('DRY RUN — nothing was changed. Re-run with --apply to write '
                . $totLinked . ' link(s).');
        } else {
            $this->info($totLinked . ' line(s) linked. ' . $totStuck . ' still unmatched.');
        }
        $this->comment('No stock was moved either way: linking an old purchase must not receive goods '
            . 'that were sold weeks ago.');

        return self::SUCCESS;
    }

    /** Case and whitespace insensitive. Nothing else — see the class docblock. */
    private static function key(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
