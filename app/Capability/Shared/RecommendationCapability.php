<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Dto\Related;
use App\Models\Product;
use App\Services\ProductRecommendations;

/** Wraps the frozen ProductRecommendations engine. No new recommendation logic. */
final class RecommendationCapability
{
    /** @return Related[] */
    public function similarTo(CapabilityContext $ctx, Product $product, int $limit = 6): array
    {
        $rows = ProductRecommendations::similar($product, $limit);

        return $rows->map(fn ($p) => new Related(
            'similar', 'product', (string) $p->id, (string) $p->name,
            '$' . number_format((float) $p->price, 2), null, '/api/v1/workspace/product/' . $p->id,
        ))->values()->all();
    }

    /** @return Related[] */
    public function topInCategory(CapabilityContext $ctx, string $category, int $limit = 6, ?int $exclude = null): array
    {
        $rows = ProductRecommendations::topInCategory($ctx->tenantId(), $category, $limit, $exclude);

        return $rows->map(fn ($p) => new Related(
            'top', 'product', (string) $p->id, (string) $p->name,
            '$' . number_format((float) $p->price, 2), null, '/api/v1/workspace/product/' . $p->id,
        ))->values()->all();
    }
}
