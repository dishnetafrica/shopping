<?php

namespace App\Services\Bot\Search;

use Illuminate\Support\Facades\Log;

/**
 * Supermarket Search — Meilisearch client wrapper.
 *
 * Every method is guarded so that if the meilisearch-php SDK isn't installed or MEILI_HOST isn't
 * set, the whole thing reports disabled and the caller falls back to the DB search. This keeps
 * the bot working before Meilisearch is provisioned.
 *
 * Server setup (deploy): `composer require meilisearch/meilisearch-php`, run a Meilisearch
 * instance, set MEILI_HOST (and MEILI_KEY), then `php artisan search:index`.
 */
class MeiliClient
{
    public function enabled(): bool
    {
        return class_exists(\Meilisearch\Client::class) && trim((string) env('MEILI_HOST', '')) !== '';
    }

    protected function client()
    {
        $key = trim((string) env('MEILI_KEY', ''));
        return new \Meilisearch\Client((string) env('MEILI_HOST'), $key !== '' ? $key : null);
    }

    public function indexName(int $tenantId): string
    {
        return 'products_t' . $tenantId;
    }

    /** @return array|null array of hit documents, or null when search is unavailable */
    public function search(int $tenantId, string $query, array $opts = []): ?array
    {
        if (! $this->enabled()) return null;
        try {
            $res = $this->client()->index($this->indexName($tenantId))->search($query, $opts);
            return method_exists($res, 'getHits') ? $res->getHits() : ($res['hits'] ?? []);
        } catch (\Throwable $e) {
            Log::warning('MeiliClient search failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Configure searchable / filterable / sortable attributes + synonyms for a tenant index. */
    public function configure(int $tenantId): bool
    {
        if (! $this->enabled()) return false;
        try {
            $idx = $this->client()->index($this->indexName($tenantId));
            $idx->updateSearchableAttributes(['name', 'brand', 'category', 'keywords']);
            $idx->updateFilterableAttributes(['category', 'brand', 'stock', 'active', 'price']);
            $idx->updateSortableAttributes(['popularity', 'price', 'stock']);
            $idx->updateSynonyms(SearchSynonyms::meiliSynonyms());
            return true;
        } catch (\Throwable $e) {
            Log::warning('MeiliClient configure failed: ' . $e->getMessage());
            return false;
        }
    }

    public function indexDocuments(int $tenantId, array $docs): bool
    {
        if (! $this->enabled() || ! $docs) return false;
        try {
            $this->client()->index($this->indexName($tenantId))->addDocuments($docs, 'id');
            return true;
        } catch (\Throwable $e) {
            Log::warning('MeiliClient indexDocuments failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteDocument(int $tenantId, int $productId): void
    {
        if (! $this->enabled()) return;
        try {
            $this->client()->index($this->indexName($tenantId))->deleteDocument($productId);
        } catch (\Throwable $e) {
            Log::warning('MeiliClient deleteDocument failed: ' . $e->getMessage());
        }
    }
}
