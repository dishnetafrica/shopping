<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * products:price-sync — repair the price/base_price drift found 2026-07-18
 * (storefront + bot preferred a stale base_price and sold at OLD prices).
 * Shows every drifted product with old → new, then syncs base_price = price
 * and clears the storefront + bot catalogue caches so it's live immediately.
 */
class ProductPriceSyncCommand extends Command
{
    protected $signature   = 'products:price-sync {tenant : Tenant id} {--dry : Preview only}';
    protected $description = 'Sync base_price to the selling price where they drifted; clears catalogue caches';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('tenant not found');
            return self::FAILURE;
        }

        $drifted = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->where('price', '>', 0)
            ->whereNotNull('base_price')
            ->whereColumn('base_price', '!=', 'price')
            ->get(['id', 'name', 'price', 'base_price']);

        if ($drifted->isEmpty()) {
            $this->info('No drift — every product already sells at its panel price.');
            return self::SUCCESS;
        }

        $this->warn($drifted->count().' products were selling at a STALE price (storefront/bot showed base_price):');
        foreach ($drifted as $p) {
            $this->line(sprintf('  #%-6d %-52s %10.2f  →  %.2f',
                $p->id, mb_substr((string) $p->name, 0, 52), (float) $p->base_price, (float) $p->price));
        }

        if ($this->option('dry')) {
            $this->info('dry run — nothing changed. Run without --dry to sync.');
            return self::SUCCESS;
        }

        foreach ($drifted as $p) {
            $p->base_price = $p->price;   // the saving() hook would do this too — explicit is clearer here
            $p->save();
        }
        Cache::forget('catalogue:'.$t->id);
        Cache::forget('ai_calc_catalogue:'.$t->id);

        $this->info('Synced '.$drifted->count().' products · storefront + bot catalogue caches cleared — live now.');

        return self::SUCCESS;
    }
}
