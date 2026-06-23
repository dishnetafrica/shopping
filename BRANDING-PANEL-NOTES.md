# Website & branding — manage it from the seller panel

A new **Settings → Website & branding** card. No more tinker for theme/brand-site content.

## What the owner can now set
- **Brand colour** — 7 presets (Navy, Green, Forest, Charcoal, Maroon, Teal, Royal) **and** a custom
  colour picker / hex box. Recolours the storefront *and* the brand site. The darker shade is derived
  automatically.
- **Hero image** — upload (the "product / factory photo" box on the brand site). Wide photo recommended.
- **Tagline, eyebrow, trust strip, hero title, hero text, website URL, SEO description** — text fields.
- **FAQ editor** — add / edit / remove questions (drives both the brand-site FAQ section and the shop
  help sheet, and the FAQ JSON-LD for SEO).
- **Brand cards editor** — add / edit / remove brands (name, tagline, products, chips, accent colour)
  shown on the brand site.

Press **Save website & branding** (separate from "Save settings" because the FAQ + brand cards are
structured data sent as JSON).

## How it flows
Panel → `POST /papi/branding-save` (validates: hex colours, trims text, drops empty FAQ rows and
nameless brands) → tenant `settings`. The storefront theme (`resolveTheme`) and the brand site
(`brand()`) already read these keys, so changes show on the next page load (hard-refresh; storefront
caches ~5 min). Colours apply to every tenant; hero/eyebrow/trust/brands show on manufacturer brand sites.

## Files
- `app/Http/Controllers/Panel/PanelApiController.php` — `brandingSave()` + branding fields in the settings feed.
- `app/Http/Controllers/Storefront/StorefrontController.php` — brand site consumes `hero_image`.
- `resources/panel/seller.html` — the Website & branding card + editors + save.
- `resources/storefront/brand.html` — hero image render (cover for photo, contain for logo).
- `routes/web.php` — `POST papi/branding-save`.
- `qa/branding_save.php` — **12/12** (hex/darken/FAQ/brand normalisation).

## Deploy
Pull → restart → `php artisan optimize:clear` (new route). No migration.
Then open the seller panel → Settings → Website & branding and set the colour, hero image, FAQ and brands.
