# Manufacturer brand site + business type + quick-order

## 1. New business type in master login
Added **Manufacturer / wholesale brand** as a vertical. It now appears automatically in the
master admin (Create business → **Business type**), because that select is bound to `Vertical::LABELS`.
Manufacturer gets riders + POS like a selling business; Restaurant/Snacks tools stay off.

## 2. Brand site (config-driven, same pattern as the theme)
- `/{shop}` now serves the **brand site** when the tenant is a Manufacturer (or `brand_site` setting = true).
  Everyone else gets the shop exactly as before.
- The shop always lives at **`/{shop}/shop`** — brand-site order links point there.
- Content comes from tenant settings with sensible defaults (no schema change):
  `hero_title`, `hero_text`, `website`, `public_phone`, `public_email`, `address`, `brands` (array),
  `brand_stats` (array). Colours/eyebrow/trust come from the existing theme settings.
- The page pulls **live products** from the catalogue: a featured row + category cards.

## 3. Order from the site → one cart
Every "Add to order" links to `/{shop}/shop?add=<product>` and every category to `?cat=<category>`.
The shop reads these on load: `?add` drops the item in the cart (MOQ-aware) and opens checkout;
`?cat` jumps to that aisle. **One cart, one checkout** — no second ordering engine.
The distributor form sends a pre-filled enquiry to the business WhatsApp.

## 4. Website setting
Master admin → Settings → **Website URL**. If set, it shows as "Visit our website" on the brand site.

## Files
- `app/Support/Vertical.php` — MANUFACTURER type (+ riders/pos, label).
- `app/Filament/Admin/Resources/TenantResource.php` — Website field + business-type helper.
- `app/Http/Controllers/Storefront/StorefrontController.php` — `landing()`, `brand()`, `brandSiteEnabled()`.
- `resources/storefront/brand.html` — the brand-site template (`__BRAND_CONFIG__`).
- `resources/storefront/shop.html` — `applyQuickOrder()` (?add / ?cat).
- `routes/web.php` — `/{shop}` → landing, `/{shop}/shop` → shop.
- `qa/manufacturer_brandsite.php` — **16/16**.

## Deploy
```
pull → restart → php artisan optimize:clear      # new routes
```
No migration. Then make Krishna Wellness a manufacturer:
```php
php artisan tinker --execute="
\$t=\App\Models\Tenant::withoutGlobalScopes()->where('name','ilike','%krishna wellness%')->first();
\$t->putSetting('vertical','manufacturer');     // brand site at /ep, shop at /ep/shop
\$t->putSetting('website','https://europearlafrica.com');
\$t->putSetting('public_phone','+256 752 345 935');
\$t->putSetting('public_email','krishnawellness2024@gmail.com');
\$t->putSetting('address','Namanve Industrial Park, Kampala, Uganda');
\$t->save();
echo 'done'.PHP_EOL;
"
```
After this, `mycloudbss.com/ep` = the brand site, `mycloudbss.com/ep/shop` = the shop.
Revert: `\$t->putSetting('vertical','grocery')` (or set `brand_site` false).

## QA sweep
manufacturer_brandsite 16 · vertical_visibility 25 · storefront_theme 10 · wholesale_units 17 · import_merge_keep 9 · storefront_departments 11 — all green.
