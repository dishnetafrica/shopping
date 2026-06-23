# Combined deploy — brand site + theme + branding panel + factory image

One cumulative bundle. Each file is at its current (latest) state, so this brings everything to
current regardless of which earlier deltas you already pushed. Safe to deploy as one.

## What's inside (everything from the recent arc)
- **Manufacturer business type + brand site** — master-admin "Manufacturer" type; `/{shop}` serves the
  brand site, `/{shop}/shop` the storefront. One cart/checkout (brand-site buttons deep-link into the shop).
- **Storefront theme engine** — per-tenant accent colour recolours the shop; eyebrow + trust strip,
  premium category tiles, spec chips.
- **FAQ + SEO** — brand-site FAQ accordion, shop help sheet, server-rendered title/description/OG/Twitter
  + JSON-LD (Organization + FAQPage).
- **Logo fix** — brand site renders the logo (header + hero), graceful initials fallback.
- **Website & branding panel** — Settings card: brand colour (presets + custom), hero image, factory
  image, tagline/eyebrow/trust/hero title+text/website/SEO text, FAQ editor, brand-cards editor.
- **Manufacturer default text** — shared `BrandDefaults` pre-fills the panel (and brand-site fallback)
  for manufacturer tenants instead of blanks.
- **Factory image** — second upload for the "Our Factory" section.

## Files
- `app/Support/Vertical.php`, `app/Support/BrandDefaults.php`
- `app/Filament/Admin/Resources/TenantResource.php`
- `app/Http/Controllers/Storefront/StorefrontController.php`
- `app/Http/Controllers/Panel/PanelApiController.php`
- `routes/web.php`
- `resources/storefront/shop.html`, `resources/storefront/brand.html`, `resources/panel/seller.html`
- `qa/manufacturer_brandsite.php`, `qa/storefront_theme.php`, `qa/brandsite_seo.php`, `qa/branding_save.php`

## Deploy
1. Pull on EasyPanel → restart.
2. `php artisan optimize:clear`  ← **required** (new routes: landing/shop, branding-save, delete-product).
3. Migration: only `2026_06_23_000001_add_wholesale_units_to_products` — run `php artisan migrate`
   **only if you haven't already** (you applied it earlier when you set MOQ / imported, so likely a no-op).
4. Open the seller panel → Settings → **Website & branding** to set colour, hero + factory images,
   text, FAQ and brand cards.

## QA (all green)
manufacturer_brandsite 16 · storefront_theme 10 · brandsite_seo 12 · branding_save 12 · wholesale_units 17 · import_merge_keep 9 · storefront_departments 11 · vertical_visibility 25

## Caveats
- Storefront/brand site caches ~5 min — hard-refresh after saving.
- Colour applies to every tenant; hero/factory/eyebrow/trust/brand-cards + default text only show on
  manufacturer brand sites.
- Hero/factory images: upload real photos in the panel (placeholders show until you do).
