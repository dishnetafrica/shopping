<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OrderCost;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\PurchaseNameMatch;
use App\Services\Inventory\PurchaseResolver;
use Illuminate\Console\Command;

/**
 * v4.65 — attach purchase lines to the products they were always for.
 *
 * A purchase line moves stock only when it carries a product id, and it only carries one when the
 * typed item matched a product exactly. On a real shop that almost never holds: the catalogue says
 * `ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)` and the supplier's invoice — and so
 * the person typing it — says `Golden Foil 45cm`.
 *
 * Both are correct, and no amount of care at the keyboard will make them the same string, because
 * the supplier will write it their way again next month. So this does two jobs:
 *
 *   RELINK   link every line whose name matches a product, by name or by an alias.
 *   ALIAS    teach a product a name it answers to — `--alias="Golden Foil 45cm=ALUMINIUM FOIL…"` —
 *            which links every line using that wording, past and future, in one go.
 *
 * DRY RUN BY DEFAULT. Nothing is written until `--apply`.
 *
 * It moves NO stock, ever. Linking a two-month-old purchase would receive goods that were sold
 * weeks ago. This repairs the link so the reconciliation can see it and so future edits behave.
 */
class PurchaseRelinkCommand extends Command
{
    protected $signature = 'purchase:relink
        {tenant? : Tenant id (omit for all)}
        {--alias=* : "purchase wording=Exact Product Name" — teach a product another name. Repeatable.}
        {--apply : Actually write. Without this it only reports.}';

    protected $description = 'Link purchase lines to products by name or alias. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only  = (int) $this->argument('tenant');

        foreach (Tenant::query()->when($only > 0, fn ($q) => $q->whereKey($only))->get() as $t) {
            $this->line('');
            $this->info($t->name . ' (tenant ' . $t->id . ')');

            if (! $this->applyAliases($t, (array) $this->option('alias'), $apply)) {
                return self::FAILURE;
            }

            // Rebuilt AFTER the aliases, so a line links in the same run that taught the name.
            $resolver = PurchaseResolver::forTenant($t);

            // Voided lines are excluded: they will never move stock whatever their link says, and
            // listing them only buries the rows a person can actually do something about.
            $rows = OrderCost::withoutGlobalScopes()
                ->where('tenant_id', $t->id)->where('stage', 'purchase')
                ->whereNull('product_id')->whereNotNull('qty')->where('qty', '>', 0)
                ->whereNotIn('status', ['void', 'rejected'])->whereNull('voided_at')
                ->orderBy('id')->get(['id', 'note', 'qty', 'created_at']);

            if ($rows->isEmpty()) {
                $this->line('  Nothing unlinked. Every purchase line will move stock.');
                continue;
            }

            $linked = []; $stuck = [];
            foreach ($rows as $r) {
                $pid = $resolver->find((string) $r->note);
                if ($pid) {
                    $linked[] = [$r->id, (string) $r->note, (float) $r->qty, $pid];
                    if ($apply) {
                        OrderCost::withoutGlobalScopes()->where('id', $r->id)->update(['product_id' => $pid]);
                    }
                } else {
                    $stuck[] = $r;
                }
            }

            $this->line('  ' . $rows->count() . ' unlinked line(s) with a quantity');
            if ($linked) {
                $this->info('  ' . ($apply ? 'LINKED ' : 'would link ') . count($linked)
                    . ' line(s) — ' . number_format(array_sum(array_column($linked, 2))) . ' units');
                $this->table(['Line', 'Item', 'Qty', '→ product'], array_slice($linked, 0, 40));
            }
            if ($stuck) {
                $this->suggest($t, $stuck, $resolver);
            }
        }

        $this->line('');
        if (! $apply) {
            $this->comment('DRY RUN — nothing changed. Add --apply to write.');
        }
        $this->comment('No stock moved either way: linking an old purchase must not receive goods '
            . 'that were sold weeks ago.');

        return self::SUCCESS;
    }

    /** `--alias="Golden Foil 45cm=ALUMINIUM FOIL 45CM GOLDEN (…)"` */
    private function applyAliases(Tenant $t, array $aliases, bool $apply): bool
    {
        foreach (array_filter($aliases) as $spec) {
            $bits = explode('=', $spec, 2);
            if (count($bits) !== 2 || trim($bits[0]) === '' || trim($bits[1]) === '') {
                $this->error('  Bad --alias. Use  --alias="purchase wording=Exact Product Name"');

                return false;
            }
            [$wording, $productName] = [trim($bits[0]), trim($bits[1])];

            $matches = Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->get(['id', 'name', 'keywords'])
                ->filter(fn ($p) => PurchaseNameMatch::key($p->name) === PurchaseNameMatch::key($productName));

            if ($matches->count() !== 1) {
                $this->error('  ' . ($matches->isEmpty() ? 'No product named' : 'More than one product named')
                    . ' "' . $productName . '" — copy the name exactly as it appears in Products.');

                return false;
            }
            $p = $matches->first();

            $before = (string) $p->keywords;
            $after  = PurchaseNameMatch::addAlias($before, $wording);
            if ($after === $before) {
                $this->line('  · "' . $wording . '" — already an alias of ' . $p->name);
                continue;
            }
            $this->info('  ' . ($apply ? 'TAUGHT' : 'would teach') . ' ' . $p->name
                . '  ←  "' . $wording . '"');
            if ($apply) {
                Product::withoutGlobalScopes()->where('id', $p->id)->update(['keywords' => $after]);
            }
        }

        return true;
    }

    /**
     * For everything still unmatched: the closest products, and the exact command to fix it.
     *
     * Grouped by wording, so ten lines saying "Golden Cling Film 45cm" are one decision worth 190
     * cartons rather than ten rows worth nineteen each.
     */
    private function suggest(Tenant $t, array $stuck, PurchaseResolver $resolver): void
    {
        $groups = [];
        foreach ($stuck as $r) {
            $k = PurchaseNameMatch::key((string) $r->note) ?: '(no item name)';
            $groups[$k] ??= ['name' => (string) $r->note ?: '(no item name)', 'qty' => 0.0, 'lines' => 0];
            $groups[$k]['qty'] += (float) $r->qty;
            $groups[$k]['lines']++;
        }
        uasort($groups, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        $products = Product::withoutGlobalScopes()->where('tenant_id', $t->id)->get(['id', 'name']);

        $this->warn('  ' . count($groups) . ' wording(s) match no product — '
            . number_format(array_sum(array_column($groups, 'qty'))) . ' units:');

        $table = [];
        foreach ($groups as $g) {
            $best = $products->map(fn ($p) => ['n' => (string) $p->name,
                                               's' => PurchaseNameMatch::similarity($g['name'], (string) $p->name)])
                ->sortByDesc('s')->first();
            $note = $resolver->isAmbiguous($g['name']) ? 'AMBIGUOUS — two products claim this name' : '';
            $table[] = [
                $g['name'], (int) $g['qty'], $g['lines'],
                $note ?: (($best && $best['s'] >= 25) ? $best['n'] . '  (' . $best['s'] . '%)' : '— nothing close'),
            ];
        }
        $this->table(['Item as recorded', 'Qty', 'Lines', 'Closest product'], array_slice($table, 0, 40));

        $top = reset($groups);
        if ($top) {
            $best = $products->map(fn ($p) => ['n' => (string) $p->name,
                                               's' => PurchaseNameMatch::similarity($top['name'], (string) $p->name)])
                ->sortByDesc('s')->first();
            $this->line('');
            $this->comment('  If the closest product is the right one, teach it that name and every '
                . 'line using that wording links at once:');
            $this->line('    php artisan purchase:relink ' . $t->id
                . ' --alias="' . $top['name'] . '=' . ($best['n'] ?? 'Exact Product Name') . '" --apply');
            $this->line('  Repeat --alias as many times as you need in one command.');
            $this->comment('  Anything that is not goods — fuel, freight, a fee — should stay unlinked.');
        }
    }
}
