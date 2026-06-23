# Per-tenant storefront themes

A storefront theme is **pure data on the tenant** — no schema change (uses the existing `settings` JSON).
It is resolved server-side into the page config and applied client-side. Every field defaults, so
**existing shops are untouched** until you opt them in.

## How it works (the integration)

1. **Resolve (server).** `StorefrontController::resolveTheme($tenant)` builds the theme from a named
   **preset** (`default` or `wholesale`) plus **per-tenant overrides** read from settings. The result is
   added to the page config as `theme` and injected into `shop.html`.
2. **Apply (client).** `applyTheme()` runs at boot:
   - sets CSS variables `--green` / `--green-d` to the brand accent → because the whole storefront themes
     off `var(--green)`, one value recolors buttons, steppers, links, focus rings, tiles, chips.
   - fills the search placeholder, tagline, **eyebrow** line, and the **trust strip** from the theme.
3. **Feature flags.** `premiumTiles` swaps cartoon-emoji category fallbacks for clean typographic tiles;
   `specChips` shows spec chips (2-Ply · 150 Sheets · A4 · 80 GSM …) parsed from each product's name/keywords.

## To add a new business theme

Either reuse a preset and override a few fields, or add a preset in `resolveTheme()` (one array entry).
Per-tenant settings (all optional):

| setting | effect |
|---|---|
| `theme` | preset name: `default` or `wholesale` |
| `theme_accent` / `theme_accent_dark` | brand colour (hex) |
| `tagline` | header subtitle |
| `search_hint` | search box placeholder |
| `eyebrow` | small-caps line under the shop name |
| `trust_line` | top trust strip; split on `·` into pills |
| `premium_tiles` | `true`/`false` — clean category fallbacks |
| `spec_chips` | `true`/`false` — product spec chips |

## Apply the premium look to Krishna Wellness (EuroPearl)

```php
php artisan tinker
$t = \App\Models\Tenant::withoutGlobalScopes()->where('name','ilike','%krishna wellness%')->first();
$t->putSetting('theme','wholesale');
$t->putSetting('theme_accent','#103A8C');
$t->putSetting('theme_accent_dark','#0C2C6B');
$t->putSetting('eyebrow','EuroPearl · Orchid · Angel Soft — Authorised Distributor');
$t->putSetting('trust_line','100% Virgin Pulp · UNBS Certified · Wholesale Trade Pricing · Kampala & Juba Delivery');
$t->putSetting('search_hint','Search paper & tissue — toilet rolls, napkins, A4, thermal…');
$t->putSetting('tagline','Wholesale paper & tissue · order on WhatsApp');
$t->save();
```

Revert anytime: `$t->putSetting('theme','default')` (and clear the overrides).

## Files
- `app/Http/Controllers/Storefront/StorefrontController.php` — `resolveTheme()` + `theme` in config.
- `resources/storefront/shop.html` — `applyTheme()`, eyebrow + trust strip, premium tile fallback, spec chips.
- `qa/storefront_theme.php` — **10/10** (preset/override resolution + chip parser).

## Deploy
Pull → restart. No migration, no new route. Then run the tinker block above to switch Krishna Wellness on.
