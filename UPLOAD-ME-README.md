# S2a · Serialized Inventory — the serial registry

The foundation of serialized inventory (Asset Lifecycle). You can now register individual units by
serial, track each through its lifecycle, and every change is logged on the audit spine. Nothing is
wired into sales yet — that's S2b (observe-first).

## What to do
Upload everything here, keeping the folders. (S1 drops already uploaded.)

## How you'll know it worked
Green ✓ "Money kernel (must pass)" — the S2a tests run inside that check.

## How to use it (API today; on-screen view + QR come in S2c)
- Register units (owner/manager): `POST /papi/products/{id}/serials` with `{ "serials": ["SN-001","SN-002"] }`
  (or a single `{ "serial": "SN-001" }`). Optional: manufacturer_serial, batch, supplier, location, purchase_date.
- List a product's units + status counts: `GET /papi/products/{id}/serials`.
- Change a unit's status: `POST /papi/serials/{unit}/status` with `{ "status": "sold" }`
  (in_stock · reserved · sold · installed · returned · replaced · scrapped).

Registering serials automatically flags that product as "serialized".

## How it's built (per your architecture review)
- `inventory_units` is the **source of truth** (its `status` column). Every change also emits an
  **audit event** (SerialRegistered / SerialSold / …) on the spine — history, not state.
- Registration is **idempotent per serial**: re-registering a known serial returns the same unit and
  logs nothing new.
- Serials are an **identity layer over the existing stock ledger** — not a second stock count.
- `qr_token` + `meta` columns are already there so QR codes (S2c) and certificates (S3) need no
  further migration.

## What's inside
- migrations: `create_inventory_units_table`, `add_serialized_to_products`.
- `app/Models/InventoryUnit.php` + `Product.php` (`serialized` flag + `units()` relation).
- `app/Kernel/Events/SerialEvents.php` — the lifecycle vocabulary.
- `app/Services/Inventory/SerialService.php` — register / bulk-register / transition / query.
- `app/Http/Controllers/Panel/SerialController.php` + routes.
- `tests/Feature/Kernel/SerialRegistryTest.php`.

## Safety
Additive: a new table + one nullable product flag; no existing screen or path changed. Writes are
owner/manager-only, tenant-isolated. Rollback = drop the table/column and remove these files.

## Next
S2b — wire serials into sales (mark Sold on delivery; observe-first if none picked; reconciliation).
Then S2c — product-page unit view + QR codes.
