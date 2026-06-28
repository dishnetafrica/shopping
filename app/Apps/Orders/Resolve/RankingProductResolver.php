<?php
namespace App\Apps\Orders\Resolve;

use App\Apps\Orders\Contracts\ProductResolver;
use App\Apps\Orders\Dto\CartLine;
use App\Models\Product;
use App\Models\ProductAliasEntry;

/**
 * Concrete ProductResolver over the real Product Master. Loads candidates (by alias + name +
 * keywords), ranks them with CandidateScorer, and applies weight-aware pricing via WeightResolver.
 * Data-driven: the catalogue's own aliases/variants/popularity decide outcomes — not hardcoded rules.
 */
class RankingProductResolver implements ProductResolver
{
    public function __construct(
        private CandidateScorer $scorer = new CandidateScorer(),
        private WeightResolver $weights = new WeightResolver(),
    ) {}

    public function report(int $tenantId, CartLine $line, array $context = []): ProductResolution
    {
        $candidates = $this->loadCandidates($tenantId, $line->name);
        $res = $this->scorer->rank($line->name, $candidates, $context);

        if ($res->decision === ProductResolution::AUTO && $res->line && $res->line->productId) {
            $product = collect($candidates)->firstWhere('id', $res->line->productId);
            if ($product) {
                $w = $this->weights->resolve($product, $line->weightGrams);
                if ($w['ok']) {
                    $res->line->weightGrams = $w['weight_grams'];
                    $res->line->variantId   = $w['variant_id'];
                    $res->line->unitPrice   = $w['unit_price'];
                    $res->line->quantity    = $line->quantity;
                } else {
                    $res->weightIssue = $w['reason'];           // product known, but weight needs clarifying
                }
            }
        }
        return $res;
    }

    public function resolve(int $tenantId, CartLine $line): CartLine
    {
        return $this->report($tenantId, $line)->line ?? $line;
    }

    /** @return array<int,array> candidate product data shaped for CandidateScorer */
    private function loadCandidates(int $tenantId, string $query): array
    {
        $tokens = array_filter(preg_split('/\s+/', mb_strtolower($query)) ?: []);
        $ids = ProductAliasEntry::where('tenant_id', $tenantId)
            ->where(function ($w) use ($tokens, $query) {
                $w->where('alias', mb_strtolower($query));
                foreach ($tokens as $t) $w->orWhere('alias', $t);
            })->pluck('product_id')->all();

        $products = Product::query()
            ->where(function ($w) use ($ids, $query, $tokens) {
                if ($ids) $w->whereIn('id', $ids);
                $w->orWhere('name', 'ilike', "%{$query}%");
                foreach ($tokens as $t) $w->orWhere('name', 'ilike', "%{$t}%")->orWhere('keywords', 'ilike', "%{$t}%");
            })
            ->limit(25)->get();

        $aliasMap = ProductAliasEntry::where('tenant_id', $tenantId)
            ->whereIn('product_id', $products->pluck('id'))->get()->groupBy('product_id');

        return $products->map(function (Product $p) use ($aliasMap) {
            $aliases = $aliasMap[$p->id] ?? collect();
            $byType = fn ($type) => $aliases->where('type', $type)->pluck('alias')->all();
            $variants = [];
            foreach ($p->weightVariants as $v) { $variants[(int) $v->weight_grams] = ['id' => $v->id, 'price' => (float) $v->price]; }
            return [
                'id' => $p->id, 'name' => $p->name,
                'aliases' => $byType('alias'), 'tokens' => $byType('token'),
                'misspellings' => $byType('misspelling'), 'gujarati' => array_filter([$p->gujarati_name, ...$byType('gujarati')]),
                'short' => $p->short_name, 'default_aliases' => $aliases->where('is_default', true)->pluck('alias')->all(),
                'popularity' => (int) ($p->popularity ?? 0),
                'available' => (bool) $p->active && ((int) $p->stock > 0 || (bool) $p->sold_by_weight),
                'category' => $p->category,
                'sold_by_weight' => (bool) $p->sold_by_weight, 'price' => $p->price !== null ? (float) $p->price : null,
                'reference_price' => $p->reference_price !== null ? (float) $p->reference_price : null,
                'reference_weight_grams' => (int) ($p->reference_weight_grams ?: 1000),
                'allow_prorata' => (bool) ($p->allow_prorata ?? true),
                'default_variant_id' => null, 'variants' => $variants, 'min_grams' => 100,
            ];
        })->all();
    }
}
