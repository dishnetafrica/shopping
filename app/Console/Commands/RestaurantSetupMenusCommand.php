<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Support\Vertical;
use Illuminate\Console\Command;

/**
 * One-shot: turn a tenant into a restaurant and build its Food / Beverages menus from the
 * categories its products actually use. Writes ONLY tenant settings (restaurant_mode +
 * category_groups) — never touches products, prices or images.
 *
 *   php artisan restaurant:setup-menus tg
 */
class RestaurantSetupMenusCommand extends Command
{
    protected $signature = 'restaurant:setup-menus {slug : tenant slug} {--preset= : named section layout, e.g. dhaba} {--food-name=Food Menu} {--bev-name=Beverages Menu}';
    protected $description = 'Activate restaurant mode and build menu tabs from a tenant\'s product categories';

    /** category-name keywords that mark a BEVERAGE category (covers soft drinks + full bar) */
    private array $bev = [
        'drink', 'beverage', 'juice', 'soda', 'water', 'tea', 'coffee', 'shake', 'smoothie', 'lassi',
        'cocktail', 'mocktail', 'beer', 'lager', 'cider', 'wine', 'champagne', 'sparkling', 'whisky',
        'whiskey', 'scotch', 'bourbon', 'gin', 'rum', 'vodka', 'tequila', 'brandy', 'cognac', 'liqueur',
        'aperitif', 'spirit', 'peg', 'mojito',
    ];

    /** Curated section layouts: ['Tab name' => ['Category', ...], ...] in display order. */
    private array $presets = [
        'dhaba' => [
            'Soups & Starters'         => ['Soups', 'Ever Fresh Salads', 'Vegetarian Starters', 'Non Vegetarian Starters', 'Chinese Vegetarian Starter', 'Chinese Non Veg Starter', 'Indian Street Food'],
            'Tandoor & Grill'          => ['Grilled & Fried', 'Chicken Corner'],
            'Main Course'              => ['Indian Veg Main Course', 'Indian Non Veg Main Course', 'Sea Food Chinese'],
            'Rice & Breads'            => ['Rice', 'Chinese Rice', 'Bread'],
            'Pizza & Fast Food'        => ['Pizza', 'Pasta', 'Burger', 'Sandwiches'],
            'South Indian & Specials'  => ['South Indian Food', 'Side Dish', 'Special Offers'],
            'Desserts'                 => ['Dessert'],
            'Soft Drinks & Shakes'     => ['Soft Drinks', 'Cold Drinks', 'Juice', 'Lassi', 'Shakes', 'Smoothie'],
            'Tea & Coffee'             => ['Tea', 'Coffee', 'Iced Coffee'],
            'Bar — Beer, Wine & Spirits' => ['Local Beers', 'Imported Beers', 'Scotch Whisky', 'Cognac & Brandy', 'Bourbon & Irish Whisky', 'Gin', 'Rum', 'Liqueur', 'Vodka', 'Tequila', 'Tequila Chocolate', 'Wine By Glass', 'Aperitifs', 'Champagne & Sparkling Wine'],
        ],
    ];

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $t = Tenant::where('slug', $slug)->first();
        if (! $t) { $this->error("Tenant '{$slug}' not found."); return self::FAILURE; }

        $cats = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('active', true)
            ->pluck('category')->filter()->unique()->values()->all();

        if (! $cats) { $this->error("No active product categories for '{$slug}'. Import the products first."); return self::FAILURE; }

        $preset = (string) $this->option('preset');
        if ($preset !== '') {
            if (! isset($this->presets[$preset])) { $this->error("Unknown preset '{$preset}'. Known: " . implode(', ', array_keys($this->presets))); return self::FAILURE; }
            $groups = $this->buildFromPreset($this->presets[$preset], $cats);
        } else {
            $groups = $this->buildFoodBev($cats);
        }

        $s = $t->settings ?: [];
        $s['restaurant_mode'] = true;
        $s['category_groups'] = $groups;
        $t->settings = $s;
        $t->save();

        $this->info("✅ '{$slug}' is now a restaurant (vertical=" . Vertical::of($t) . "), " . count($groups) . " menu tabs:");
        foreach ($groups as $tab => $list) $this->line("  • {$tab} (" . count($list) . '): ' . implode(', ', $list));
        $this->newLine();
        $this->comment('Now run:  php artisan optimize:clear   then hard-refresh /' . $slug);
        $this->comment('Tweak any grouping in the seller panel → Settings → Website & branding → Menus.');

        return self::SUCCESS;
    }

    /** Keep only categories that actually exist; drop empty tabs; sweep any leftover into "More". */
    private function buildFromPreset(array $preset, array $cats): array
    {
        $have = array_fill_keys($cats, true);
        $used = [];
        $groups = [];
        foreach ($preset as $tab => $list) {
            $keep = array_values(array_filter($list, function ($c) use ($have, &$used) {
                if (isset($have[$c])) { $used[$c] = true; return true; }
                return false;
            }));
            if ($keep) $groups[$tab] = $keep;
        }
        $leftover = array_values(array_filter($cats, fn ($c) => ! isset($used[$c])));
        if ($leftover) $groups['More'] = $leftover;   // nothing ever hidden
        return $groups;
    }

    /** Default: split into Food / Beverages by keyword. */
    private function buildFoodBev(array $cats): array
    {
        $food = []; $bev = [];
        foreach ($cats as $c) {
            $lc = mb_strtolower((string) $c);
            $isBev = false;
            foreach ($this->bev as $w) { if (str_contains($lc, $w)) { $isBev = true; break; } }
            if ($isBev) $bev[] = $c; else $food[] = $c;
        }
        $groups = [];
        if ($food) $groups[(string) $this->option('food-name')] = array_values($food);
        if ($bev)  $groups[(string) $this->option('bev-name')]  = array_values($bev);
        return $groups;
    }
}
