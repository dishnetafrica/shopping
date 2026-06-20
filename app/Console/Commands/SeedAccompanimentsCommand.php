<?php

namespace App\Console\Commands;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Seed a free, required "Choice of accompaniment" (Rice / Naan / Chapati) and attach it to
 * every Main Course dish for a tenant. Idempotent — safe to re-run; only attaches where missing.
 *
 *   php artisan shopbot:seed-accompaniments --tenant=6
 *   php artisan shopbot:seed-accompaniments --tenant=6 --category="Main Course" --options="Rice,Naan,Chapati"
 */
class SeedAccompanimentsCommand extends Command
{
    protected $signature = 'shopbot:seed-accompaniments
        {--tenant= : tenant id (required)}
        {--category=Main Course : product category to attach to}
        {--name=accompaniment : the group name shown to customers}
        {--options=Rice,Naan,Chapati : comma-separated free options}';

    protected $description = 'Create a required free accompaniment choice and attach it to Main Course dishes.';

    public function handle(): int
    {
        $tid = (int) $this->option('tenant');
        if (! $tid || ! Tenant::find($tid)) {
            $this->error('Pass a valid --tenant=<id>.');
            return self::FAILURE;
        }

        $group = ModifierGroup::withoutGlobalScopes()
            ->where('tenant_id', $tid)->where('name', $this->option('name'))->first();

        if (! $group) {
            $group = new ModifierGroup();
            $group->tenant_id  = $tid;
            $group->name       = (string) $this->option('name');
            $group->required   = true;
            $group->min_select = 1;
            $group->max_select = 1;
            $group->free_qty   = 1;
            $group->active     = true;
            $group->save();
            $this->info("Created group #{$group->id} \"{$group->name}\" (required, pick 1, free).");
        } else {
            $this->line("Group #{$group->id} already exists — reusing.");
        }

        $sort = 0;
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('options')))) as $name) {
            $exists = ModifierOption::where('modifier_group_id', $group->id)->where('name', $name)->exists();
            if (! $exists) {
                ModifierOption::create([
                    'modifier_group_id' => $group->id,
                    'name'        => $name,
                    'price_delta' => 0,            // included free
                    'sort'        => $sort,
                    'active'      => true,
                ]);
            }
            $sort++;
        }

        $cat = (string) $this->option('category');
        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tid)->where('category', $cat)->get();

        $attached = 0;
        foreach ($products as $p) {
            $already = $p->modifierGroups()->where('modifier_groups.id', $group->id)->exists();
            if (! $already) {
                $p->modifierGroups()->attach($group->id);
                $attached++;
            }
        }

        $this->info("Attached to {$attached} new \"{$cat}\" dish(es) ({$products->count()} total in category).");
        $this->line('Run `php artisan catalogue:flush --tenant='.$tid.'` to refresh the storefront.');
        return self::SUCCESS;
    }
}
