<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Re-maps a shop's messy long category list into a small, clean, Blinkit-style set,
 * splitting the giant "Food" bucket into proper sub-categories using product names.
 *
 *   php artisan catalogue:recategorize                 # Family Shoppers, live
 *   php artisan catalogue:recategorize --tenant=5
 *   php artisan catalogue:recategorize --dry           # preview only, no writes
 *   php artisan catalogue:recategorize --restore       # undo (from backup)
 *
 * Fully reversible: the original category of every product is saved to a backup file
 * the first time it runs, and --restore puts everything back.
 */
class CatalogueRecategorizeCommand extends Command
{
    protected $signature = 'catalogue:recategorize {--tenant= : tenant id (default: Family Shoppers)} {--dry : preview without writing} {--restore : revert to the saved backup}';
    protected $description = 'Consolidate + split product categories into a clean Blinkit-style set.';

    /** Curated display order for the new menu (food first, like Blinkit). */
    private array $order = [
        'Fruits & Vegetables',
        'Rice, Flour & Grains',
        'Masala, Oil & Spices',
        'Sauces, Jam & Condiments',
        'Snacks, Biscuits & Bakery',
        'Breakfast & Cereals',
        'Sweets, Chocolate & Ice Cream',
        'Beverages & Drinks',
        'Groceries & Packaged Food',
        'Beauty & Cosmetics',
        'Bath, Soap & Personal Care',
        'Baby Care',
        'Health & Hygiene',
        'Home & Cleaning',
        'Kitchen & Dining',
        'Stationery & Office',
        'Toys, Gifts & Party',
        'Electronics & Hardware',
        'Clothing & Apparel',
        'Tobacco & Smoking',
        'Pet & Animal',
    ];

    public function handle(): int
    {
        $tid = $this->option('tenant')
            ? (int) $this->option('tenant')
            : (int) Tenant::withoutGlobalScopes()->where('name', 'like', '%Family%')->value('id');

        if (! $tid) { $this->error('Tenant not found.'); return self::FAILURE; }
        $this->info("Tenant #{$tid}");

        $backupPath = "recategorize-backup-{$tid}.json";

        if ($this->option('restore')) {
            return $this->restore($tid, $backupPath);
        }

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tid)
            ->get(['id', 'name', 'category']);
        if ($products->isEmpty()) { $this->warn('No products.'); return self::SUCCESS; }

        // Save original categories once (reversibility) — never overwrite the first backup.
        if (! $this->option('dry') && ! Storage::exists($backupPath)) {
            Storage::put($backupPath, $products->pluck('category', 'id')->toJson());
            $this->line("Backup saved: storage/app/{$backupPath}");
        }

        $counts = [];
        $updates = [];
        foreach ($products as $p) {
            $new = $this->classify((string) $p->name, (string) $p->category);
            $counts[$new] = ($counts[$new] ?? 0) + 1;
            if ($new !== (string) $p->category) $updates[$new][] = $p->id;
        }

        // Summary
        $this->line('');
        $shown = $this->order;
        foreach (array_keys($counts) as $c) if (! in_array($c, $shown, true)) $shown[] = $c;
        foreach ($shown as $c) {
            if (isset($counts[$c])) $this->line(str_pad($c, 34) . $counts[$c]);
        }
        $this->line('');
        $this->info(count($counts) . ' categories total.');

        if ($this->option('dry')) { $this->warn('Dry run — nothing written.'); return self::SUCCESS; }

        // Apply product updates (batched by destination category).
        $changed = 0;
        foreach ($updates as $new => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $changed += Product::withoutGlobalScopes()->whereIn('id', $chunk)->update(['category' => $new]);
            }
        }
        $this->info("Updated {$changed} products.");

        $this->rebuildCategories($tid, array_keys($counts));
        $this->remapCategoryImages($tid);

        Cache::forget("catalogue:{$tid}");
        $this->line('');
        $this->info('Catalogue cache flushed — the storefront will show the new categories immediately.');
        return self::SUCCESS;
    }

    /** Map one product to a clean category from its name + current category. First match wins. */
    private function classify(string $name, string $old): string
    {
        $n = mb_strtolower($name);
        $o = trim($old);
        $has = fn (array $ws) => array_filter($ws, fn ($w) => str_contains($n, $w)) !== [];

        // exercise books / stationery (covers the newly added SKUs)
        if (stripos($o, 'exercise') !== false || stripos($o, 'stationery') !== false) return 'Stationery & Office';

        if ($o === 'Food') {
            if ($has(['sauce','ketchup','vinegar','honey','jam','pickle','chutney','mayonnaise','heinz','paste','spread'])) return 'Sauces, Jam & Condiments';
            if ($has(['cornflake','corn flake','oats','cereal','porridge','bournvita','milo','weetabix','cocoa'])) return 'Breakfast & Cereals';
            if ($has(['chocolate','candy','sweet','toffee','ice cream','lollipop',' gum'])) return 'Sweets, Chocolate & Ice Cream';
            if ($has(['biscuit','cookie','crisps','chips','snack','namkeen','nuts','popcorn','wafer','bread','cake','bun','britannia','nuvita'])) return 'Snacks, Biscuits & Bakery';
            if ($has(['juice','squash','soda','drink','cordial','water'])) return 'Beverages & Drinks';
            if ($has(['masala','pepper','cumin','coriander','turmeric','chilli','curry','garam','cinnamon','spice'])) return 'Masala, Oil & Spices';
            if ($has(['rice','basmati','flour','atta','maize','posho','dal','beans','lentil','chana','sugar','salt','wheat','spaghetti','pasta','noodle','semolina'])) return 'Rice, Flour & Grains';
            return 'Groceries & Packaged Food';
        }
        if ($o === 'Spices') {
            if ($has(['sauce','pickle','chutney','vinegar','paste'])) return 'Sauces, Jam & Condiments';
            if ($has(['flour','rice','dal','beans','chana','atta'])) return 'Rice, Flour & Grains';
            return 'Masala, Oil & Spices';
        }
        if ($o === 'Oil') return 'Masala, Oil & Spices';

        if ($o === 'Cosmetics') {
            if ($has(['soap','bath','shower','body wash','toothpaste','toothbrush','oral','colgate','whitedent','deo','petroleum','vaseline','jelly','wash','wipes','sanitiz','shampoo'])) return 'Bath, Soap & Personal Care';
            return 'Beauty & Cosmetics';
        }
        if ($o === 'Soap') return 'Bath, Soap & Personal Care';

        if ($o === 'Household') {
            if ($has(['clean','detergent','bleach','toilet','broom','brush','mop','freshener','incense','dhoop','insectic','mortein','polish','washing','soap powder'])) return 'Home & Cleaning';
            if ($has(['glass','steel','bowl','flask','jug','container','cup','plate','plastic','bottle','bucket','basin','kitchen','cook','pan','pot','spoon','knife','mat','candle','tray','vacuum'])) return 'Kitchen & Dining';
            return 'Home & Cleaning';
        }

        $map = [
            'Fruits' => 'Fruits & Vegetables', 'Vegetables' => 'Fruits & Vegetables',
            'Drinks' => 'Beverages & Drinks', 'Juice' => 'Beverages & Drinks', 'Beverages' => 'Beverages & Drinks',
            'Tea' => 'Beverages & Drinks', 'Milk' => 'Beverages & Drinks', 'Wine' => 'Beverages & Drinks',
            'Chocolate' => 'Sweets, Chocolate & Ice Cream', 'Candy' => 'Sweets, Chocolate & Ice Cream', 'Ice Cream' => 'Sweets, Chocolate & Ice Cream',
            'Pads' => 'Health & Hygiene', 'Protectors' => 'Health & Hygiene', 'Medication' => 'Health & Hygiene',
            'Baby Items' => 'Baby Care', 'Diapers' => 'Baby Care',
            'Cleaners' => 'Home & Cleaning', 'Sprays' => 'Home & Cleaning', 'Toilet Care' => 'Home & Cleaning',
            'Insecticide' => 'Home & Cleaning', 'Polishes' => 'Home & Cleaning', 'Gas' => 'Home & Cleaning',
            'Kitchenware' => 'Kitchen & Dining', 'Plastics' => 'Kitchen & Dining',
            'Toys' => 'Toys, Gifts & Party', 'Gifts' => 'Toys, Gifts & Party', 'Party' => 'Toys, Gifts & Party', 'Key Rings' => 'Toys, Gifts & Party',
            'Electronics' => 'Electronics & Hardware', 'Batteries' => 'Electronics & Hardware', 'Blades' => 'Electronics & Hardware',
            'Locks' => 'Electronics & Hardware', 'Flashes' => 'Electronics & Hardware',
            'Clothing' => 'Clothing & Apparel',
            'Smokes' => 'Tobacco & Smoking', 'Tobbaco' => 'Tobacco & Smoking', 'Tobacco' => 'Tobacco & Smoking',
            'Animal Food' => 'Pet & Animal',
        ];
        return $map[$o] ?? ($o !== '' ? $o : 'Groceries & Packaged Food');
    }

    /** Rebuild the categories table to exactly the new set, in curated order. */
    private function rebuildCategories(int $tid, array $present): void
    {
        $ordered = array_values(array_filter($this->order, fn ($c) => in_array($c, $present, true)));
        foreach ($present as $c) if (! in_array($c, $ordered, true)) $ordered[] = $c;

        Category::withoutGlobalScopes()->where('tenant_id', $tid)->delete();
        $sort = 0;
        foreach ($ordered as $name) {
            Category::withoutGlobalScopes()->create([
                'tenant_id' => $tid, 'name' => $name, 'sort' => $sort++, 'active' => true,
            ]);
        }
        $this->info(count($ordered) . ' categories rebuilt.');
    }

    /** Move any category photos from old category names onto their new primary category. */
    private function remapCategoryImages(int $tid): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($tid);
        if (! $tenant) return;
        $images = (array) $tenant->setting('category_images', []);
        if (! $images) return;

        $primary = [
            'Food' => 'Groceries & Packaged Food', 'Fruits' => 'Fruits & Vegetables',
            'Spices' => 'Masala, Oil & Spices', 'Oil' => 'Masala, Oil & Spices',
            'Cosmetics' => 'Beauty & Cosmetics', 'Soap' => 'Bath, Soap & Personal Care',
            'Household' => 'Home & Cleaning', 'Drinks' => 'Beverages & Drinks',
            'Toys' => 'Toys, Gifts & Party', 'Stationery' => 'Stationery & Office',
            'Electronics' => 'Electronics & Hardware', 'Kitchenware' => 'Kitchen & Dining',
        ];
        $next = [];
        foreach ($images as $old => $url) {
            $key = $primary[$old] ?? $old;
            if (! isset($next[$key])) $next[$key] = $url;   // first wins, don't clobber
        }
        $tenant->putSetting('category_images', $next);
        $this->info('Category photos remapped.');
    }

    private function restore(int $tid, string $backupPath): int
    {
        if (! Storage::exists($backupPath)) { $this->error('No backup found — nothing to restore.'); return self::FAILURE; }
        $orig = json_decode(Storage::get($backupPath), true) ?: [];
        $byCat = [];
        foreach ($orig as $id => $cat) $byCat[$cat][] = (int) $id;

        $n = 0;
        foreach ($byCat as $cat => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $n += Product::withoutGlobalScopes()->whereIn('id', $chunk)->update(['category' => $cat]);
            }
        }
        $this->rebuildCategories($tid, array_values(array_unique(array_values($orig))));
        Cache::forget("catalogue:{$tid}");
        $this->info("Restored {$n} products to their original categories. Catalogue cache flushed.");
        return self::SUCCESS;
    }
}
