# S6a — Recurring maintenance / service plans

This adds **service contracts** to the platform. Service *visits* already work (they're jobs of type
"service" — you can create and complete them from the Jobs screen today). S6a adds the recurring part:
a **maintenance plan** that says "service this customer's system every N months" and tracks when the
next visit is **due**.

## How it works

- Create a plan for a customer (optionally tied to a specific installed unit): choose the interval
  (e.g. every 6 months). The plan computes the **next-due** date.
- When a visit happens, **log it** — this creates a real, completed **service job** in your Jobs list
  (so the visit is on record) and rolls the next-due date forward by the interval automatically.
- A **due list** shows every plan due within N days (and flags overdue ones), so nothing slips. Plans
  can be paused, resumed, or ended.

It's **due-list-driven** — no background scheduler needed; your team works from what's due. (Automatic
generation of the next visit on a timer can be added later if you want it.)

Safe by design: logging a maintenance visit on an already-installed unit **never re-touches its
warranty** — a service visit only installs/activates nothing; it just records the work.

---

## ⚠️ Upload S5 first

S6 builds on **S5** (the Jobs / Work Orders engine) — logging a visit creates a service job through it.
Upload S5a + S5b and see them green first. As a safety net, this zip re-includes S5's engine files
(byte-identical), so nothing breaks if part of S5 didn't upload cleanly.

## Files in this zip

**S6a (the real changes)**

- `database/migrations/2026_07_25_000012_create_maintenance_plans_table.php` *(new)*
- `app/Models/MaintenancePlan.php` *(new)*
- `app/Kernel/Events/MaintenancePlanEvents.php` *(new)*
- `app/Services/Field/MaintenancePlanService.php` *(new)*
- `app/Http/Controllers/Panel/MaintenancePlanController.php` *(new)*
- `tests/Feature/Kernel/MaintenancePlanTest.php` *(new)*
- `routes/web.php` *(replace)* — adds `/papi/maintenance-plans...`
- `docs/v3-migration/MIGRATION-TRACKER.md` *(replace)*

**S5 engine — safety net (identical to S5)**

- `app/Models/WorkOrder.php`, `app/Models/WorkOrderItem.php`, `app/Kernel/Events/WorkOrderEvents.php`,
  `app/Services/Field/WorkOrderService.php`, and the two `work_orders` / `work_order_items` migrations.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`database`, `app`, `routes`, `tests`, `docs`) into your repo at the same paths. If a
new file doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000012_create_maintenance_plans_table.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Models/MaintenancePlan.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Services/Field/MaintenancePlanService.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Http/Controllers/Panel/MaintenancePlanController.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/MaintenancePlanTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** — it runs the new maintenance-plan test (create, log-visit
advances next-due + spawns a service job, due list, pause) with everything before it.

---

## What's next

- **S6b** — the V3 **Maintenance** screen: a due-for-service list with "New plan" and "Log visit"
  actions (this drop is the engine + API; S6b makes it visible in the sidebar).
- Then **S7 Warranty Claims** and **S8 Project Quotes**.
