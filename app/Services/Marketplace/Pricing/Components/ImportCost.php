<?php

namespace App\Services\Marketplace\Pricing\Components;

use App\Services\Marketplace\Pricing\PriceBuild;
use App\Services\Marketplace\Pricing\PricingComponent;

/**
 * Adds import handling / clearing costs as a percentage of the running amount.
 * Defaults to 0 (no-op). Separate from duty so the two can be tuned and shown
 * independently on a landed-cost breakdown.
 */
final class ImportCost implements PricingComponent
{
    public function __construct(
        private float $pct = 0.0,   // fraction, e.g. 0.08 = +8%
    ) {}

    public function apply(PriceBuild $build): void
    {
        if ($this->pct <= 0) {
            return;
        }
        $delta = $build->amount() * $this->pct;
        $build->add($delta, 'Import', 'clearing/handling ' . round($this->pct * 100, 2) . '%');
    }
}
