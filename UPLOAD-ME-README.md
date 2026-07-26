# S3a · Warranty — the warranty object

The foundation of warranty management (Asset Lifecycle). Warranties are now first-class records that
attach to **either a serial or a non-serialized invoice line**, with derived expiry and an audit trail.
Builds on S2 (uses the serial units).

## What to do
Upload everything here, keeping the folders. (S1 + S2 already uploaded & green.)
Green ✓ "Money kernel (must pass)" confirms it.

## What it does (API today; on-page view + auto-activation come in S3b)
- Activate a warranty (owner/manager): `POST /papi/warranties` with `product_id` and EITHER
  `inventory_unit_id` (a serial) OR `invoice_id`+`invoice_item_id` (a non-serial line), plus customer,
  `manufacturer`, `warranty_start`, and `manufacturer_months` / `company_months`
  (warranty_end is computed from the longer term if you don't pass one). Attaching to a serial links
  that unit back to the warranty.
- List: `GET /papi/warranties?product_id=&inventory_unit_id=&customer_phone=`.
- Expiring soon: `GET /papi/warranties/expiring?days=30`.
- Void: `POST /papi/warranties/{id}/void`.

## How it's built (per your review)
- `warranties` table is the source of truth (`status` = active | void); **"expired" is derived** from
  `warranty_end`, so no nightly job. Activation / voiding emit `WarrantyActivated` / `WarrantyVoided`.
- Manufacturer is a name field for now; the full Manufacturer entity is a later slice (decision #6).

## What's inside
- migration `create_warranties_table`
- `app/Models/Warranty.php`, `app/Kernel/Events/WarrantyEvents.php`
- `app/Services/Warranty/WarrantyService.php` (activate / void / list / expiring / derived status)
- `app/Http/Controllers/Panel/WarrantyController.php` + routes
- `tests/Feature/Kernel/WarrantyTest.php`

## Safety
Additive: a new table; no existing screen or path changed. Writes owner/manager-only, tenant-isolated.
Rollback = drop the table and remove these files.

## Next
S3b — show warranties on the product/unit page + auto-activate on sale/installation + "expiring soon"
view. Then the claims workflow (S7).
