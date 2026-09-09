# ShopBot technology storefront — install, activate, roll back

A reusable storefront vertical for software sellers, computer/laptop retailers,
cybersecurity companies, networking suppliers, IT hardware businesses and B2B
technology resellers. Secure Solutions is the first tenant; nothing about it is
baked into the code.

---

## 1. What this is, architecturally

ShopBot already selects a storefront template per tenant inside
`StorefrontController` — `shop.html` (catalogue), `brand.html` (manufacturer
brochure), `autoparts.html` (EPC). This adds a **fourth**: `technology.html`,
gated the same way.

It reuses, and does not reimplement:

| Reused | How |
|---|---|
| Tenant resolution | `resolveShop()` — slug or custom domain, unchanged |
| Catalogue | `GET {base}/catalogue` — the existing feed, unchanged |
| Orders | `POST {base}/order` — `{name, phone, items:[{name, qty}]}` |
| Quotes / RFQ | `POST {base}/quote` — same shape |
| Pricing authority | `repriceCart()` server-side; the page never sets a price |
| FX / currency | tenant `currency` + `Fx`, unchanged |
| Visibility, hidden categories, stock policy | the catalogue feed already applies them |
| SEO | server-rendered title/meta/OG/canonical + JSON-LD, same helpers |

No new routes, no new tables, **no migrations**, no new endpoints.

### The theming change you asked for

The classic storefront themes through `resolveTheme()` (2 presets, an accent pair,
4 strings, 2 booleans) and then patches literal markup with
`str_replace('<div class="lg">SH</div>', …)`.

This vertical instead resolves **one nested config from tenant settings** in
`App\Support\TechTheme` and injects it as a single JSON blob — the same clean
three-token pattern `brand.html` already uses:

```
__SEO_TITLE__   __SEO_META__   __TECH_CONFIG__
```

Colours, typography, radius, header style, card style, navigation, section order,
hero, rails, solutions, services, trust blocks, why-us, FAQ, footer columns,
contact details, content pages and commerce toggles are all data.

---

## 2. Files

### Modified (2) — both strictly additive

| File | Change |
|---|---|
| `app/Support/Vertical.php` | Adds the `technology` constant; appends it to `ALL`, `LABELS` and the `riders`/`pos` matrix rows. **No line deleted** — the three changed lines are the same lines with one entry appended. |
| `app/Http/Controllers/Storefront/StorefrontController.php` | Adds `technologyEnabled()` + `technology()`, and one guard line each in `rootLanding()` and `landing()`. **Zero lines removed** (verified by diff against the original zip). |

### New (4)

| File | Purpose |
|---|---|
| `app/Support/TechTheme.php` | Pure config resolver — no DB, no facades, unit-testable |
| `resources/storefront/technology.html` | The storefront (single file, no build step) |
| `app/Console/Commands/TechnologyStorefrontCommand.php` | `php artisan storefront:technology` |
| `qa/technology_storefront.php` | 77-assertion pure harness — runs without Laravel |

---

## 3. Deploy

```bash
# from the repo root, after unpacking this ZIP over it
php -l app/Support/TechTheme.php
php -l app/Support/Vertical.php
php -l app/Http/Controllers/Storefront/StorefrontController.php
php -l app/Console/Commands/TechnologyStorefrontCommand.php

php qa/technology_storefront.php        # must print ALL PASS: 77 passed, 0 failed
```

Then commit and push as usual — EasyPanel rebuilds the image. `resources/` and
`app/` are already in the Dockerfile COPY list; `qa/` rides along for CI and is
not deployed, which is intended.

**No migration. No cache clear required** (the storefront reads tenant settings
per request; the catalogue feed keeps its existing 5-minute cache).

---

## 4. Activate for a tenant

Nothing happens to any tenant until you do this.

```bash
# 1. preview what would be written — writes nothing
php artisan storefront:technology --tenant=<ID> --settings=path/to/settings.json --dry

# 2. apply
php artisan storefront:technology --tenant=<ID> --settings=path/to/settings.json
```

For Secure Solutions, use the supplied file:

```bash
php artisan storefront:technology --tenant=<ID> --settings=tenant-config/secure-solutions-settings.json --dry
```

The command writes through `Tenant::putSetting()`, which locks the row and
re-reads before merging — so a concurrent watchdog cannot clobber it, and any
setting the file does not name is left exactly as it is. There is no
"all tenants" mode by design.

### Activating for a *different* technology tenant

1. Set the vertical (either is sufficient):
   - `settings.vertical = "technology"`, or
   - `settings.technology_site = true`
2. Give it an identity: `logo`, `favicon`, `theme_accent`, `theme_accent_dark`,
   `tagline`, `meta_title`, `meta_description`, `currency`.
3. Optionally shape the homepage — everything below is optional and has a
   sensible generic default:

```jsonc
{
  "tech": {
    "colors":  { "ink": "#0E1721", "surface": "#F3F7FB", "line": "#DDE5ED" },
    "fonts":   { "display": "Manrope", "body": "Inter" },   // Google Fonts names
    "radius": 10, "header_style": "standard", "card_style": "bordered",
    "density": "comfortable", "mega_menu": true,

    "sections": ["hero","trust_bar","categories","featured","software",
                 "hardware","networking","brands","solutions","services",
                 "new_arrivals","why_us","quote_cta","faq","contact_cta"],

    "hero":   { "eyebrow": "…", "title": "…", "text": "…", "image": "…",
                "stats": [{ "k": "500+", "l": "Products" }] },

    "rails":  { "software":   { "title": "…", "categories": ["<real category name>"], "limit": 8 },
                "hardware":   { "title": "…", "categories": [...] },
                "networking": { "title": "…", "categories": [...] } },

    "trust":     [{ "icon": "shield", "title": "…", "text": "…" }],
    "why_us":    [{ "icon": "grid",   "title": "…", "text": "…" }],
    "solutions": [{ "icon": "bolt",   "title": "…", "text": "…", "cta": "…", "href": "cat:Networking" }],
    "services":  [{ "icon": "truck",  "title": "…", "text": "…" }],
    "faq":       [{ "q": "…", "a": "…" }],

    "nav":            [{ "label": "Security", "href": "cat:Security",
                         "children": [{ "label": "Endpoint", "href": "cat:Endpoint" }] }],
    "footer_columns": [{ "title": "Shop", "links": [{ "label": "All", "href": "cat:All" }] }],
    "pages":          { "terms": { "title": "Terms", "blocks": [{ "type": "h", "text": "…" },
                                                               { "type": "p", "text": "…" }] } },
    "contact": { "phones": ["+…"], "emails": ["…"], "address": "…", "hours": "…" },

    "allow_cart": true, "allow_buy_now": true, "allow_quote": true, "allow_wishlist": true
  }
}
```

**Leave `tech` out entirely and the storefront still works.** With no config it
derives navigation, category tiles, brand strip and footer links from the live
catalogue, and ships generic technology-retail copy. Icons available:
`shield, bolt, quote, chat, grid, truck, tag, check`.

`href` values understand `cat:<Category>`, `brand:<Brand>`, `page:<slug>`, and
absolute URLs.

---

## 5. Rollback

Three levels, cheapest first.

**Per tenant (instant, no deploy):**
```bash
php artisan storefront:technology --tenant=<ID> --off
```
Sets `technology_site = false`. `technologyEnabled()` checks that flag *before*
the vertical, so this wins even if `vertical` is still `technology`, and the
tenant falls straight back to its previous storefront on the next request.

**Or by hand:**
```php
Tenant::find($ID)->putSetting('technology_site', false);
```

**Full code rollback:** revert the commit. The two modified files return to their
original bytes and the four new files become dead code. Nothing else references
them, there is no migration to unwind, and no tenant data is deleted — the
`tech` settings simply stop being read.

---

## 6. Existing tenants are unaffected — how that is enforced and verified

`technologyEnabled()` mirrors the existing `brandSiteEnabled()` /
`autoPartsEnabled()` gates exactly:

```php
$flag = $tenant->setting('technology_site', null);
if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
return Vertical::of($tenant) === Vertical::TECHNOLOGY;
```

`Vertical::of()` returns `grocery` for any tenant with no explicit vertical, so
**both halves are false for every existing tenant.** The guard lines were added
*above* the existing `autoPartsEnabled` / `brandSiteEnabled` branches without
altering them.

`qa/technology_storefront.php` asserts this directly (run it — it needs no
framework). Section 1 checks ten shapes of existing tenant, including legacy
`restaurant_mode` and `feature_thali` inference and a tenant with a bogus
vertical, and requires all of them to be rejected. Section 2 asserts the feature
matrix is unchanged for grocery and restaurant. Section 3 builds two tenants'
configs and asserts neither contains any of the other's strings. Section 4
asserts the default config contains no client-identifying string at all.

Diff evidence against the original zip: `StorefrontController.php` has **zero
removed lines**; `Vertical.php` has three, each an in-place extension of a list.

---

## 7. What could not be runtime-tested here

The repo ships without `vendor/`, so Laravel cannot boot in this sandbox.

- **Tested:** `php -l` on all five PHP files; the 77-assertion pure harness;
  and a full browser render of `technology.html` against a mock of the real
  `/catalogue` payload with all 173 imported products and 87 images
  (homepage, product rails, product detail, category browse with filters,
  and mobile at 375px).
- **Not tested here, verify on staging:** the Laravel-bound parts —
  `resolveShop()` reaching `technology()`, `resource_path()` finding the
  template, the artisan command against a real `Tenant` row, and the live
  `/order` and `/quote` round trips. The page's contracts with those endpoints
  were read from the controller source and matched exactly, but they have not
  been exercised against a running app.
