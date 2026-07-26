# Solar business type — set a tenant up for solar in one selection

Adds a **"Solar / equipment"** option to the Business type dropdown in ShopBot Operator
(tenant edit page). Choosing it and saving:
- sets the tenant's vertical to solar (so it sees the trading/wholesale surfaces), and
- **automatically seeds the solar spec catalogue** (Wattage, Voltage, Ah, Lumens, IP Rating, …) —
  idempotent, so re-saving never duplicates.

Serialized inventory and warranties don't depend on the business type — they work per product — so
after this, a solar tenant is fully set up: pick the type, save, done.

## What to do
Upload these files (keep folders), then in ShopBot Operator open the business, set **Business type →
Solar / equipment**, and Save.

## How you'll know it worked
- Green ✓ "Money kernel (must pass)".
- In Operator, the Business type dropdown now lists **Solar / equipment**.
- After saving a business as Solar, open its app → products can be given specs (the solar fields are
  already seeded) and serials.

## What's inside / safety
- `app/Support/Vertical.php` — adds the `solar` vertical (+ label + trading-surface visibility).
- `app/Filament/Admin/Resources/TenantResource.php` — `applyVerticalPreset()` (seeds solar specs; guarded).
- `EditTenant.php` / `CreateTenant.php` — call it after save/create.
- test: `SolarVerticalPresetTest.php`.
- Additive; other business types are unchanged. Rollback = revert these files.
