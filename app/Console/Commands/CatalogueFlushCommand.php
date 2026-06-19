<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Clears ONLY the storefront catalogue cache (the per-tenant "catalogue:{id}" key set by
 * StorefrontController). Unlike `cache:clear`, this never touches sessions — so you stay
 * logged into the panel.
 *
 *   php artisan catalogue:flush            # all shops
 *   php artisan catalogue:flush --tenant=1 # one shop
 */
class CatalogueFlushCommand extends Command
{
    protected $signature = 'catalogue:flush {--tenant= : tenant id (default: all shops)}';
    protected $description = 'Refresh the storefront catalogue cache without logging anyone out.';

    public function handle(): int
    {
        $ids = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::withoutGlobalScopes()->pluck('id')->all();

        foreach ($ids as $id) {
            Cache::forget("catalogue:{$id}");
        }

        $this->info('Flushed catalogue cache for ' . count($ids) . ' shop(s). Sessions untouched.');
        return self::SUCCESS;
    }
}
