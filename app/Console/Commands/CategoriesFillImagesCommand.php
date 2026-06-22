<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Har category ne uska EK product ni image aapo (je product ma image_url chhe).
 * Products string `category` thi judaay chhe (category = category.name).
 *
 *   php artisan categories:fill-images                 # badha tenant, khali wale j
 *   php artisan categories:fill-images --tenant=1      # fakt aa tenant
 *   php artisan categories:fill-images --force         # already-set ne pan overwrite
 *   php artisan categories:fill-images --dry           # preview only
 */
class CategoriesFillImagesCommand extends Command
{
    protected $signature = 'categories:fill-images {--tenant= : tenant id (default: badha)} {--force : already-set overwrite karo} {--dry : preview, write nahi}';
    protected $description = 'Category ne uske product ni image aapo (image_url).';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dry   = (bool) $this->option('dry');

        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::withoutGlobalScopes()->pluck('id')->all();

        $set = 0; $skip = 0; $noimg = 0;

        foreach ($tenantIds as $tid) {
            $cats = Category::withoutGlobalScopes()->where('tenant_id', $tid)->get();
            foreach ($cats as $cat) {
                if ($cat->image_url && ! $force) { $skip++; continue; }

                // aa category na product ma thi ek sari image lao (popular pehla)
                $img = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tid)
                    ->where('category', $cat->name)
                    ->whereNotNull('image_url')->where('image_url', '!=', '')
                    ->orderByDesc('popularity')
                    ->orderBy('display_order')
                    ->orderBy('id')
                    ->value('image_url');

                if (! $img) { $noimg++; $this->line("  [t{$tid}] {$cat->name} — koi product image nathi"); continue; }

                if (! $dry) {
                    $cat->image_url = $img;
                    $cat->saveQuietly();   // tenant scope/events bypass
                }
                $set++;
                $this->line("  [t{$tid}] {$cat->name}  ->  {$img}");
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Set: {$set}  |  pehle-se-hatu (skip): {$skip}  |  product-image-vinanu: {$noimg}");
        return self::SUCCESS;
    }
}
