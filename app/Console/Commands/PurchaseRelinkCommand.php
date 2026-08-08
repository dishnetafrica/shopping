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
        {--line=* : "lineId=Exact Product Name" — link ONE line, teaching nothing. Repeatable.}
        {--apply : Actually write. Without this it only reports.}';

    protected $description = 'Link purchase lines to products by name or alias. Dry run unless --apply.';

    /**
     * Below this, a suggestion is shown in the table but never offered as a command to run.
     *
     * A printed command is a recommendation whatever the table beside it says — somebody will paste
     * it — so the bar for printing one is higher than the bar for listing a candidate.
     */
    private const CONFIDENT = 60;

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
            if (! $this->applyLines($t, (array) $this->option('line'), $apply)) {
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
     * `--line="310=ALUMINIUM FOIL 45CM GOLDEN (…)"` — one line, no alias written.
     *
     * The right tool for a wording that will never occur again. `Aluminium foil (AVION Vch 243
     * 17-Jul-26)` carries a voucher number and a date, so it is unique by construction: teaching it
     * as an alias would put a dead string into the keywords field for ever, where it can never match
     * another purchase and can only make the bot's product search worse.
     */
    private function applyLines(Tenant $t, array $specs, bool $apply): bool
    {
        foreach (array_filter($specs) as $spec) {
            $bits = explode('=', $spec, 2);
            if (count($bits) !== 2 || ! ctype_digit(trim($bits[0])) || trim($bits[1]) === '') {
                $this->error('  Bad --line. Use  --line="310=Exact Product Name"');

                return false;
            }
            [$lineId, $productName] = [(int) trim($bits[0]), trim($bits[1])];

            $line = OrderCost::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('stage', 'purchase')->find($lineId);
            if (! $line) {
                $this->error('  No purchase line ' . $lineId . ' for this tenant.');

                return false;
            }

            $matches = Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->get(['id', 'name'])
                ->filter(fn ($p) => PurchaseNameMatch::key($p->name) === PurchaseNameMatch::key($productName));
            if ($matches->count() !== 1) {
                $this->error('  ' . ($matches->isEmpty() ? 'No product named' : 'More than one product named')
                    . ' "' . $productName . '" — copy the name exactly as it appears in Products.');

                return false;
            }

            $this->info('  ' . ($apply ? 'LINKED' : 'would link') . ' line ' . $lineId
                . ' (' . $line->note . ', ' . (float) $line->qty . ')  →  ' . $matches->first()->name);
            if ($apply) {
                OrderCost::withoutGlobalScopes()->where('id', $lineId)
                    ->update(['product_id' => (int) $matches->first()->id]);
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
            $groups[$k] ??= ['name' => (string) $r->note ?: '(no item name)', 'qty' => 0.0, 'lines' => 0, 'ids' => []];
            $groups[$k]['qty'] += (float) $r->qty;
            $groups[$k]['lines']++;
            $groups[$k]['ids'][] = (int) $r->id;
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
                // The ids, because --line needs them and going to look them up is friction.
                implode(' ', array_slice($g['ids'], 0, 6)) . (count($g['ids']) > 6 ? ' …' : ''),
                $note ?: (($best && $best['s'] >= 25) ? $best['n'] . '  (' . $best['s'] . '%)' : '— nothing close'),
            ];
        }
        $this->table(['Item as recorded', 'Qty', 'Lines', 'Line ids', 'Closest product'], array_slice($table, 0, 40));

        // v4.65.2 — the example command must come from the best MATCH, not the biggest quantity.
        //
        // On the first real run this printed, ready to paste:
        //     --alias="Lids 500ml=Methylated Spirit 500ml"
        // because Lids was the largest remaining quantity — while being a 37% match to a completely
        // different product. A tool that prints a copy-pasteable command is making a recommendation
        // whatever its table says, and it must only do that when it is actually confident.
        $strong = null;
        foreach ($groups as $g) {
            $best = $products->map(fn ($p) => ['n' => (string) $p->name,
                                               's' => PurchaseNameMatch::similarity($g['name'], (string) $p->name)])
                ->sortByDesc('s')->first();
            if ($best && $best['s'] >= self::CONFIDENT && (! $strong || $best['s'] > $strong['s'])) {
                $strong = ['wording' => $g['name'], 'n' => $best['n'], 's' => $best['s']];
            }
        }

        $this->line('');
        if ($strong) {
            $this->comment('  The closest match left is ' . $strong['s'] . '%. If it is right, teach it '
                . 'that name and every line using that wording links at once:');
            $this->line('    php artisan purchase:relink ' . $t->id
                . ' --alias="' . $strong['wording'] . '=' . $strong['n'] . '" --apply');
        } else {
            $this->comment('  Nothing left is a confident match — every suggestion above is under '
                . self::CONFIDENT . '%, so read the table rather than pasting a command. Most of what '
                . 'reaches this point is either a product you have not created yet, or not goods at all.');
            $this->line('    php artisan purchase:relink ' . $t->id
                . ' --alias="<wording as recorded>=<Exact Product Name>" --apply');
        }
        $this->line('  Repeat --alias as many times as you need in one command.');
        $this->line('');
        $this->comment('  For a wording that will never occur again — one carrying a voucher number '
            . 'or a date — link the LINE instead, so no dead alias is left behind:');
        $this->line('    php artisan purchase:relink ' . $t->id
            . ' --line="<line id>=Exact Product Name" --apply');
        $this->comment('  Anything that is not goods — fuel, freight, a fee — should stay unlinked.');
    }
}
