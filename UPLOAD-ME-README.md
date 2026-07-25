# S1a · Product Specifications (Asset Lifecycle) — upload to GitHub

The first capability of the Asset Lifecycle extension. Product Specifications is built as a **core
platform capability** (useful to any business), and the **solar preset** just seeds a starter
catalogue. This drop is the tested foundation — data, service, API, and the solar seed. The on-screen
display on product/quote/invoice, filtering in the product list UI, export, and the capability-manifest
registration come in **S1b** (I want to study the V3 product screen and the workspace layer before
touching them, so nothing working is edited blind).

## What to do
Upload everything here to your repository, **keeping the folders** (`app/…`, `database/…`, `routes/…`,
`tests/…`, `docs/…`). Nothing needs deleting.

## How you'll know it worked
On the **Actions** tab: **green ✓ "Money kernel (must pass)"**. The new spec tests live under
`tests/…/Kernel/`, so they run inside that same green check. If it's red, send me a screenshot.

## What it does (verifiable by API today; UI comes in S1b)
- Define a reusable spec catalogue (Wattage/W/number, Battery Chemistry/pick-list, IP Rating/text…).
- Set spec values on any product; numbers are stored numerically so you can filter "≥ 400 W".
- Filter products by spec (numeric range, exact match).
- Seed the solar starter catalogue in one call (owner/manager only).

Endpoints (all under `/papi`, owner/manager for writes):
`GET/POST spec-definitions` · `POST spec-definitions/seed-solar` · `GET/PUT products/{id}/specs` ·
`GET product-spec-filter`.

## What's inside
- `database/migrations/*spec_definitions*`, `*product_specs*` — two new tables (products table untouched).
- `app/Models/SpecDefinition.php`, `ProductSpec.php` + a `specs()` relation added to `Product.php`.
- `app/Services/Catalog/ProductSpecService.php` — the one reader/writer (define, set, read, filter).
- `app/Http/Controllers/Panel/ProductSpecController.php` + routes.
- `app/Support/Presets/SolarPreset.php` — the solar starter catalogue + seeder.
- `docs/asset-lifecycle/DECISIONS.md` — the approved architecture decisions (from your review), so
  every future session honours them.
- `tests/…/Kernel/ProductSpec*` — pure coercion + full API tests.

## Safety
- Additive: two new tables + a read relation; no existing screen, product, quote or invoice changed.
- Multi-tenant (global tenant scope) and permission-aware (writes are owner/manager).
- Rollback: drop the two tables and remove these files — you're exactly where you are today.

## Next (S1b)
Show specs on the product page, quotation and invoice; filter in the product list; export; and register
the capability in the workspace/module layer with its permission.
