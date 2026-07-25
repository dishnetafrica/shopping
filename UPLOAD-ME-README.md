# S2 (complete) — re-upload this whole bundle to fix the failing check

The check failed with **"relation inventory_units does not exist"** — the table's migration didn't
run, which means the S2a migration file isn't in your repo (an upload gap; S2b didn't include the
migrations). This bundle contains **everything for S2 (a + b)** so one upload guarantees all the pieces
are present together.

> ⚠️ Do NOT create a new inventory_units migration. It already exists here
> (`database/migrations/2026_07_25_000004_create_inventory_units_table.php`). A second one would fail
> with "table already exists." Just upload this bundle.
> (The "database shopbot does not exist" line in the log is the harmless health-check, not the cause.)

## What to do
Upload everything in this folder, **keeping the folders** (`database/…`, `app/…`, `routes/…`,
`tests/…`). It's safe to overwrite — these are the current versions.

Then the check should go green.

## The one file that matters most
`database/migrations/2026_07_25_000004_create_inventory_units_table.php` — this is what creates the
`inventory_units` table. Make sure, after uploading, it appears under `database/migrations/` in GitHub.

## What's in the bundle (all of S2)
- migrations: create `inventory_units`, add `serialized` to products
- `app/Models/InventoryUnit.php`, `Product.php`
- `app/Kernel/Events/SerialEvents.php`
- `app/Services/Inventory/SerialService.php`, `OrderStockIssuer.php`
- `app/Http/Controllers/Panel/SerialController.php` + routes
- tests: `SerialRegistryTest.php`, `SerialSaleObserveTest.php`
