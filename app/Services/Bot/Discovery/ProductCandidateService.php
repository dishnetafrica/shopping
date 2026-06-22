<?php

namespace App\Services\Bot\Discovery;

use App\Models\BusinessDiscovery;
use App\Models\DiscoveryProductCandidate;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Business Brain — Product Candidates.
 *
 * Surfaces the discovery report's `unverified_products` (frequent chat terms that matched no
 * catalogue product) so the owner can one-tap approve them into the catalogue or dismiss them.
 *
 *  - list()    reads the latest discovery, drops terms that already map to a product or that the
 *              owner already decided, and returns the rest (pure gating via CandidateFilter).
 *  - approve() creates a DRAFT Product (active=false, price 0) from the term and records the
 *              decision. Draft, not live: a catalogue product needs a price before the bot quotes
 *              it, so we never fabricate one — the owner finishes it in Products.
 *  - dismiss() records the decision so the term never re-surfaces.
 *
 * NOTE: `unverified_products` is only produced when the tenant HAS a catalogue (catalogue mode).
 * No-catalogue tenants have no candidates by design (legacy mode deliberately emits none to avoid
 * the "Https/Tinyurl" chat-token garbage). Such a tenant gets candidates once it has a catalogue.
 */
class ProductCandidateService
{
    /** @return array{count:int,items:list<array{term:string,count:int}>} */
    public function list(Tenant $tenant, int $limit = 20): array
    {
        $d = BusinessDiscovery::where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $unverified = [];
        if ($d && is_array($d->report)) {
            $unverified = $d->report['sections']['unverified_products'] ?? [];
        }
        if (! is_array($unverified) || $unverified === []) {
            return ['count' => 0, 'items' => []];
        }

        $items = CandidateFilter::filter(
            $unverified,
            $this->productNamesNorm($tenant),
            $this->decidedNorm($tenant),
            $limit
        );

        return ['count' => count($items), 'items' => $items];
    }

    /** @return array{ok:bool,product_id?:int,noop?:bool,error?:string} */
    public function approve(Tenant $tenant, string $term, string $by = 'owner'): array
    {
        $term = trim($term);
        $norm = CandidateFilter::normalize($term);
        if ($norm === '') return ['ok' => false, 'error' => 'empty_term'];

        // Already decided — idempotent no-op.
        $existing = DiscoveryProductCandidate::where('tenant_id', $tenant->id)
            ->where('term_norm', $norm)->first();
        if ($existing) {
            return ['ok' => true, 'noop' => true, 'product_id' => (int) $existing->product_id];
        }

        // If a product with this normalised name already exists, link it instead of duplicating.
        $productId = $this->existingProductId($tenant, $norm);
        if ($productId === null) {
            $p = Product::create([
                'name'       => $term !== '' ? $term : ucwords($norm),
                'category'   => '',
                'keywords'   => mb_strtolower($term),
                'price'      => 0,
                'base_price' => 0,
                'stock'      => 0,
                'active'     => false,   // DRAFT — owner sets a price to make it live
            ]);
            $productId = (int) $p->id;
        }

        $this->record($tenant, $term, $norm, 'approved', $productId, $by);

        return ['ok' => true, 'product_id' => $productId];
    }

    /** @return array{ok:bool,noop?:bool,error?:string} */
    public function dismiss(Tenant $tenant, string $term, string $by = 'owner'): array
    {
        $term = trim($term);
        $norm = CandidateFilter::normalize($term);
        if ($norm === '') return ['ok' => false, 'error' => 'empty_term'];

        $existing = DiscoveryProductCandidate::where('tenant_id', $tenant->id)
            ->where('term_norm', $norm)->first();
        if ($existing) return ['ok' => true, 'noop' => true];

        $this->record($tenant, $term, $norm, 'dismissed', null, $by);

        return ['ok' => true];
    }

    private function record(Tenant $tenant, string $term, string $norm, string $decision, ?int $productId, string $by): void
    {
        DiscoveryProductCandidate::create([
            'tenant_id'  => $tenant->id,
            'term'       => $term,
            'term_norm'  => $norm,
            'decision'   => $decision,
            'product_id' => $productId,
            'decided_by' => $by !== '' ? $by : 'owner',
            'created_at' => now(),
        ]);
    }

    /** @return list<string> normalised names of every product (active OR draft). */
    private function productNamesNorm(Tenant $tenant): array
    {
        return Product::where('tenant_id', $tenant->id)
            ->limit(5000)->pluck('name')
            ->map(fn ($n) => CandidateFilter::normalize((string) $n))
            ->filter()->unique()->values()->all();
    }

    /** @return list<string> normalised terms already approved/dismissed. */
    private function decidedNorm(Tenant $tenant): array
    {
        return DiscoveryProductCandidate::where('tenant_id', $tenant->id)
            ->pluck('term_norm')
            ->map(fn ($n) => (string) $n)
            ->filter()->unique()->values()->all();
    }

    private function existingProductId(Tenant $tenant, string $norm): ?int
    {
        $row = Product::where('tenant_id', $tenant->id)->limit(5000)->get(['id', 'name'])
            ->first(fn ($p) => CandidateFilter::normalize((string) $p->name) === $norm);
        return $row ? (int) $row->id : null;
    }
}
