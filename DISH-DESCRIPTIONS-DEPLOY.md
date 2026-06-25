# Show dish descriptions on the menu

Adds a short description line under each dish (name → description → price) on the
storefront menu cards, for both single dishes and multi-size dishes.

## Files (2)
- app/Http/Controllers/Storefront/StorefrontController.php  — the menu feed now sends each
  product's `description`.
- resources/storefront/shop.html  — cards now render the description (2-line clamp, muted),
  and the design stays clean when a dish has no description (nothing shows).
  (This shop.html is cumulative: it also includes the category-first menu and restaurant FAQ.)

## IMPORTANT — descriptions only show if the dishes HAVE description text
Your current dishes were imported from a CSV with no Description column, so descriptions are
empty. After deploying this, you still need to add description text. Two ways:

A) Edit a dish in the seller panel and fill its Description field.
B) Bulk: import a CSV that has a "Description" column, with "Replace the whole catalogue" OFF
   (merge mode). Merge updates the description by product name and does NOT touch photos,
   prices or anything you leave blank.
   Minimum columns for that CSV: "Product Name" and "Description".

## Deploy
1. Unzip at the repo ROOT (overwrites the 2 files).
2. Commit + push -> EasyPanel rebuild.
3. php artisan optimize:clear
4. Hard-refresh /tg, open a category — dishes with a description now show it.

## Rollback
Restore the 2 files from the previous version and redeploy.
