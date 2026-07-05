<?php

namespace App\Services\Marketplace\Pricing\Components;

use App\Services\Marketplace\Pricing\PriceBuild;
use App\Services\Marketplace\Pricing\PricingComponent;

/**
 * Converts the running amount from the marketplace's base currency into the
 * currency the shop quotes in. DishNet quotes USD (rate set, e.g. 3600 UGX/USD);
 * most CloudBSS shops quote UGX (rate = 0 -> no-op, stays UGX-native).
 *
 * Applied AFTER all cost + markup stages, so it's a pure display conversion of
 * the final price — never mixed into the cost maths.
 */
final class FxConvert implements PricingComponent
{
    public function __construct(
        private int $baseToDisplayRate = 0,   // units of BASE currency per 1 DISPLAY unit (e.g. 3600 UGX = 1 USD). 0 = no conversion
        private string $displayCurrency = 'USD',
    ) {}

    public function apply(PriceBuild $build): void
    {
        if ($this->baseToDisplayRate <= 0) {
            return;                              // UGX-native shop: quote in base currency
        }
        $converted = $build->amount() / $this->baseToDisplayRate;
        $build->convert(
            $converted,
            $this->displayCurrency,
            'FX',
            "{$this->baseToDisplayRate} {$build->currency()} = 1 {$this->displayCurrency}",
        );
    }
}
