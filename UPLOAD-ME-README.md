# S6b — the Maintenance screen in V3

This puts **Maintenance** on screen in CloudBSS V3, completing Service Visits. A **Maintenance** item
appears in the V3 sidebar (under **Operations**) — a due-for-service list so nothing slips.

## What you can do from V3

- **See every plan** with its due state: **Overdue** (red), **Due soon** (amber), **Scheduled** (green),
  plus Paused / Ended — with KPI tiles (overdue / due in 30 days / active plans) and a status filter.
- **Create a plan** with the "New plan" button (customer, interval in months, first-due date).
- **Open a plan** and **Log a visit** — which records a real completed service job (in your Jobs list)
  and rolls the next-due date forward automatically. You can also Pause, Resume, or End a plan.

Built the V3-native way: the sidebar builds itself from the capability registry, so Maintenance appears
automatically for every role. Every write re-checks owner/manager permission on the server.

With this, **Service Visits are fully done** — one-off service jobs (via the Jobs screen) *and* recurring
maintenance contracts (here).

---

## ⚠️ Upload S5 and S6a first

S6b is the screen on top of the **S6a** engine (which itself builds on **S5**). Upload S5a, S5b, S6a and
see them green before this. As a safety net, this zip re-includes the S6a maintenance engine
(byte-identical), so nothing breaks if part of S6a didn't upload cleanly.

## Files in this zip

**S6b (the screen)**

- `app/Capability/Process/MaintenancePlanListCapability.php` *(new)* — the Maintenance list
- `app/Capability/Object/MaintenancePlanCapability.php` *(new)* — one plan (Log visit / pause / end)
- `app/Capability/Registry/CoreCapabilityProvider.php` *(replace)* — registers both
- `resources/views/shell.blade.php` *(replace)* — Maintenance in the V3 sidebar
- `tests/Feature/Kernel/MaintenanceWorkspaceTest.php` *(new)* — proves the screen
- `docs/v3-migration/MIGRATION-TRACKER.md` *(replace)* — Service Visits now ✅ Done

**S6a engine — safety net (identical to S6a)**

- `app/Models/MaintenancePlan.php`, `app/Kernel/Events/MaintenancePlanEvents.php`,
  `app/Services/Field/MaintenancePlanService.php`, `app/Http/Controllers/Panel/MaintenancePlanController.php`,
  the `maintenance_plans` migration, and `routes/web.php`.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `resources`, `routes`, `database`, `tests`, `docs`) into your repo at the same
paths. If a new file doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Process/MaintenancePlanListCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Object/MaintenancePlanCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/MaintenanceWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 — **Maintenance** is in the left sidebar under
Operations. Create a plan, open it, Log a visit, and check the next-due date jumped forward and a
service job appeared in Jobs.

---

## What's next

**S7 — Warranty Claims** (open → approve/reject a claim against a warranty), then **S8 — Project Quotes**.
