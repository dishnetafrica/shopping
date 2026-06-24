# CloudBSS — cumulative changes (upload to GitHub: dishnetafrica/shopping)

Root-level paths — unzip at the repo root so files land in place, commit, push, let EasyPanel pull.

## After pulling on the server
- `php artisan migrate`            (only if not already applied — the wholesale_units migration)
- `php artisan optimize:clear`
- `php artisan queue:restart`
- For PDF quotations: ensure `dompdf/dompdf` is in composer.json (NOT in this zip — add + commit separately;
  on PHP 8.3 use `composer require dompdf/dompdf "symfony/css-selector:^7.0" -W`)

## What's inside (by feature)
- **Native AI bot** — AiBrain.php (persona/knowledge, voice, vision, fallback, multilingual, combos, menus, jumbo),
  ProcessIncomingMessage.php (ai branch), BrandDefaults.php (manufacturer persona + knowledge + chemicals + jumbo).
- **Deterministic totals + PDF quotations** — OrderCalculator.php, QuotationService.php, EvolutionGateway.php (sendDocument).
- **Combo offers** — Combos.php, StorefrontController.php, PanelApiController.php, shop.html, seller.html.
- **Price-on-request (jumbo)** — AiBrain/OrderCalculator/BrandDefaults + shop.html (Price-on-request cards).
- **Restaurant two menus** — shop.html (Food/Beverages tabs), seller.html (Menus + Menu-files editors),
  PanelApiController.php, AiBrain.php (menu-file send).
- **Category-image fallback** — shop.html (tiles never blank).
- **Admin fields** — TenantResource.php (smart bot, quote validity/terms, fallback text).
- **n8n bridge (optional mode)** — BotBridgeController.php, BotWatchdogCommand.php, BotDigestCommand.php, routes.
- **Catalogue** — ProductImporter.php (non-destructive import), Product.php, Vertical.php.
- **brand.html** — manufacturer brand site.
- **qa/** — test mirrors (safe to keep or omit; not used at runtime).

## Files
- app/Console/Commands/BotDigestCommand.php
- app/Console/Commands/BotWatchdogCommand.php
- app/Filament/Admin/Resources/TenantResource.php
- app/Http/Controllers/Api/BotBridgeController.php
- app/Http/Controllers/Panel/PanelApiController.php
- app/Http/Controllers/Storefront/StorefrontController.php
- app/Jobs/ProcessIncomingMessage.php
- app/Models/Product.php
- app/Services/Bot/AiBrain.php
- app/Services/Bot/OrderCalculator.php
- app/Services/Bot/QuotationService.php
- app/Services/Catalogue/ProductImporter.php
- app/Services/WhatsApp/EvolutionGateway.php
- app/Support/BrandDefaults.php
- app/Support/Combos.php
- app/Support/Vertical.php
- database/migrations/2026_06_23_000001_add_wholesale_units_to_products.php
- resources/panel/seller.html
- resources/storefront/brand.html
- resources/storefront/shop.html
- routes/api.php
- routes/console.php
- routes/web.php
- (qa/ test files):
  - qa/ai_brain.php
  - qa/ai_production.php
  - qa/branding_save.php
  - qa/brandsite_seo.php
  - qa/combos.php
  - qa/import_merge_keep.php
  - qa/manufacturer_brandsite.php
  - qa/menus.php
  - qa/multilingual.php
  - qa/n8n_bridge.php
  - qa/n8n_p2.php
  - qa/order_calc.php
  - qa/price_on_request.php
  - qa/quotation.php
  - qa/storefront_theme.php
  - qa/wholesale_units.php
