# CloudBSS — Storefront Department Ordering (food-first)

**What this does:** the storefront sidebar/department list now follows the
catalogue's `Category.sort` order (food departments first, non-food lines like
"Flooring & Paint" and "Non-Food & General" last) instead of raw product count.

## Why
The catalogue restructure produced a clean 13-department taxonomy. A pure
product-count sort floated "Disposables, Foil & Wraps" (24) and could push the
industrial line above food staples — wrong for a food wholesaler. The importer
already stamps `Category.sort` in CSV row order, and the food-first
`products-enriched.csv` is authored food-first, so the order is data-driven — no
hardcoded list.

## Files (additive, drop-in)
1. `app/Http/Controllers/Storefront/StorefrontController.php`
   - catalogue() settings now emits `category_order`: Category names ordered by
     `sort` then `name` for the tenant.
2. `resources/storefront/shop.html`
   - new global `CATORDER`, read from `d.settings.category_order`.
   - CATS sort honours CATORDER first, then falls back to product count.

## Backward compatibility
If a tenant has no Category `sort` data, `category_order` is empty and every
category maps to index 9999 → the sort falls back to the original
product-count order. Existing storefronts are unaffected until they re-import
the food-first CSV.

## Deploy
1. Push both files.
2. (Per tenant) re-import `products-enriched.csv` (food-first row order) so the
   Category `sort` reflects the food-first taxonomy. Categories are created via
   `firstOrCreate` — existing ones keep their current sort unless reseeded; to
   fully reorder an existing tenant, either re-import into a fresh category set
   or update `categories.sort` to match the CSV row order.
3. Storefront config is `Cache-Control: no-store`; catalogue feed caches 5 min —
   order updates within 5 minutes.

Verified: `php -l` clean, shop.html JS compiles, no backend logic changed.
