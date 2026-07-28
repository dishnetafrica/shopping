# CloudBSS — combined update (6 changes)

One package. Files sit at their real repo paths, so you can copy the whole tree over
your project root. Nothing here conflicts — the task features share a few files, all
consistent.

---

## What's inside

### 1. Payables overstatement fix  *(the "$5,667 you owe" bug)*
Supplier payments live as separate rows and don't touch a bill's `paid_native`, so
the "you owe" figure was adding back every payment ever made. Now netted through one
shared `PayablesCalculator` (Nura bhai reads $538.44, not $5,667.03). Does **not**
affect P&L (dashboard profit is cash-basis).
- `app/Services/Finance/PayablesCalculator.php` (new)
- `app/Http/Controllers/Panel/PanelApiController.php`
- `app/Capability/Shared/MoneyTimingCapability.php`
- `tests/Feature/PayablesCalculatorTest.php` (new)

### 2. Task-link fix  *(custom domain + can't close from the link)*
The WhatsApp reminder link uses familyshoppers.net instead of the raw app host, and
the no-login task actions no longer fail CSRF for a session-less visitor.
- `app/Console/Commands/TaskReminderCommand.php`
- `bootstrap/app.php`
- `resources/views/jobs-list.blade.php`

### 3. Task ID numbers + activity markers
A per-shop task number ("#" column + detail header). The manager task list is now
clickable into the detail view. New "Updates" column shows a 💬 comment count and,
for finished tasks, who completed it.
- `database/migrations/2026_07_28_000001_add_ref_no_to_order_tasks_table.php` (new)
- `app/Models/OrderTask.php`, `app/Capability/Process/TasksCapability.php`
- `app/Http/Controllers/Panel/TaskApiController.php`, `resources/views/shell.blade.php`
- `tests/Feature/TaskRefNumberTest.php` (new)

### 4. Task completion confirmations (WhatsApp)
When a manually-assigned task is completed (app, link, or panel Done), the system
WhatsApps a receipt to the person who did it (echoing their note) and an alert to
your owner number(s) with their comment. Ad-hoc only; on/off via `task_done_alerts`
(default on).
- `app/Services/Ops/TaskEngine.php`
- `tests/Feature/TaskCompletionAlertTest.php` (new)

### 5. Assign a task from a customer  *(e.g. "collect 68 USD from Mehul")*
The customer page gets a **New task** button (assignee, optional amount-to-collect
folded into the title, due, priority). The task links to that customer, shows in a
new **Tasks tab** on the customer page and by name in the main Tasks list, and fires
the completion confirmation from #4 when done. Manager/owner only.
- `database/migrations/2026_07_28_000002_add_customer_id_to_order_tasks_table.php` (new)
- `app/Capability/Object/CustomerCapability.php`, `app/Services/Ops/TaskEngine.php`
- `app/Models/OrderTask.php`, `app/Capability/Process/TasksCapability.php`
- `tests/Feature/TaskCustomerLinkTest.php` (new)

### 6. Attach a photo or document to a task
Both "New task" forms (the Tasks screen and the customer page) now have a **Photo or
document (optional)** picker — a receipt, a photo of the item, a signed delivery
note, a PDF. Images and PDFs, up to 20 MB. The file is stored and shown on the task
detail page (thumbnail for images, "📎 View attached file" for docs), using the same
`attachment` field the completion flow already uses.
- `app/Support/Base64Upload.php` (new) — decodes the inline upload and stores it
- `app/Services/Ops/TaskEngine.php`  *(assignAdHoc gained an optional attachment)*
- `app/Capability/Process/TasksCapability.php`, `app/Capability/Object/CustomerCapability.php`

---

## Deploy — one pass

1. **Back up first.** Snapshot the DB (only two additive nullable columns on
   `order_tasks`) and make sure you can roll back the files.
2. **Copy the files** over your project root.
3. **Run migrations:**  `php artisan migrate`
4. **Clear caches:**  `php artisan config:clear && php artisan route:clear && php artisan view:clear`
5. **Restart the queue worker** (WhatsApp sends are queued):  `php artisan queue:restart`
6. **Verify (optional):**
   ```
   php artisan test --filter=PayablesCalculatorTest
   php artisan test --filter=TaskRefNumberTest
   php artisan test --filter=TaskCompletionAlertTest
   php artisan test --filter=TaskCustomerLinkTest
   ```

No config changes, no new services. Attachments are stored on the `public` disk — if
`storage/app/public` isn't already linked, run `php artisan storage:link` once (most
installs already have it, since task-completion photos use the same disk).

---

## Notes & rollback
- **Additive & reversible.** Both migrations' `down()` drop their column/index; the
  rest is restore-the-files. No existing data modified beyond filling `ref_no`.
- **Completion alerts** fire only for manually-assigned tasks; mute per shop with
  `\App\Models\Tenant::find(<id>)->putSetting('task_done_alerts', false);`
- **Customer tasks / amount** are a reminder inside the task title — no money is
  posted. Collecting the money is still recorded via the customer's "Record payment".
- **Attachments** accept images + PDF up to 20 MB; anything else is silently ignored
  (the task is still created).

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
app/Support/Base64Upload.php
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
