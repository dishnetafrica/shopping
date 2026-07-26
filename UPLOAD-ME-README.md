# S5b — the Jobs screen in V3

This puts **Jobs** on screen in CloudBSS V3. After S5a gave us the engine, S5b makes it visible and
clickable: a **Jobs** item appears in the V3 sidebar (under **Operations**), showing all your
installations, service visits, repairs and inspections.

## What you can do from V3

- **See all jobs** in a list — number, customer, type, status, scheduled date, technician — with KPI
  tiles (open / scheduled / in progress / completed today) and filters by status and type.
- **Create a new job** with the "New job" button (type, customer, site address, schedule, technician).
- **Open a job** to see its equipment and run its lifecycle: **Schedule**, **Add equipment** (paste
  serial numbers), **Start**, **Complete**, **Cancel**.
- **Completing an installation** marks its serial units *installed* and **auto-activates their
  warranties** (the Phase 1 + Phase 2 payoff) — one tap starts the customer's coverage.

It's all built the V3-native way: the sidebar builds itself from the capability registry, so Jobs
appears automatically for every role (the field team needs it). Every write re-checks owner/manager
permission on the server.

## How it's wired (for peace of mind)

- Two new V3 workspaces: **jobs** (the list) and **workOrder** (one job), rendered by the same generic
  V3 engine every other screen uses.
- A precise 4-line edit to the V3 shell so "Jobs" gets a label, an icon, a home under Operations, and is
  marked as a ready (not "coming soon") screen. I syntax-checked the entire shell script after the edit.

---

## ⚠️ Upload S5a first

S5b builds on **S5a** (the Work Orders engine). Upload S5a and see it green **before** this. As a safety
net, this zip re-includes S5a's engine files (byte-identical), so nothing breaks if part of S5a didn't
upload cleanly.

## Files in this zip

**S5b (the screen)**

- `app/Capability/Process/WorkOrderListCapability.php` *(new)* — the Jobs list workspace
- `app/Capability/Object/WorkOrderCapability.php` *(new)* — the single-job workspace
- `app/Capability/Registry/CoreCapabilityProvider.php` *(replace)* — registers both
- `app/Http/Controllers/Panel/WorkOrderController.php` *(replace)* — "Add equipment" accepts pasted serials
- `resources/views/shell.blade.php` *(replace)* — Jobs in the V3 sidebar
- `tests/Feature/Kernel/WorkOrderWorkspaceTest.php` *(new)* — proves the screen
- `docs/v3-migration/MIGRATION-TRACKER.md` *(replace)* — Jobs now ✅ Done in V3

**S5a engine — safety net (identical to S5a)**

- `app/Models/WorkOrder.php`, `app/Models/WorkOrderItem.php`, `app/Kernel/Events/WorkOrderEvents.php`,
  `app/Services/Field/WorkOrderService.php`, the two `work_orders` / `work_order_items` migrations,
  and `routes/web.php`.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `resources`, `routes`, `database`, `tests`, `docs`) into your repo so they land
at the same paths. If a new file doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Process/WorkOrderListCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Object/WorkOrderCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/WorkOrderWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 — **Jobs** is in the left sidebar under
Operations. Create a job, add a serial or two, hit Complete, and check that unit's warranty is now
active.

---

## What's next

**S6 Service Visits** (same Jobs engine, `type=service`, with optional recurring), then **S7 Warranty
Claims** and **S8 Project Quotes**.
