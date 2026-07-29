# CloudBSS updates — deploy guide (for your developer)

Add these files into the GitHub repo, then **Rebuild** in EasyPanel and run the post-deploy commands
at the bottom.

## ⚠️ Read first — your live code is AHEAD of this copy in one place
We confirmed (from the live Settings screen) that your production **Settings** file has tabs this copy
doesn't (Notifications, Storefront, Delivery, Payments). So the Settings file has been **left out of this
zip on purpose** — overwriting it would delete those tabs.

Because of that, when you add these files in GitHub, **review the diff** before committing. Almost all of
this session's changes have already deployed cleanly (Buy product, Books, delivery fix, etc.), so only the
Settings file was actually diverged — but a quick diff review is the safe habit.

## What's in this zip (all verified to compile)
**New files (safe to add):**
- `resources/panel/situation-report.html` — the visual "Books" report page
- `app/Console/Commands/StockWatchCommand.php` — stock parity watchdog (`stock:watch`)
- `app/Support/Base64Upload.php` — file-attachment helper for tasks
- `database/migrations/2026_07_28_000001_*`, `..._000002_*` — task ref-no + customer link

**Edited files (review diff, then overwrite):**
- **Quotation PDF fix (new this round):** `app/Capability/Object/QuotationCapability.php` — the Documents-tab link now opens the PDF via `/papi/quotation-pdf` instead of the broken storage URL.
- Bot: `app/Services/Bot/AiBrain.php` (big-order handling + Juba-store delivery language)
- Supplier "Buy product": `app/Capability/Object/SupplierCapability.php`, `app/Http/Controllers/Panel/OrderCostController.php`
- Date pickers: `CampaignListCapability`, `CashbookCapability`, `ScheduledOrdersCapability`, `WorkOrderCapability`, `WorkOrderListCapability`, `LeadListCapability`, `DemandPlanListCapability`
- Finance fixes: `MoneyTimingCapability` (cash tile all-currencies), `PayablesCalculator`, `SupplierController`, `SupplierListCapability`
- Books menu: `resources/views/shell.blade.php`, `PanelApiController.php`
- Tasks: `TasksCapability`, `CustomerCapability`, `TaskEngine`, `OrderTask`, `TaskApiController`, `CashbookDigestCommand`, `TaskDigestCommand`, `TaskReminderCommand`
- `CoreCapabilityProvider.php`, `bootstrap/app.php`, `routes/web.php`

## NOT in this zip (do by hand)
**Bot "Item suggestions" on/off toggle** — needs 2 small edits to your *current* Settings files (not the
stale ones), so it's excluded. Meanwhile, turn suggestions off with this URL while logged in as owner:
`mycloudbss.com/papi/bot-toggle?key=combo_recommendations&on=0`  (…&on=1 to re-enable).
Ask for the paste-in snippet when you want the in-app switch.

## Post-deploy commands
```
php artisan migrate            # applies the two task migrations
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan queue:restart
```
Notes: `stock:watch` stays dormant until the stock ledger is enabled (see the runbook in
`_deploy-notes/`). Nothing here changes money or deletes data. The per-feature notes from this session
are collected in `_deploy-notes/` for reference.
