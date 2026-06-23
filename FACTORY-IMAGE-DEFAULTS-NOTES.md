# Factory image + manufacturer default text

## Two changes
1. **Manufacturer text now defaults instead of showing blank.** A single shared source
   (`app/Support/BrandDefaults.php`) supplies the tagline, eyebrow, trust strip, hero title/text,
   FAQ and brand cards. The brand site falls back to it, and the **seller panel pre-fills these**
   for manufacturer businesses — so the owner edits real text rather than empty boxes. Any saved
   value still overrides the default. (Non-manufacturer tenants stay blank — defaults don't apply.)

2. **Second image: Factory image.** There are now two uploads in Settings → Website & branding:
   - **Hero image** → the big panel at the top (cover-fit).
   - **Factory image** → the photo in the "Our Factory" section.
   Both upload via the existing image pipeline and save with "Save website & branding".

## Files
- `app/Support/BrandDefaults.php` — NEW shared defaults (text, faq, brands, stats).
- `app/Http/Controllers/Storefront/StorefrontController.php` — brand() uses BrandDefaults + `factory_image`.
- `app/Http/Controllers/Panel/PanelApiController.php` — feed pre-fills manufacturer defaults; saves `factory_image`.
- `resources/panel/seller.html` — Factory image upload + init/save.
- `resources/storefront/brand.html` — renders the factory image.

## Deploy
Pull → restart → `php artisan optimize:clear`. No migration.
Open Settings → Website & branding: the text fields already show the manufacturer defaults; upload a
hero photo and a factory photo, then Save website & branding.
