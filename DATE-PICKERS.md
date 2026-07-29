# Date/time fields are now pickers (no more typing YYYY-MM-DD)

Every place you used to type a date/time by hand now shows a proper **calendar + time picker**
(the app's form already supported these types — the fields were just set to plain text):

- **Campaigns** — "Schedule for" → date & time picker (the screen you flagged).
- **Cashbook** — entry "Date & time" → date & time picker.
- **Scheduled orders** — "Deliver at" / "New time" → date & time picker.
- **Jobs / Work orders** — "Scheduled" → date & time picker.
- **Leads** — "Next follow-up" → date picker.
- **Demand plans** — "Dispatch date" → date picker.
  (Procurement "Expected delivery" was already a date picker.)

Saving is unchanged — every one of these already parses the value with Carbon, which reads the
picker's format fine. Deploy: copy files, then `php artisan config:clear && view:clear`, `queue:restart`.
No migration.
