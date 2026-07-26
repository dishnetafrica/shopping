# Sell solar properly on your storefront — complete update (one upload)

Solar can't be sold like grocery. This one bundle makes your storefront treat it properly: product pages
show specifications, warranty and "what's in the box", and big/made-to-order products get a **"Request a
quote / installation"** button instead of "Add to cart". Grocery and all normal products are untouched.

Everything is in this single zip — backend, storefront page, and tests. Just upload it once.

## What it does

- **Per-product sell mode** — every product is `buy` (normal add-to-cart, the default for everything you
  already have) or `quote` (a solar system, a generator). Set it with a **`Sell Mode`** CSV column on
  import, or leave blank for `buy`.
- **Richer product page** — specifications table, warranty, and kit contents, all shown only when the
  product has them.
- **Quote flow** — quote-mode products show "Request a quote", wired to your existing quotation portal.
- **Solar shops get the premium layout automatically** (still overridable).

Additive and reversible: the only database change is a nullable `sell_mode` column defaulting to `buy`.

## Files in this zip

**New**

- `database/migrations/2026_07_26_000001_add_sell_mode_to_products.php`
- `tests/Feature/Kernel/StorefrontSolarProductTest.php`
- `tests/Feature/Kernel/ProductSellModeImportTest.php`
- `tests/Feature/Kernel/ProductSpecImportTest.php`  *(from the earlier importer upgrade)*
- `tests/Feature/Kernel/ProductMultiPhotoImportTest.php`  *(from the earlier importer upgrade)*

**Replace**

- `resources/storefront/shop.html` — storefront: product page shows specs/warranty/kit + quote button.
- `app/Http/Controllers/Storefront/StorefrontProductController.php` — richer product API payload.
- `app/Http/Controllers/Storefront/StorefrontController.php` — auto premium layout for solar.
- `app/Http/Controllers/Panel/PanelApiController.php` — importer reads `Sell Mode`; also carries the
  multi-photo + spec import upgrade. **This bundle supersedes all the earlier importer/storefront zips.**
- `app/Models/Product.php` — `sell_mode` fillable.

> This is a cumulative superset — it includes the multi-photo import, the spec auto-import, and the whole
> solar-storefront change. If you upload this, you do **not** need the earlier zips.

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `resources` and `tests` folders into your repo at the same paths. The new
migration runs automatically on deploy. If a new file doesn't appear, use GitHub's "Add file" with the
same path and paste the contents.

## How you know it worked

1. Green ✓ on **"Money kernel (must pass)"** (covers the backend + tests; the front-end file can't affect
   it).
2. By eye: open a **quote-mode** solar product on `mycloudbss.com/gsl` — you'll see the spec table,
   warranty, "what's in the box", and a **Request a quote** button. A normal product looks as before.

The storefront JavaScript was syntax-checked and its new rendering logic simulated before shipping.

## Optional follow-ups (just ask)

- Make the catalogue **grid tiles** also show "Request a quote" for quote-mode products (page already does).
- Add a **toggle on the product edit screen** so you can flip existing products to quote without re-importing.
