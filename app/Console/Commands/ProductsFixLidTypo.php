<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * v4.37 — repair "lead" written where "lid" is meant, on containers and drinkware.
 *
 * Family Shoppers' catalogue advertised "5 Star Disposable Glasses With lead 300ML" and four
 * siblings. Lead is a toxic heavy metal and these are food-contact drinking cups; the word also
 * reached the keywords and the auto-generated description, so it was on the storefront, in the
 * search index and in whatever the bot said about them.
 *
 * The word is only replaced on rows that are clearly containers — a stationery shop sells pencil
 * lead, and that is a real word about a real product. Run with --dry-run first; it prints every
 * change it would make and writes nothing.
 */
class ProductsFixLidTypo extends Command
{
    protected $signature = 'products:fix-lid-typo
        {--tenant= : limit to one tenant id}
        {--dry-run : list the changes and write nothing}';

    protected $description = 'Fix "lead" written where "lid" is meant on containers and drinkware';

    /** Only these say "this is a thing with a lid on it", never a pencil. */
    private const CONTAINER = '/\b(glass|glasses|cup|cups|tumbler|container|bowl|box|jar|bottle|tub|plate|foil|takeaway|take away|disposable)\b/i';

    /** "with lead", "without lead", "with out lead", "c/lead" — the shapes seen in the wild. */
    private const TYPO = '/\b(with(?:\s*out)?)\s+lead\b/i';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = Product::query()->withoutGlobalScopes();
        if ($t = $this->option('tenant')) {
            $q->where('tenant_id', (int) $t);
        }

        $hasDesc = Schema::hasColumn('products', 'description');
        $changed = 0; $skipped = 0;

        foreach ($q->cursor() as $p) {
            $name = (string) $p->name;
            $blob = $name . ' ' . (string) $p->category . ' ' . (string) $p->keywords;

            // Nothing to do, or not a container — leave it alone either way.
            if (! preg_match('/\blead\b/i', $blob . ' ' . (string) ($hasDesc ? $p->description : ''))) {
                continue;
            }
            if (! preg_match(self::CONTAINER, $blob)) {
                $this->warn("  skipped (not a container, 'lead' may be correct): {$name}");
                $skipped++;
                continue;
            }

            $fields = ['name' => $name, 'keywords' => (string) $p->keywords];
            if ($hasDesc) {
                $fields['description'] = (string) $p->description;
            }

            $new = [];
            foreach ($fields as $k => $v) {
                if ($v === '') {
                    continue;
                }
                // "with lead" / "without lead" first, so the joining word is kept and spaced;
                // then any bare "lead" left over in the same string.
                $out = preg_replace(self::TYPO, '$1 lid', $v);
                $out = preg_replace('/\blead\b/i', 'lid', (string) $out);
                // "With Out lid" is the same product as "Without lid" — say it once, one way.
                $out = preg_replace('/\bwith\s+out\b/i', 'Without', (string) $out);
                if ($out !== $v) {
                    $new[$k] = $out;
                }
            }
            if (! $new) {
                continue;
            }

            $this->line("  #{$p->id}  {$name}");
            foreach ($new as $k => $v) {
                $this->line("      {$k}: " . mb_strimwidth($v, 0, 96, '…'));
            }
            if (! $dry) {
                foreach ($new as $k => $v) {
                    $p->{$k} = $v;
                }
                $p->save();
            }
            $changed++;
        }

        $this->newLine();
        $this->info($dry
            ? "{$changed} product(s) would be corrected" . ($skipped ? ", {$skipped} left alone" : '') . '. Nothing was written — drop --dry-run to apply.'
            : "{$changed} product(s) corrected" . ($skipped ? ", {$skipped} left alone" : '') . '.');

        return self::SUCCESS;
    }
}
