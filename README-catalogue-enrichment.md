# Catalogue enrichment — enriched data + brand support (additive)

Currency CONFIRMED: prices are USD. No conversion done. The CSV column is now correctly labelled
"Price (USD)" (was the misleading "Price UGX"), and the importer accepts price / price usd /
price (usd) / price ugx headers. Make sure the tenant Currency setting is USD (your live PDF already
renders USD, so it is) — everything (quotation totals, PDF, portal) follows that setting.

## 1) products-enriched.csv  (corrected + enriched, ready to import)
- 13 clean, industry-aligned CATEGORIES (Spices split into Whole Spices / Ground Spices / Recipe
  Mixes & Masalas; epoxy line separated; "Hygine" fixed; singletons merged; casing normalised;
  rows ordered food-first).
- SKUs FS-0001…FS-0191 (were blank).
- BRAND filled for 107/191.
- KEYWORDS 120 → 188 rows (added vernacular: jeera/haldi/dhania/elaichi/arhar/namkeen…).
- Header "Price (USD)"; prices/cost/stock/units unchanged.

### Apply via the panel's Replace catalogue import (owner-only, type RESTART) — it archives the
current products and imports this set. A normal import would skip all 191 as same-name duplicates.

## 2) Brand support (backend, makes the Brand column live)
- database/migrations/2026_07_16_000004_add_brand_to_products.php  (new nullable products.brand)
- app/Models/Product.php                                          (brand fillable)
- app/Http/Controllers/Storefront/StorefrontController.php        (catalogue feed emits brand)
- app/Http/Controllers/Panel/PanelApiController.php               (both CSV imports read Brand +
                                                                   USD price headers; export emits brand)

### Deploy
1. Copy these files (this PanelApiController + StorefrontController are the current versions).
2. php artisan migrate
3. Replace-import products-enriched.csv (RESTART).
4. php artisan optimize:clear
Then the storefront "Featured brands" filter lights up and brand shows on product data.
