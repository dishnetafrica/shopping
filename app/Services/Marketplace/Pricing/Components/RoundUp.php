<?php

namespace App\Services\Marketplace\Pricing\Components;

use App\Services\Marketplace\Pricing\PriceBuild;
use App\Services\Marketplace\Pricing\PricingComponent;

/**
 * Final tidy: round the price UP to a whole unit (never quote a customer a
 * fraction, and never round down below cost+margin). Optionally round up to a
 * "nice" step (e.g. nearest 100 UGX) when a step is configured.
 */
final class RoundUp implements PricingComponent
{
    public function __construct(
        private int $step = 1,   // round up to the nearest multiple of $step (1 = whole unit)
    ) {}

    public function apply(PriceBuild $build): void
    {
        $step = max(1, $this->step);
        $rounded = (int) (ceil(($build->amount() - 1e-9) / $step) * $step);
        if ((float) $rounded !== $build->amount()) {
            $build->convert((float) $rounded, $build->currency(), 'Rounding', "up to nearest {$step}");
        }
    }
}
