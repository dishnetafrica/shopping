<?php

namespace App\Services\Marketplace\Pricing;

use App\Services\Marketplace\Config\MarketplaceConfig;
use App\Services\Marketplace\Pricing\Components\DutyCost;
use App\Services\Marketplace\Pricing\Components\FxConvert;
use App\Services\Marketplace\Pricing\Components\ImportCost;
use App\Services\Marketplace\Pricing\Components\Markup;
use App\Services\Marketplace\Pricing\Components\RoundUp;
use App\Services\Marketplace\Pricing\Components\ShippingCost;

/**
 * Assembles the pricing pipeline for a tenant. The ORDER encodes the landed-cost
 * model the brief asked for:
 *
 *     Marketplace price
 *        -> Shipping        (freight in)
 *        -> Import          (clearing/handling)
 *        -> Duty            (tax)
 *        -> Markup          (shop margin)   <-- only stage active by default
 *        -> FX              (base -> display currency, e.g. UGX -> USD)
 *        -> Round up
 *        = Customer price
 *
 * Today most shops run just Markup + (optional) FX + Round, because the cost
 * stages default to 0. Switching a tenant to full landed-cost pricing is purely
 * data (set the rates in settings); adding a new cost is one new component here.
 */
final class PricingPlanFactory
{
    public static function fromConfig(MarketplaceConfig $cfg): PricingPlan
    {
        return new PricingPlan([
            new ShippingCost($cfg->shippingFlat, $cfg->shippingPct),
            new ImportCost($cfg->importPct),
            new DutyCost($cfg->dutyPct),
            new Markup($cfg->margin),
            new FxConvert($cfg->ugxRate, $cfg->displayCurrency),
            new RoundUp($cfg->roundStep),
        ]);
    }
}
