# S5a — Jobs / Work Orders (Phase 2 foundation, V3-native)

This starts **Phase 2** — the operational layer. It adds a generic **Jobs** system: a job to install,
service, repair, or inspect equipment at a customer's site. Installations come first; service visits,
repairs and inspections reuse the exact same system (just a different `type`). It's **V3-native** — a
brand-new module with no legacy panel version, so it's V3-only from birth.

## What a job holds

A customer + site address, an optional link to the sale it came from, an assigned technician, a
scheduled date, the specific equipment (serial units) going in, notes, and a status:
**draft → scheduled → in progress → completed** (or **cancelled**). Each job gets a number (JOB-0001…).

## The payoff — Phase 1 and Phase 2 lock together

When you **complete an installation**, every serial unit on that job is automatically marked
**installed** — which (thanks to the auto-warranty work from S3c) **activates each unit's warranty** from
the product's default terms. So finishing the job starts the customer's coverage with **zero extra data
entry**. Completing a *service* visit correctly does none of that — it just closes the visit.

It's all safe: the warranty/serial step on completion is guarded, so it can never block a job from
closing.

> **Depends on Phase 1** (specs / serials / warranty, including S3c auto-warranty). Those are already in
> your repo from the earlier drops — just make sure they're in place and green.

---

## Files in this zip (10)

**New**

- `database/migrations/2026_07_25_000010_create_work_orders_table.php`
- `database/migrations/2026_07_25_000011_create_work_order_items_table.php`
- `app/Models/WorkOrder.php`
- `app/Models/WorkOrderItem.php`
- `app/Kernel/Events/WorkOrderEvents.php`
- `app/Services/Field/WorkOrderService.php`
- `app/Http/Controllers/Panel/WorkOrderController.php`
- `tests/Feature/Kernel/WorkOrderTest.php`

**Replace**

- `routes/web.php` (adds the `/papi/work-orders...` routes)
- `docs/v3-migration/MIGRATION-TRACKER.md` (records the new module)

---

## How to upload (GitHub, branch `main`)

Drag the folders (`database`, `app`, `routes`, `tests`, `docs`) into your repo so they land at the same
paths. If any new file doesn't appear, use these "create file at path" links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000010_create_work_orders_table.php`
- `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000011_create_work_order_items_table.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Models/WorkOrder.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Models/WorkOrderItem.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Kernel/Events/WorkOrderEvents.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Services/Field/WorkOrderService.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Http/Controllers/Panel/WorkOrderController.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/WorkOrderTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** — it runs the new work-order test (including the
complete → installed → warranty-activated flow) with everything before it.

---

## What's next

- **S5b** — the V3 **Jobs screen**: see/create/schedule/complete jobs from the V3 interface (this drop
  is the engine + API; S5b makes it visible and clickable). I'll study the V3 nav/workspace wiring and
  build it as its own slice.
- Then **S6 Service Visits**, **S7 Warranty Claims**, **S8 Project Quotes**.
