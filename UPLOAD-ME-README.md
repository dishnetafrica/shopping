# Team & Roles in V3 — the last legacy-panel gap

This migrates **Team & Roles** (legacy `/panel/staff`) to CloudBSS V3. With it, every remaining
legacy-panel gap is closed.

## What you get

A new **Team & Roles** item in the V3 sidebar (under **Settings**):

- **Your team** listed with each person's role and how they sign in, plus your seat usage.
- **Add staff**, **change a member's role**, and **remove a member** — each posting to your **existing,
  owner-only staff endpoints**, so all the safety carries over unchanged: only owners can make changes,
  you can't remove yourself or the last remaining login, and your plan's seat limit is enforced.
- A read-only **role guide** — what each role (Owner, Manager, Sales, Product staff, Purchaser, Field,
  Transport, Staff) is for, so you assign confidently.
- Legacy `/panel/staff` now redirects to this V3 screen.

Two deliberate safety choices: I wrote **no new access-control code** (all writes reuse the guarded
endpoints), and the current user is **excluded from the change-role / remove pickers** so you can't lock
yourself out. The strict-enforcement switch (which can suddenly block staff) is **left exactly as it is**
— that's a separate, deliberate decision, not something to bury in a screen.

---

## ⚠️ Prerequisite

Ships the **cumulative** capability registry (`CoreCapabilityProvider.php`) and V3 shell
(`shell.blade.php`) — they include all the earlier V3 screens. Upload the earlier drops first (they are,
if you've followed along); this supersedes them with the latest.

## Files in this zip (6)

**New**

- `app/Capability/Process/TeamCapability.php` — the V3 Team & Roles screen
- `tests/Feature/Kernel/TeamWorkspaceTest.php` — proves it

**Replace**

- `app/Capability/Registry/CoreCapabilityProvider.php` — registers Team
- `resources/views/shell.blade.php` — Team in the V3 sidebar (Settings)
- `app/Http/Middleware/ClassicPanelRedirects.php` — `/panel/staff` → V3 Team
- `docs/v3-migration/MIGRATION-TRACKER.md` — Team & Roles now ✅ Done

No new routes and no payment/auth code — the screen reads staff and reuses the existing `/papi/staff`
endpoints for changes.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `resources`, `tests`, `docs`) into your repo at the same paths. If a new file
doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Process/TeamCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/TeamWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 → **Settings → Team & Roles**: your team lists,
the role guide shows, and (as owner) you can add / change-role / remove.

---

## 🎉 Legacy-panel migration: gaps closed

Dashboard, Orders, Customers, Products, Inventory, Purchasing, Suppliers, Accounting, Messaging,
Campaigns, Leads, Scheduling, Reports, Settings, **Module Manager**, **Billing** (view — payment stays on
the secure legacy page), and **Team & Roles** are all in V3. The only piece still on the legacy panel by
design is the Billing **payment flow** (a separate, careful step). New tenants already start in V3.
