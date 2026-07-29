# Family Shoppers — switch on live stock (the "digital employee")

**Goal:** make stock update itself — up when you **Buy product** / receive, down automatically when a
sale is **invoiced/delivered** — with nobody updating anything by hand. This is done by turning on the
**Stock Ledger**. It's safe and reversible: there's a hard parity check before you flip the switch, and
turning it off is two settings with no data lost.

**Who runs this:** your developer, on the server (these are `php artisan` commands + two settings). It
takes ~15 minutes at a quiet time of day.

---

## Before you start — find the tenant id
Family Shoppers has a tenant id (a number). Find it once:
```
php artisan tinker
>>> \App\Models\Tenant::where('name','like','%Family Shoppers%')->value('id');
```
Use that number wherever this runbook says `<ID>`.

## Step 0 — confirm the tools are present
```
php artisan list | grep stock:
# expect: stock:reconcile, stock:backfill, stock:watch
```
(`stock:watch` ships in this package; the other two are already in your system.)

## Step 1 — baseline (read-only, changes nothing)
```
php artisan stock:reconcile <ID>
```
Note what it shows. With the ledger still off you'll typically see some Juba drift — that's expected and
Step 2 handles it.

## Step 2 — seed today's stock as opening balances (safe, re-runnable)
```
php artisan stock:backfill <ID>
```
This writes one opening balance per stocked product (Kampala) and per consignment line's remaining qty
(Juba). Re-running it is harmless.

## Step 3 — THE SAFETY GATE: prove Kampala parity
```
php artisan stock:reconcile <ID> --location=kampala
```
**Do not go further until this says `PARITY OK`.** If it lists drifting products, the fixes are in
`DEPLOY-STOCK-LEDGER-ENABLE.txt` (the most common one: re-run `stock:backfill <ID>` for bulk-imported
products). Re-run until Kampala is clean.

## Step 4 — flip the switch (quiet window)
```
php artisan tinker
>>> $t = \App\Models\Tenant::find(<ID>);
>>> $t->putSetting('stock_ledger', true);
>>> $t->putSetting('stock_v2', true);
>>> $t->putSetting('stock_issue_on_delivery', true);
```
From this moment: **Buy product / receive add stock, and every invoiced/delivered sale removes it — automatically.**

## Step 5 — verify it live
```
php artisan stock:reconcile <ID>
```
Then in the app: receive a little stock, invoice a sale of it, reopen Juba Stock — on-hand should move
both ways on its own. (The old `products.stock` column now lags because sales don't touch it — that's
expected; the ledger is the truth.)

## Step 6 — keep it honest (the watchdog)
Add this one line in `routes/console.php` so drift is caught the same day and WhatsApped to you:
```php
Schedule::command('stock:watch')->dailyAt('06:00');
```
`stock:watch` is dormant until Step 4, then checks parity daily and messages your owner-alert number if
the live stock and the ledger ever disagree. (It uses the same owner-alert number as your other alerts.)

---

## Rollback (instant, nothing lost)
```
php artisan tinker
>>> $t = \App\Models\Tenant::find(<ID>);
>>> $t->putSetting('stock_v2', false);
>>> $t->putSetting('stock_ledger', false);
```
On-hand reads fall straight back to the old numbers. The ledger rows stay (harmless) and can be reused
next time.

## What this unlocks (why it's worth it)
- **Buy product** (supplier screen) → stock up, linked to the product, cost updated.
- **Receive** in Juba → lands in Juba on-hand.
- **Invoice / deliver a sale** → stock comes down by itself. No manual updates, no one forgetting.
- Live, trustworthy on-hand everywhere — products list, the WhatsApp bot, and reports — and
  "purchased − sold = on hand" is finally real.
