# Catalogue enrichment — data + brand support (additive)

## 1) products-enriched.csv  (the corrected, enriched catalogue)
Your 191 products, ready to import, with:
- 13 clean, industry-aligned CATEGORIES (was 17 messy; "Spices & Masala" split into Whole Spices /
  Ground Spices / Recipe Mixes & Masalas; epoxy line separated as "Flooring & Paint (Industrial)";
  "Hygine" typo fixed; singletons merged; casing normalised).
- SKUs FS-0001…FS-0191 (were blank) — enables copy-SKU, barcode matching, ERP + stock-by-SKU sync.
- BRAND column filled for 107/191 (Shan, Everest, Hathi, Badshah, Galaxy Pack, 5 Star, Zesta…).
- KEYWORDS enriched 120 → 188 rows (added vernacular: jeera, haldi, dhania, elaichi, arhar/tuvar,
  namkeen/farsan, etc.) for better search.
- Rows ordered food-first so the department sort reads sensibly.
- Prices/Cost/Stock/Unit/Pack/MOQ/Image are UNCHANGED (currency not converted — see note below).

### How to apply
The importer skips same-name duplicates, so apply via the panel's **Replace catalogue** import
(owner-only; type RESTART to confirm) — it archives the current products and imports this enriched
set. (A normal import would skip all 191 as duplicates.)

## 2) Brand support (backend) — makes the Brand column live
Previously products had no brand field, so the storefront "Featured brands" filter and the quotation
PDF brand column had nothing to show. This patch adds it, additively:
- database/migrations/2026_07_16_000004_add_brand_to_products.php   (new nullable products.brand)
- app/Models/Product.php                                           (brand fillable)
- app/Http/Controllers/Storefront/StorefrontController.php         (catalogue feed emits brand)
- app/Http/Controllers/Panel/PanelApiController.php                (both CSV imports read a Brand column; export emits it)

### Deploy
1. Copy these files (this StorefrontController is the current version — includes the quotation work).
2. php artisan migrate
3. Re-import products-enriched.csv (Replace/RESTART) so brand + new categories + SKUs land.
4. php artisan optimize:clear

Result: the storefront "Featured brands" filter lights up, brand shows on product data, and the
catalogue export now round-trips brand.

## Currency note (needs your call)
The price column is headed "Price UGX" but the values (0.86–155) and the live PDF render USD. I did
NOT convert — tell me if pricing should be UGX and I'll convert the column and re-issue the CSV.
