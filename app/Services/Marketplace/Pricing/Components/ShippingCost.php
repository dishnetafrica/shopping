<?php

namespace App\Services\Marketplace\Pricing\Components;

use App\Services\Marketplace\Pricing\PriceBuild;
use App\Services\Marketplace\Pricing\PricingComponent;

/**
 * Adds inbound shipping/freight to the base cost. Flat amount (in the base
 * currency) plus an optional percentage of the running amount. Both default to
 * 0, so this stage is invisible until a tenant configures freight.
 */
final class ShippingCost implements PricingComponent
{
    public function __construct(
        private float $flat = 0.0,
        private float $pct  = 0.0,   // fraction, e.g. 0.05 = +5%
    ) {}

    public function apply(PriceBuild $build): void
    {
        if ($this->flat <= 0 && $this->pct <= 0) {
            return;                                  // no-op: nothing to add
        }
        $delta = $this->flat + ($build->amount() * $this->pct);
        if ($delta <= 0) {
            return;
        }
        $build->add($delta, 'Shipping', $this->describe());
    }

    private function describe(): string
    {
        $bits = [];
        if ($this->flat > 0) $bits[] = 'flat ' . rtrim(rtrim(number_format($this->flat, 2), '0'), '.');
        if ($this->pct > 0)  $bits[] = round($this->pct * 100, 2) . '%';
        return 'freight (' . implode(' + ', $bits) . ')';
    }
}
