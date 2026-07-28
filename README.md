# Task ID numbers + activity markers (Tasks screen)

Adds a human-facing task number, makes the manager Tasks list clickable into the
existing detail page, and surfaces comments + who-completed at a glance.

## What changed for the user
1. **Per-shop task ID** — every task now shows a number in a new "#" column that
   restarts at #1 for each shop (Family Shoppers #1, #2, #3…; EA Auto Part #1, #2…).
   Staff can quote it on WhatsApp ("done with #7"). It also appears on the task
   detail header.
2. **Click a task to see details** — the manager "All team tasks" register was a
   plain table before (rows weren't clickable). Now clicking any task row opens the
   in-app detail page that already existed: description, photo, comment box, a
   "Mark as done" button, and the full **Activity timeline** (every comment +
   completion, including ones made through the no-login WhatsApp link).
3. **At-a-glance "Updates" column** — shows a 💬 comment count, and for finished
   tasks shows "✓ <who completed it>". Completion done via the no-login link (which
   leaves no logged-in user) falls back to the assignee's name.

## Files changed
- `database/migrations/2026_07_28_000001_add_ref_no_to_order_tasks_table.php` (NEW)
  Adds `ref_no` (unsigned int, nullable) + a `(tenant_id, ref_no)` index, and
  backfills every existing task 1..N per shop in creation order.
- `app/Models/OrderTask.php`
  `ref_no` fillable + integer cast; a `creating` hook assigns the next per-tenant
  number on insert (never overwrites an explicit value; no-op if the column is
  absent). New `refLabel()` helper ("#7", falls back to id if unnumbered).
- `app/Capability/Process/TasksCapability.php`
  New "#" and "Updates" columns for both the manager and staff task lists; one
  grouped query for comment counts; completed-by resolution.
- `app/Http/Controllers/Panel/TaskApiController.php`
  `/papi/task/show` now returns `ref`, `completed_by`, `completed_at`.
- `resources/views/shell.blade.php`
  Manager (selectable) table rows now open the task detail on click (previously
  only the phone-card view did). Detail header shows the "#" reference and a
  "Completed by" line.
- `tests/Feature/TaskRefNumberTest.php` (NEW) — regression tests for per-tenant
  sequential numbering.

## Deploy
    php artisan migrate        # adds ref_no + backfills existing tasks per shop
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear

The migration is additive and idempotent (guards on column existence); `down()`
drops the column and index. No existing data is modified other than filling the
new ref_no. Numbering is by DB id order, so existing tasks keep a stable order.

## Note on numbering
New numbers are assigned as `max(ref_no for that shop) + 1` at insert time. Under
normal single-writer task creation this is exact; two tasks created in the very
same instant is not a concern at this volume.
