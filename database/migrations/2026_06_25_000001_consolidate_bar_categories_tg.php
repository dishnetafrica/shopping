<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data migration: merge all spirit / wine categories of the
 * Great Indian Dhabaa (slug "tg") into a single "Bar" category, and update
 * that shop's menu groups so the Beverages tab shows one Bar tile instead of 14.
 *
 * Only touches the "tg" tenant. Runs once. Other shops are untouched.
 * To use a different single-category name, change $NEW below before deploying.
 */
return new class extends Migration
{
    public function up(): void
    {
        $slug = 'tg';
        $NEW  = 'Bar';   // <- single category name (e.g. 'Drinks', 'Wine & Spirits')

        // every spirit/wine category, both UK + US spellings to be safe
        $bar = [
            'Scotch Whisky', 'Scotch Whiskey', 'Single Malt',
            'Bourbon & Irish Whisky', 'Bourbon & Irish Whiskey',
            'Cognac & Brandy', 'Gin', 'Rum', 'Liqueur', 'Vodka', 'Tequila',
            'Wine By Glass', 'Wine by Glass', 'Aperitifs',
            'Champagne & Sparkling Wine', 'White Wine', 'Red Wine',
        ];

        $tenant = DB::table('tenants')->where('slug', $slug)->first();
        if (! $tenant) {
            return; // tg not on this environment — nothing to do
        }

        // 1) re-categorise the products
        DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->whereIn('category', $bar)
            ->update(['category' => $NEW]);

        // 2) rewrite the menu groups: drop the 14, add the single Bar tile to the
        //    menu(s) that contained them (the Beverages tab).
        $settings = json_decode($tenant->settings ?? '{}', true);
        if (! is_array($settings)) {
            $settings = [];
        }

        if (! empty($settings['category_groups']) && is_array($settings['category_groups'])) {
            foreach ($settings['category_groups'] as $menu => $cats) {
                $cats = (array) $cats;
                $keep = array_values(array_filter($cats, function ($c) use ($bar) {
                    return ! in_array($c, $bar, true);
                }));
                if (count($keep) !== count($cats)) {
                    $keep[] = $NEW;
                }
                $settings['category_groups'][$menu] = array_values(array_unique($keep));
            }

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // Data consolidation — the original per-product sub-categories cannot be
        // auto-restored. Restore from your exported products CSV if you need to revert.
    }
};
