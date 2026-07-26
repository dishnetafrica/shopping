# V3 by default for every new tenant + Migration Constitution

Two things in this drop:

## 1. Every new tenant now starts in CloudBSS V3

One tiny change at the single point where every business is created: a new tenant is now flagged to
**land in V3** automatically (`order_system_enabled = true`). This covers **both** ways tenants are
made — self-serve signup (already did this) and the **ShopBot Operator admin** (which is how GSL was
made, and previously could land a tenant on the old panel).

Safe and reversible by design:

- **Existing tenants are untouched.** This only applies to tenants created from now on.
- **An explicit choice is respected.** If a tenant is ever created with the setting deliberately off,
  that's honored.
- **Any tenant can still be flipped back at runtime** with the existing switches — no deploy needed
  (`order_system_enabled`, `v3_all_roles`, `v3_redirects`, or the `?classic=1` link).

So from now on: *Create tenant → launch V3 → onboard → never touch the old panel.*

## 2. The Migration Constitution is now written into the codebase

Your direction is recorded as a governing document future work must follow, tied to the migration
machinery that already exists in the code (the `order_system_enabled` / `v3_redirects` switches, the
`v3:staff` / `v3:retire` commands, and the legacy→V3 page map). Two docs:

- `docs/v3-migration/CONSTITUTION.md` — the 10 rules + the freeze.
- `docs/v3-migration/MIGRATION-TRACKER.md` — the single source of truth for what's migrated. A first
  pass is already filled in from the code: most modules (Dashboard, Orders, Customers, Products,
  Inventory, Purchasing, Suppliers, Accounting, Messaging, Campaigns, Leads, Scheduling, Consignments,
  Ads, Guide) already have real V3 homes. The genuine remaining gaps are **Billing, Roles/Permissions,
  and Module Manager**, plus a few to double-check (Settings, Reports, Business Profile, WhatsApp
  connect, Team).

---

## Files in this zip (4)

| File | What it is |
|------|------------|
| `app/Models/Tenant.php` | **REPLACE** — new tenants default to V3 |
| `tests/Feature/Kernel/V3DefaultTenantTest.php` | **NEW** — proves the default (part of the green gate) |
| `docs/v3-migration/CONSTITUTION.md` | **NEW** — the governing document |
| `docs/v3-migration/MIGRATION-TRACKER.md` | **NEW** — the migration source of truth |

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `tests`, `docs`) into your repo so they land at the same paths. If any new
file doesn't appear, use these direct links (paste contents from the zip, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/V3DefaultTenantTest.php`
- `github.com/dishnetafrica/shopping/new/main?filename=docs/v3-migration/CONSTITUTION.md`
- `github.com/dishnetafrica/shopping/new/main?filename=docs/v3-migration/MIGRATION-TRACKER.md`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. To see it live: create a fresh test tenant in the Operator
admin and confirm it opens straight into `/v3`.
