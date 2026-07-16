# CloudBSS — Storefront Merchandising, complete bundle (v3 + storefront + backend)

One clean deploy for the whole feature: **Shop for Every Need** (collection
merchandising) and **Ready-made Kits** (one-tap bundle → quotation), configurable
from **v3** (`mycloudbss.com/v3`), rendered on the storefront, backed by tenant
settings. Additive, no engine/ERP/quotation redesign, no migrations, no new
storage (reuses `/papi/upload-image`).

## Files (6)
1. `app/Http/Controllers/Storefront/StorefrontController.php` — emits
   `business_banners` + `kits` in the catalogue feed.
2. `app/Http/Controllers/Panel/PanelApiController.php` — `bannersGet/bannersSave`,
   `kitsGet/kitsSave` (whitelisted, size-capped, stored in tenant settings).
3. `routes/web.php` — authed `/papi` routes for the above.
4. `resources/storefront/shop.html` — storefront rendering: collection cards
   ("Shop for Every Need"), auto-scroll heroes, per-collection best-sellers,
   bulk matching, gentle device personalization, and kit cards with one-tap
   add-to-quote (MOQ-aware). Renders only when enabled.
5. `resources/views/shell.blade.php` — **v3 admin**: two new Marketing items,
   **Shop for Every Need** and **Ready-made Kits**, native editors (photo upload,
   starter template, save) wired to the same `/papi` endpoints via the app's own
   `post()`/`getQuery()` (session-authed, CSRF).
6. `resources/panel/seller.html` — the classic `/panel` also has the same editors
   under a tabbed **Storefront** menu (optional; kept in sync so either admin works).

## Where it lives in v3
Sidebar → **Marketing** → **Shop for Every Need** / **Ready-made Kits**
(alongside Storefront Insights). Both are always available to the owner — they're
client-rendered pages, not server workspaces, so they don't depend on capability
flags.

## Turn it on (v3)
1. Deploy all 6 files.
2. v3 → **Shop for Every Need** → **Load starter template** (7 collections mapped
   to your categories) → adjust keywords, upload a card + hero photo per
   collection (optional; gradient shows until then) → tick **Show on storefront**
   → **Save**.
3. v3 → **Ready-made Kits** → **+ Add kit** → name it, pick a collection (or all),
   add products by name (autocompletes from your catalogue) with quantities →
   **Save kits**.
4. Storefront reflects it within ~5 min (feed cache).

## Notes
- A collection's best-seller rail and a kit render only when ≥ enough real
  products match (kit needs ≥2 available items), so nothing shows broken.
- Kit lines honour each product's MOQ; unavailable lines are skipped at add time.
- Everything is session-authed & tenant-scoped server-side; the v3 app passes no
  tenant id — the backend resolves it from the session, same as every other page.

Verified: `php -l` clean; shop.html, seller.html and shell.blade.php scripts all
compile; Playwright — v3 renders both editor pages, loads the 7-collection
starter, and Save posts correct `business_banners` + `kits` payloads (bulk match,
title, kit collection & MOQ qty all intact); storefront renders/switches/kits/
personalization verified earlier; classic panel tabbed Storefront verified. No
console errors.
