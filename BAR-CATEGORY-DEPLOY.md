# Merge spirit/wine categories into one "Bar" category (tg)

Deployable version of the bar-consolidation. It is a one-time migration that runs on
deploy — no console scripting needed.

## What it does (tg shop only, runs once)
1. Moves every product in these categories into a single "Bar" category:
   Scotch Whisky, Bourbon & Irish Whisky, Single Malt, Cognac & Brandy, Gin, Rum,
   Liqueur, Vodka, Tequila, Wine By Glass, Aperitifs, Champagne & Sparkling Wine,
   White Wine, Red Wine.
2. Updates the shop's menu groups so the Beverages tab shows ONE "Bar" tile instead of 14.
Non-alcohol drinks (Coffee, Tea, Juice, Soft Drinks, Shakes...) stay as their own tiles.
Other shops are untouched.

## File
- database/migrations/2026_06_25_000001_consolidate_bar_categories_tg.php

## BEFORE you deploy
Export your tg products CSV from the panel as a backup — this replaces the per-product
sub-categories (Gin, Vodka, ...) with "Bar" and cannot be auto-reverted.

## Change the category name (optional)
Open the migration and edit `$NEW = 'Bar';` to 'Drinks', 'Wine & Spirits', etc. before deploying.

## Deploy
1. Unzip at the repo ROOT (adds the one migration file).
2. git add -A && git commit -m "consolidate bar categories for tg" && git push
3. EasyPanel rebuild.
4. Run migrations (one of):
   - if your deploy already runs them, nothing to do;
   - otherwise in the shopping console:  php artisan migrate --force
5. php artisan optimize:clear
6. Hard-refresh /tg -> Beverages Menu -> one "Bar" tile.

## Verify
   php artisan tinker --execute="echo \DB::table('products')->where('tenant_id',(\DB::table('tenants')->where('slug','tg')->value('id')))->where('category','Bar')->count();"
A number > 0 means the products were merged.

## Rollback
Migrations run once and are tracked, so it won't re-run. To revert the data, re-import your
backup CSV (merge mode). The migration's down() is a no-op by design.
