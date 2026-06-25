# Custom-domain root now respects brand sites

Single file changed: `app/Http/Controllers/Marketing/MarketingController.php`
(independent of the earlier storefront/shop.html ZIPs — this only touches the controller).

## What changed
When a request arrives on a shop's own custom domain, the root used to always serve the
**shop** storefront (`show()`). It now calls **`landing()`**, the same handler the slug
URL (`mycloudbss.com/{slug}`) uses. So the custom-domain root now matches the slug:

- Manufacturer / brand-site shops (e.g. EuroPearl, slug `ep`) → their **brand site**.
  → `europearlafrica.com` now shows the Krishna Wellness brand site, same as mycloudbss.com/ep.
- All other shops (palssnack.com, familyshoppers.net, thegreatindiandhabaa.com, etc.)
  → `landing()` falls through to `show()` → the **shop**, exactly as before. No change.

Brand-site links (Order / Products / Wholesale) resolve to `/{slug}/shop` etc. on the
same custom domain, so ordering keeps working from the brand site.

## Scope / safety
- One controller method, one line of behaviour. No DB, no migration, no new settings.
- Whether a shop is a "brand site" is decided by the existing `brandSiteEnabled()` logic
  (manufacturer vertical, or the `brand_site` setting) — unchanged.

## Deploy
1. Unzip at the repo root (overwrites the one controller file).
2. Commit + push → EasyPanel rebuild.
3. After deploy run `php artisan optimize:clear` (or let the container entrypoint do it).
4. Visit `https://europearlafrica.com` → brand site. Confirm a non-manufacturer custom
   domain (e.g. palssnack.com) still opens its shop.

Prerequisite unchanged: europearlafrica.com (and www) DNS A-record must point to the server.

## Rollback
Restore the previous MarketingController.php (change `landing(` back to `show(`) and redeploy.
