# Merchant + Weight (build 2026.06.21-mw2) — deploy & update pattern

## Repeatable update pattern (same every time)
1. Edit code in this repo.
2. Commit/push to GitHub `dishnetafrica/shopping` (root-level paths preserved).
3. EasyPanel: pull → **restart container** (required: opcache.validate_timestamps=Off).
4. `php artisan migrate` (if new migrations) → `php artisan catalogue:flush`.
5. Verify: send `version` to the bot → expect `2026.06.21-mw2` (bump VERSION in
   app/Services/Bot/BotBrain.php on every code change so you can confirm the deploy landed).

## What lives where (persistence)
- **Code** (this repo) — persists via GitHub; redeploy replaces it.
- **DB** (Postgres) — migrations, product flags (`sold_by_weight`, `reference_price`),
  merchant phone authorization, daily_state: all persist across deploys.
- **verify_live.php** — committed here so it deploys with the app and is NOT wiped.
  If your pull only syncs `app/`, copy verify_live.php into a deployed path or recreate it.

## Verify after any deploy (in the container, Laravel root)
    php verify_live.php            # read-only: catalogue probe + customer cart + merchant ChangeSets
    php verify_live.php --write     # apply -> undo round-trip (needs an authorized merchant phone)
Uses VERIFY_SLUG (default palssnack). For Pal's: `VERIFY_SLUG=pals php verify_live.php`.

## Weight-pricing activation (data, done once per product, persists)
Set on loose products: `sold_by_weight=true`, `reference_weight_grams=1000`,
`reference_price=<per-kg price>`. Merchants can also set/correct prices from WhatsApp
(e.g. `Fafda 1kg 35000`). 5 Kg bagged items stay as count units.

## QA (framework-free, runnable with bare php)
    php qa/weight_pricing.php   php qa/weight_surface.php   php qa/merchant_parser.php
    php qa/merchant_realworld.php   php qa/merchant_services.php   php qa/final_report.php
