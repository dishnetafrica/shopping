<?php

namespace App\Services\Marketplace\Pricing\Components;

use App\Services\Marketplace\Pricing\PriceBuild;
use App\Services\Marketplace\Pricing\PricingComponent;

/**
 * Adds import duty / tax as a percentage of the running amount. Defaults to 0
 * (no-op). Applied after shipping + import so it can reflect a duty base that
 * already includes landed freight, if that's how the tenant models it.
 */
final class DutyCost implements PricingComponent
{
    public function __construct(
        private float $pct = 0.0,   // fraction, e.g. 0.25 = +25%
    ) {}

    public function apply(PriceBuild $build): void
    {
        if ($this->pct <= 0) {
            return;
        }
        $delta = $build->amount() * $this->pct;
        $build->add($delta, 'Duty', 'duty/tax ' . round($this->pct * 100, 2) . '%');
    }
}
