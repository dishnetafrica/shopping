# Auto Parts vertical (EPC catalogue storefront)

Adds **Auto Parts / EPC catalogue** as a CloudBSS vertical. Selecting it as a
tenant's Business type serves the genuine-parts front UI (models → 360° explorer
→ systems → exploded diagrams → part detail → AI diagnosis) at `/{shop}`, exactly
the way `manufacturer` serves the brand site. **Config-driven, no schema change** —
everything reads from the tenant `settings` JSON, so existing tenants are untouched.

Same three moving parts as the manufacturer brand site:

1. **Vertical** — one entry in `App\Support\Vertical` so it appears in the master
   admin *Business type* select (that select is bound to `Vertical::LABELS`).
2. **Storefront branch** — `StorefrontController::landing()` serves the auto-parts
   template when the tenant's vertical is `auto_parts` (or `auto_parts_site` = true).
   The shop still lives at `/{shop}/shop`; part pages deep-link into it.
3. **Template** — `resources/storefront/autoparts.html`, whose `__AUTOPARTS_CONFIG__`
   is filled from tenant settings + the live catalogue. The reference build in this
   kit (`build/generate.py` + `design-system.json` + `/data`) is that template's
   source of truth; the tenant config maps 1:1 to `data/tenant.json`.

---

## 1. Vertical — `app/Support/Vertical.php`

See `Vertical.php.patch`. In short:

```php
public const AUTO_PARTS = 'auto_parts';

public const ALL = [self::GROCERY, self::RESTAURANT, self::SNACKS,
                    self::MANUFACTURER, self::SOLAR, self::AUTO_PARTS];

public const MATRIX = [
    // …existing…
    'riders' => [self::GROCERY, self::RESTAURANT, self::MANUFACTURER, self::SOLAR, self::AUTO_PARTS],
    'pos'    => [self::GROCERY, self::RESTAURANT, self::MANUFACTURER, self::SOLAR, self::AUTO_PARTS],
];

public const LABELS = [
    // …existing…
    self::AUTO_PARTS => 'Auto Parts / EPC catalogue',
];
```

Auto Parts behaves like a selling business (riders + POS on; restaurant/snacks
tools off). Every gated feature still defaults OFF for a vertical that does not
list it, so nothing leaks into other verticals.

## 2. Storefront — `app/Http/Controllers/Storefront/StorefrontController.php`

See `StorefrontController-autoparts.snippet.php`. Add `autoPartsEnabled()`, a
branch in `landing()`, and `autoParts()` (mirrors `brand()`), plus
`resolveAutoPartsConfig()` (mirrors `resolveTheme()`). No new route: `/{shop}` →
`landing()` already exists and just gains one branch.

## 3. Template — `resources/storefront/autoparts.html`

Build it from this kit: `python3 build/generate.py` emits the config-driven HTML.
`resolveAutoPartsConfig()` produces the same shape as `data/tenant.json`
(brand, theme accent, market/currency, enabled models, hero copy) and is injected
as `__AUTOPARTS_CONFIG__`. Model / system / part / diagram data comes from the
catalogue (`WwItem` + the EPC tables), so each tenant shows **their** stock.

Images use the existing upload path: every image is an upload slot keyed like
`boxer100:studio_right`, `system:engine`, `product:<id>:main`. The seller/admin
panel writes `image_url` (CDN or `/storage/...`) into the slot exactly as
Family Shoppers does; empty slots fall back to bundled defaults.

---

## Turn a tenant into an Auto Parts store

```php
php artisan tinker --execute="
\$t = \App\Models\Tenant::withoutGlobalScopes()->where('name','ilike','%bajaj%')->first();
\$t->putSetting('vertical','auto_parts');          // EPC storefront at /{shop}
\$t->putSetting('theme_accent','#1552CC');
\$t->putSetting('theme_accent_dark','#0E3A94');
\$t->putSetting('theme_secondary','#F5B301');
\$t->putSetting('tagline','Genuine Bajaj parts, diagrams, manuals & AI support');
\$t->putSetting('website','https://bajaj-genuineparts.ug');
\$t->putSetting('public_phone','+256 700 000 000');
\$t->save();
echo 'done'.PHP_EOL;
"
```

`mycloudbss.com/bajaj` now serves the EPC storefront; `…/bajaj/shop` is the cart.
Revert anytime: `\$t->putSetting('vertical','grocery')`.

A second tenant with different `theme_accent` / brand / currency gets the same UI
rebranded — see `data/tenant-speedparts.json` in this kit (green, Kenya/KES) for a
worked example rendered by the same engine.

## Deploy

```
pull → restart → php artisan optimize:clear     # picks up the new vertical + landing branch
```

No migration. Suggested QA (mirrors existing sweeps):
`auto_parts_storefront` · `vertical_visibility` (now 30 with the new rows).
