<?php

namespace App\Services\Marketplace\Pricing\Components;

use App\Services\Marketplace\Pricing\PriceBuild;
use App\Services\Marketplace\Pricing\PricingComponent;

/**
 * The shop's profit margin, applied to the landed cost. This is the one stage
 * that's always active today (default 15%). margin is a fraction: 0.15 = +15%.
 */
final class Markup implements PricingComponent
{
    public function __construct(
        private float $margin = 0.15,
    ) {}

    public function apply(PriceBuild $build): void
    {
        if ($this->margin == 0.0) {
            return;
        }
        $build->multiply(1 + $this->margin, 'Markup', '+' . round($this->margin * 100, 2) . '%');
    }
}
