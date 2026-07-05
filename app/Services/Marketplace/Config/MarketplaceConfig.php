<?php

namespace App\Services\Marketplace\Config;

use App\Models\Tenant;

/**
 * A tenant's resolved special-order configuration, as a plain immutable value
 * object. Building this once (from tenant settings + global config) keeps the
 * rest of the marketplace layer free of Eloquent and facades, which is what
 * makes it straightforward to unit-test.
 *
 * Per-tenant settings (settings JSON on the Tenant model):
 *   special_order_enabled   bool    master on/off
 *   special_order_margin    float   markup fraction (0.15 = +15%)
 *   special_order_ugx_rate  int     UGX per 1 display unit; 0 = quote UGX-native
 *   special_order_currency  string  display currency override (else derived)
 *   special_order_terms     string  the "special order" terms line
 *   special_order_provider  string  provider key (else global default)
 *   special_order_shipping_flat / _shipping_pct / _import_pct / _duty_pct
 *                                   optional landed-cost rates (default 0)
 */
final class MarketplaceConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $providerKey,
        public readonly float $margin,
        public readonly int $ugxRate,           // 0 = UGX-native, else UGX per 1 display unit
        public readonly string $displayCurrency,
        public readonly string $terms,
        public readonly float $shippingFlat,
        public readonly float $shippingPct,
        public readonly float $importPct,
        public readonly float $dutyPct,
        public readonly int $roundStep,
        public readonly float $confidenceHigh,
        public readonly float $confidenceMedium,
        public readonly int $maxOptions,
        /** @var array<string> */
        public readonly array $allowedImageHosts,
    ) {}

    public static function fromTenant(Tenant $tenant): self
    {
        $enabled = (bool) $tenant->setting('special_order_enabled', false);
        $ugxRate = (int) $tenant->setting('special_order_ugx_rate', 0);

        // Display currency: explicit override wins; else USD when a rate is set
        // (DishNet-style), otherwise the shop's own currency (UGX-native shops).
        $display = (string) $tenant->setting('special_order_currency', '');
        if ($display === '') {
            $display = $ugxRate > 0 ? 'USD' : (string) $tenant->setting('currency', 'UGX');
        }

        return new self(
            enabled: $enabled,
            providerKey: (string) $tenant->setting('special_order_provider', (string) config('marketplace.default_provider', 'jumia')),
            margin: (float) $tenant->setting('special_order_margin', (float) config('marketplace.default_margin', 0.15)),
            ugxRate: $ugxRate,
            displayCurrency: $display ?: 'UGX',
            terms: (string) ($tenant->setting('special_order_terms', '') ?: config('marketplace.default_terms', '')),
            shippingFlat: (float) $tenant->setting('special_order_shipping_flat', 0.0),
            shippingPct: (float) $tenant->setting('special_order_shipping_pct', 0.0),
            importPct: (float) $tenant->setting('special_order_import_pct', 0.0),
            dutyPct: (float) $tenant->setting('special_order_duty_pct', 0.0),
            roundStep: (int) $tenant->setting('special_order_round_step', 1),
            confidenceHigh: (float) config('marketplace.confidence.high', 0.60),
            confidenceMedium: (float) config('marketplace.confidence.medium', 0.30),
            maxOptions: (int) config('marketplace.max_options', 3),
            allowedImageHosts: (array) config('marketplace.allowed_image_hosts', []),
        );
    }
}
