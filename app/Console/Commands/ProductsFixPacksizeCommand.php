<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * products:fix-packsize — the weight-in-pack_size import artifact (2026-07-19).
 *
 * Evidence (tenant 17): "Bournvita 500gm | pack 500", "Roohafza 750ml | pack 750",
 * "Maggi 960gm | pack 960" — the catalogue import absorbed the WEIGHT/VOLUME number
 * into pack_size. Those are single retail units: pack_size must be 1.
 *
 * SURGICAL RULE: only fix rows where pack_size > 1 AND the product's own name
 * contains that exact number immediately followed by a weight/volume unit
 * (gm, g, kg, ml, l, ltr, litre). Real piece-packs ("Paper Cups 50pcs") never
 * match — "pcs" is not a weight unit — so they are untouchable by design.
 */
class ProductsFixPacksizeCommand extends Command
{
    protected $signature   = 'products:fix-packsize {tenant} {--dry : List what would change, write nothing}';
    protected $description = 'Repair pack_size values that absorbed the product weight/volume from the name';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) { $this->error('tenant not found'); return self::FAILURE; }
        $dry = (bool) $this->option('dry');

        $rows = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('pack_size', '>', 1)->get();

        $fixed = 0;
        foreach ($rows as $p) {
            $n = (int) $p->pack_size;
            // the number must appear in the NAME glued to a weight/volume unit
            if (! preg_match('/\b'.$n.'\s?(?:gm|g|kg|ml|ltr|litre|l)\b/iu', (string) $p->name)) continue;

            $this->line(sprintf('  %-42s pack %4d -> 1   ($%s)',
                mb_substr((string) $p->name, 0, 42), $n, number_format((float) $p->price, 2)));
            if (! $dry) {
                $p->pack_size = 1;
                $p->save();
            }
            $fixed++;
        }

        $this->line('');
        $this->info(($dry ? '[DRY] would fix ' : 'Fixed ').$fixed.' of '.$rows->count().' pack-sized product(s).');
        if ($dry && $fixed > 0) $this->comment('Read the list — every line should be a weight, not a real pack — then rerun without --dry.');
        if (! $dry && $fixed > 0) $this->comment('Storefront cache clears within 5 minutes (catalogue cache TTL); hard-refresh after that.');

        return self::SUCCESS;
    }
}
