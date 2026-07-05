# ShopBot update — Special Order + Patronics quotation + AI product knowledge

Extract at the repo root; files land in the correct paths. Then commit & push.

## New
- config/marketplace.php
- app/Services/Marketplace/**            (provider architecture: MarketplaceSearch, JumiaProvider,
                                          pricing pipeline, confidence gate, image allow-list, DTOs)
- tests/Unit/Marketplace/**              (pure unit tests)
- MARKETPLACE-SPECIAL-ORDER-NOTES.md

## Modified
- app/Services/Bot/BotBrain.php          (special-order wiring at the "we don't stock" dead-end)
- app/Services/Bot/ProductImageResponder.php  (special-order photo send)
- app/Services/Bot/QuotationService.php  (Patronics-style quotation: form code, To/quote boxes,
                                          per-line Disc %, VAT block, terms, signatures)
- app/Services/Bot/AiBrain.php           (AI PRODUCT KNOWLEDGE: full-catalogue "CATALOGUE RANGE"
                                          overview + expert-selling instruction + configurable
                                          detailed line count)
- app/Providers/AppServiceProvider.php   (marketplace provider bindings)
- app/Filament/Pages/Settings.php        (seller Special Order settings)

## AI product knowledge — what the AiBrain change does
Previously the AI prompt grounded only the first 200 products (alphabetical), so a big catalogue
read as a small one. Now the prompt ALSO includes a compact CATALOGUE RANGE overview — every
category with its item count + a few examples — covering the WHOLE catalogue for ~900 tokens.
The bot now knows its full breadth, answers "what do you sell" accurately, guides customers by
spec/part-number, suggests alternatives, and offers a special order instead of dead-ending.
Detailed exact-price lines are still capped (default 200) but configurable per tenant via
`ai_catalogue_limit` (1–600).

## Deploy — env (EasyPanel), then restart the service
    PRICE_API_URL=https://price.bentleywalkers.com
    PRICE_API_KEY=dishnet-price-2026
    MARKETPLACE_DEFAULT_PROVIDER=jumia
    MARKETPLACE_DEFAULT_MARGIN=0.15
    # and set APP_DEBUG=false

## Per-tenant settings (tinker) — Patronics (electrical wholesaler)
    $t = \App\Models\Tenant::find(PATRONICS_ID);

    // --- make the AI behave like an electrical-supply specialist ---
    $t->putSetting('vertical', 'manufacturer');   // best of the 4 verticals for a wholesaler
    $t->putSetting('ai_persona',
      'You are the sales assistant for Patronics Services (U) Ltd, a wholesale & retail supplier '.
      'of genuine Schneider Electric and industrial electrical products in Kampala, Uganda. We stock '.
      'MCBs, MCCBs, RCCBs/RCBOs, contactors, overload relays, motor protection, isolators, timers, '.
      'enclosures & distribution boards, cable management, switchgear and wiring devices. Customers '.
      'are electricians, contractors and businesses who often use part numbers (e.g. LC1D32P7, '.
      'A9F74101). Quote only from the catalogue; for items not listed, offer a special order. Prices '.
      'are UGX, VAT applies, and formal quotations are available.');
    $t->putSetting('brand_knowledge',
      'Patronics Services (U) Ltd · Plot 99, 6th Street Industrial Area, Kampala · TIN 1000038646 · '.
      'Class X Electrical Engineer & Contractors · genuine Schneider distributor · wholesale & retail.');
    $t->putSetting('ai_catalogue_limit', 400);    // optional: more exact-price lines in-prompt

    // --- Patronics quotation branding ---
    $t->putSetting('quote_form_code','PSUL-FR-F36');
    $t->putSetting('tin','1000038646');
    $t->putSetting('quote_vat_pct',18);
    $t->putSetting('quote_discount_pct',20);
    $t->putSetting('sales_employee','Amos Wakapisi');
    $t->putSetting('quote_prepared_by','Davis');
    $t->putSetting('quote_subtitle',"Class 'X' ELECTRICAL ENGINEER & CONTRACTORS\nWHOLESALER/ RETAILER: INDUSTRIAL & DOMESTIC ELECTRICAL PRODUCTS\nPLOT NO. 99, 6TH STREET INDUSTRIAL AREA- KAMPALA\nPhone: +256 750 558160");
    $t->putSetting('website','www.patronicsservices.com');
    $t->putSetting('public_email','sales.ug@patronicsservices.com');

    // --- special order (off-catalogue live pricing) ---
    $t->putSetting('special_order_enabled', true);
    $t->putSetting('special_order_margin', 0.15);
    $t->putSetting('special_order_ugx_rate', 0);  // 0 = quote UGX; set e.g. 3650 to quote USD

Note: the CATALOGUE RANGE overview is cached ~5 min per tenant; it refreshes automatically after
imports. `quote_vat_pct = 0` on non-VAT tenants hides the VAT row.
