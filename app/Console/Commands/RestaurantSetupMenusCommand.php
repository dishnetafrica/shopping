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
    protected $signature = 'restaurant:setup-menus {slug : tenant slug} {--food-name=Food Menu} {--bev-name=Beverages Menu}';
    protected $description = 'Activate restaurant mode and build Food/Beverages menus from a tenant\'s product categories';

    /** category-name keywords that mark a BEVERAGE category (covers soft drinks + full bar) */
    private array $bev = [
        'drink', 'beverage', 'juice', 'soda', 'water', 'tea', 'coffee', 'shake', 'smoothie', 'lassi',
        'cocktail', 'mocktail', 'beer', 'lager', 'cider', 'wine', 'champagne', 'sparkling', 'whisky',
        'whiskey', 'scotch', 'bourbon', 'gin', 'rum', 'vodka', 'tequila', 'brandy', 'cognac', 'liqueur',
        'aperitif', 'spirit', 'peg', 'mojito',
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

        $s = $t->settings ?: [];
        $s['restaurant_mode'] = true;
        $s['category_groups'] = $groups;
        $t->settings = $s;
        $t->save();

        $this->info("✅ '{$slug}' is now a restaurant (vertical=" . Vertical::of($t) . ").");
        $this->line('  ' . $this->option('food-name') . ' (' . count($food) . '): ' . implode(', ', $food));
        $this->line('  ' . $this->option('bev-name')  . ' (' . count($bev)  . '): ' . ($bev ? implode(', ', $bev) : '(none detected)'));
        $this->newLine();
        $this->comment('Now run:  php artisan optimize:clear   then hard-refresh /' . $slug);
        $this->comment('Adjust any miscategorised category in the seller panel → Settings → Website & branding → Menus.');

        return self::SUCCESS;
    }
}
