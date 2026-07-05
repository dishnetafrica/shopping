<?php

namespace App\Services\Marketplace\Pricing;

/**
 * One stage in the price build-up. Stages are composed in order by PricingPlan.
 *
 * Today the live chain is: Markup -> FxConvert -> RoundUp. Shipping / Import /
 * Duty stages exist and sit in the chain already but are no-ops until a tenant
 * supplies rates — so turning on true landed-cost pricing tomorrow is a config
 * change, not an architecture change. Adding a brand-new cost = a new class
 * implementing this interface, slotted into PricingPlanFactory.
 */
interface PricingComponent
{
    public function apply(PriceBuild $build): void;
}
