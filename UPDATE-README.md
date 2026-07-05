# ShopBot update — Special Order (marketplace) + Patronics quotation

Extract this at the repo root; files land in the correct paths. Then commit & push.

## New files
- config/marketplace.php
- app/Services/Marketplace/**                (provider architecture: MarketplaceSearch,
  JumiaProvider, pricing pipeline, confidence, image allow-list, DTOs)
- tests/Unit/Marketplace/**                  (pure unit tests)
- MARKETPLACE-SPECIAL-ORDER-NOTES.md         (feature handover)

## Modified files
- app/Services/Bot/BotBrain.php              (special-order wiring at the "we don't stock" dead-end)
- app/Services/Bot/ProductImageResponder.php (special-order photo send)
- app/Providers/AppServiceProvider.php       (ProviderRegistry + JumiaProvider bindings)
- app/Filament/Pages/Settings.php            (seller "Special Order" settings)
- app/Services/Bot/QuotationService.php      (Patronics-style quotation: form code, To/quote boxes,
                                              per-line Disc %, VAT block, terms, signatures)

## After deploy — env (EasyPanel), then restart the service
    PRICE_API_URL=https://price.bentleywalkers.com
    PRICE_API_KEY=dishnet-price-2026
    MARKETPLACE_DEFAULT_PROVIDER=jumia
    MARKETPLACE_DEFAULT_MARGIN=0.15
    # and set APP_DEBUG=false

## Per-tenant switches (tinker)
    // special order (electronics tenants)
    $t->putSetting('special_order_enabled', true);
    $t->putSetting('special_order_ugx_rate', 3650);   // if quoting USD; 0 = quote UGX
    // Patronics quotation branding
    $t->putSetting('quote_form_code','PSUL-FR-F36');
    $t->putSetting('quote_vat_pct',18);
    $t->putSetting('quote_discount_pct',20);
    $t->putSetting('sales_employee','Amos Wakapisi');

See MARKETPLACE-SPECIAL-ORDER-NOTES.md and (for quotations) the setting list in
QuotationService.php's header comment.
