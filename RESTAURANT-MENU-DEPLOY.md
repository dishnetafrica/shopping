# Restaurant menus (tg) — upload & run

This ZIP makes the Food | Beverages tabs work for the dhaba. Unzip at the repo root, commit, push,
let EasyPanel pull. Then run ONE command.

## Files (root-level paths)
- `app/Console/Commands/RestaurantSetupMenusCommand.php` — the setup command (NEW).
- `resources/storefront/shop.html` — the storefront with Food/Beverages tabs + category-image fallback
  (include this if you haven't already deployed it).

## After EasyPanel pulls
```
php artisan restaurant:setup-menus tg
php artisan optimize:clear
```
That sets `tg` to restaurant mode and builds the two menus from its product categories (auto-split:
food vs the full bar). It only writes tenant settings — **never touches products, prices or images.**

Then **hard-refresh `/tg`** → you'll see **Food Menu | Beverages Menu** tabs.

## Order matters
Import the drinks CSV FIRST (you've done this), THEN run the command — the menu tabs only show
categories that have products.

## Verify
The command prints the split. Expect ~23 food + ~23 beverage categories. If any category lands on the
wrong tab, fix it in the seller panel → Settings → Website & branding → **Menus**, or re-run the command.

## Notes
- Re-runnable any time (idempotent).
- Custom menu names if you want: `php artisan restaurant:setup-menus tg --food-name="Kitchen" --bev-name="Bar"`
- Images are safe — this command writes only settings; the drinks import is non-destructive (blank image
  cells keep existing photos).
