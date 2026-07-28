# CloudBSS — combined update (5 fixes)

One package, five changes. Files sit at their real repo paths, so you can copy the
whole tree over your project root. Nothing here conflicts — each fix touches
different files (the task features share a couple of files, all consistent).

---

## What's inside

### 1. Payables overstatement fix  *(the "$5,667 you owe" bug)*
Supplier payments live as separate rows and don't touch a bill's `paid_native`, so
the dashboard/working-capital "you owe" figure was adding back every payment ever
made. Now netted through one shared `PayablesCalculator` (Nura bhai reads $538.44,
not $5,667.03). Does **not** affect P&L (dashboard profit is cash-basis).
- `app/Services/Finance/PayablesCalculator.php` (new)
- `app/Http/Controllers/Panel/PanelApiController.php`
- `app/Capability/Shared/MoneyTimingCapability.php`
- `tests/Feature/PayablesCalculatorTest.php` (new)

### 2. Task-link fix  *(custom domain + can't close from the link)*
The WhatsApp reminder link now uses familyshoppers.net instead of the raw app host,
and the no-login task actions no longer fail CSRF for a session-less visitor.
- `app/Console/Commands/TaskReminderCommand.php`
- `bootstrap/app.php`
- `resources/views/jobs-list.blade.php`

### 3. Task ID numbers + activity markers
A per-shop task number ("#" column + detail header). The manager task list is now
clickable into the detail view. New "Updates" column shows a 💬 comment count and,
for finished tasks, who completed it.
- `database/migrations/2026_07_28_000001_add_ref_no_to_order_tasks_table.php` (new)
- `app/Models/OrderTask.php`
- `app/Capability/Process/TasksCapability.php`
- `app/Http/Controllers/Panel/TaskApiController.php`
- `resources/views/shell.blade.php`
- `tests/Feature/TaskRefNumberTest.php` (new)

### 4. Task completion confirmations (WhatsApp)
When a manually-assigned task is completed (app, link, or panel Done), the system
WhatsApps a receipt to the person who did it (echoing their note) and an alert to
your owner number(s) with their comment. Ad-hoc only; on/off via `task_done_alerts`
(default on).
- `app/Services/Ops/TaskEngine.php`
- `tests/Feature/TaskCompletionAlertTest.php` (new)

### 5. Assign a task from a customer  *(e.g. "collect 68 USD from Mehul")*
The customer page gets a **New task** button. Fill in an assignee, an optional
**amount to collect** (folded into the title → "Collect USD 68 from Mehul Patel"),
a due date and priority. The task links to that customer, appears in a new **Tasks
tab** on the customer page, shows the customer's name in the main Tasks list, and —
when marked done — triggers the completion confirmation from #4. Manager/owner only.
- `database/migrations/2026_07_28_000002_add_customer_id_to_order_tasks_table.php` (new)
- `app/Capability/Object/CustomerCapability.php`
- `app/Services/Ops/TaskEngine.php`  *(assignAdHoc gained an optional customer link)*
- `app/Models/OrderTask.php`
- `app/Capability/Process/TasksCapability.php`
- `tests/Feature/TaskCustomerLinkTest.php` (new)

---

## Deploy — one pass

1. **Back up first.** Snapshot the DB (the only schema changes are two additive
   nullable columns on `order_tasks`) and make sure you can roll back the files.

2. **Copy the files** over your project root so each lands at its path above.

3. **Run migrations** (adds `ref_no` + backfills per shop; adds `customer_id`):
   ```
   php artisan migrate
   ```

4. **Clear caches:**
   ```
   php artisan config:clear && php artisan route:clear && php artisan view:clear
   ```

5. **Restart the queue worker** (WhatsApp sends are queued jobs):
   ```
   php artisan queue:restart
   ```

6. **Verify (optional):**
   ```
   php artisan test --filter=PayablesCalculatorTest
   php artisan test --filter=TaskRefNumberTest
   php artisan test --filter=TaskCompletionAlertTest
   php artisan test --filter=TaskCustomerLinkTest
   ```

No config changes, no new services. If EasyPanel rebuilds on git push, commit these
files and run the migrate + queue:restart step as a release command.

---

## Notes & rollback
- **Additive & reversible.** Both migrations' `down()` drop their column/index; the
  rest is restore-the-files. No existing data is modified beyond filling `ref_no`.
- **Completion alerts** fire only for manually-assigned tasks; mute per shop with
  `\App\Models\Tenant::find(<id>)->putSetting('task_done_alerts', false);`
- **Customer tasks** reuse the existing ad-hoc task engine — the "amount to collect"
  is stored inside the task title (no money is posted; it's a reminder, not a ledger
  entry). Collecting the money is still recorded via the customer's "Record payment".
- **Payables fix** changes only how the number is calculated/displayed — it writes
  nothing and never touches the ledger or P&L.

## MANIFEST
```
app/Capability/Object/CustomerCapability.php
app/Capability/Process/TasksCapability.php
app/Capability/Shared/MoneyTimingCapability.php
app/Console/Commands/TaskReminderCommand.php
app/Http/Controllers/Panel/PanelApiController.php
app/Http/Controllers/Panel/TaskApiController.php
app/Models/OrderTask.php
app/Services/Finance/PayablesCalculator.php
app/Services/Ops/TaskEngine.php
bootstrap/app.php
database/migrations/2026_07_28_000001_add_ref_no_to_order_tasks_table.php
database/migrations/2026_07_28_000002_add_customer_id_to_order_tasks_table.php
resources/views/jobs-list.blade.php
resources/views/shell.blade.php
tests/Feature/PayablesCalculatorTest.php
tests/Feature/TaskCompletionAlertTest.php
tests/Feature/TaskCustomerLinkTest.php
tests/Feature/TaskRefNumberTest.php
```
