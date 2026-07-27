# EA Auto Part — Auto Parts vertical (full patch)

Makes the "Auto Parts / EPC catalogue" business type selectable in Operator AND
serves the genuine-parts storefront at the tenant's web address `/{shop}`.
The shop (cart/checkout) still lives at `/{shop}/shop` — one cart, one checkout,
exactly like the Manufacturer brand site.

## Files in this patch (same paths as your repo)

1. `app/Support/Vertical.php`
     Complete drop-in. ADDITIVE ONLY — adds the `auto_parts` type + label.
     Existing types (grocery, restaurant, snacks, manufacturer, solar) are
     unchanged. Safe to replace wholesale.

2. `app/Http/Controllers/Storefront/StorefrontController.php`
     Adds 3 things (each marked with `// AUTO_PARTS` / `// >>> AUTO_PARTS`):
       - an auto_parts branch in `landing()` and `rootLanding()`
       - `autoPartsEnabled()`  (mirrors `brandSiteEnabled()`)
       - `autoParts()`         (mirrors `brand()`)
     ⚠️ This file is based on the version in your shared codebase. If your LIVE
     controller has changed since, DON'T overwrite it — instead open your live
     file and paste just the 3 blocks marked `AUTO_PARTS` (search the file for
     that word). If your live file matches, you can replace it wholesale.

3. `resources/storefront/autoparts.html`   (NEW file)
     The storefront template. `autoParts()` injects `__AUTOPARTS_CONFIG__`
     (brand, theme colour, hero copy, models, systems) — same mechanism as
     `brand.html`’s `__BRAND_CONFIG__`.

## Upload on GitHub

Repo home → Add file → Upload files → drag the `app` and `resources` folders
from this zip. GitHub places each file at its matching path and offers to commit
the changes.

## Deploy on the server

    git pull
    php artisan optimize:clear
    # restart the app

## Enable for EA Auto Part

Operator → Businesses → Edit EA Auto Part (tenant 20):
  - Business type → **Auto Parts / EPC catalogue**
  - (optional) set theme colour, hero title, tagline, WhatsApp number, logo
  - Save

Now `mycloudbss.com/<ea-slug>` serves the parts storefront; `…/<ea-slug>/shop`
is the cart. Selecting the type affects ONLY this tenant.

## Optional per-tenant settings (all have sensible defaults)
  hero_title, tagline (hero text), theme_accent, theme_accent_dark, logo,
  public_phone, public_email, website, epc_models, epc_systems

## Rollback
Set the tenant’s Business type back to its previous value, or set setting
`auto_parts_site = false`. No data changes.
