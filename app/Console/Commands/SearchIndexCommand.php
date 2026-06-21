<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Bot\Search\MeiliClient;
use App\Services\Bot\Search\SearchIndexer;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Push products into Meilisearch. Run after enabling supermarket_search or on a schedule.
 *   php artisan search:index            # all tenants with supermarket_search on
 *   php artisan search:index --tenant=2 # one tenant
 */
class SearchIndexCommand extends Command
{
    protected $signature = 'search:index {--tenant= : tenant id} {--all : include tenants without the toggle}';
    protected $description = 'Index products into Meilisearch for supermarket-scale search.';

    public function handle(SearchIndexer $indexer, MeiliClient $meili): int
    {
        if (! $meili->enabled()) {
            $this->warn('Meilisearch is not configured (install meilisearch/meilisearch-php and set MEILI_HOST). Nothing indexed.');
            return self::SUCCESS;
        }

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('id', (int) $this->option('tenant')))
            ->get();

        $total = 0;
        foreach ($tenants as $tenant) {
            if (! $this->option('all') && ! $this->option('tenant') && ! (bool) $tenant->setting('supermarket_search', false)) {
                continue;
            }
            app(TenantContext::class)->set($tenant->id);
            $n = $indexer->reindex($tenant);
            $total += $n;
            $this->line("tenant {$tenant->id}: indexed {$n} products");
        }

        $this->info("Done. {$total} products indexed.");
        return self::SUCCESS;
    }
}
