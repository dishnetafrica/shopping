<?php

namespace App\Services\Marketplace\Pricing;

/**
 * An ordered set of PricingComponents. price() runs a base amount through each
 * stage and returns the completed PriceBuild (final amount, currency, and the
 * full landed-cost breakdown).
 *
 * The plan doesn't know or care which stages it holds — add, remove, or reorder
 * components in PricingPlanFactory and every quote follows the new pipeline with
 * no changes here or in MarketplaceSearch.
 */
final class PricingPlan
{
    /** @param array<int,PricingComponent> $components */
    public function __construct(
        private array $components,
    ) {}

    public function price(int $baseAmount, string $baseCurrency): PriceBuild
    {
        $build = new PriceBuild((float) $baseAmount, $baseCurrency);
        foreach ($this->components as $component) {
            $component->apply($build);
        }
        return $build;
    }
}
