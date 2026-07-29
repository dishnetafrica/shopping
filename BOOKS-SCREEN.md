# Books screen — real financial statements (read-only)

Adds a **Books** screen under Finance that shows, live from the same reconciled
cashbook the rest of the app uses:

- **Profit & Loss** — including the director's **loan interest as a finance cost**
  (the plain cash P&L can't show this on its own).
- **Balance Sheet** — cash across **all three currencies (USD+UGX+SSP) valued in USD**,
  inventory, receivables, supplier payables, the loan, and equity. Assets = Liabilities + Equity.
- **Owners' equity** — real partner capital (lenders excluded).
- **Loan schedule** — each advance grown at the monthly rate, less any partial repayments.

It writes **nothing** to the ledger. The only writes are three config values saved by the
owner-only "Set stock value / lender" button.

## Files in this package
- `app/Capability/Process/BooksCapability.php`  (new)
- `app/Capability/Registry/CoreCapabilityProvider.php`  (adds ONE registration line)

If your server's `CoreCapabilityProvider.php` has diverged from this repo, don't overwrite it —
instead add just these two edits by hand:

1. Near the other `use App\Capability\Process\...Capability;` imports:
   ```php
   use App\Capability\Process\BooksCapability;
   ```
2. Right after the `finance` capability is registered:
   ```php
   $r->register(new BooksCapability(), new CapabilityManifest(key: 'books', type: 'process', owner: 'accounts', permission: 'menu:finance', commands: [['key' => 'save_config', 'actor' => 'human']], api: '/api/v1/workspace/books'));
   ```

## Deploy
```
# copy the two files, then:
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan queue:restart
```
No migration needed (config lives in the existing tenant settings JSON).

## First-run checklist (please verify once — it was built but not run against live data)
1. Open `mycloudbss.com/v3#/books` (it also appears in the Finance menu).
2. Confirm the numbers match the audited books:
   net profit ≈ **$2,327**, cash ≈ **$4,949** (all currencies), loan owed ≈ **$2,469**,
   equity ≈ **$2,037**, and the balance sheet shows **Assets = Liabilities + Equity ✓**.
3. Click **Set stock value / lender** and enter the inventory value (start with **3798.33**),
   lender name **Bhavin**, and rate **0.03**. Save and reload.

## Rollback (instant)
Remove the one `$r->register(new BooksCapability()...)` line (or delete the two files) and
`php artisan config:clear`. Nothing else is affected — it's a read-only screen.

## Also in this package: Finance "Cash on hand" tile now counts all currencies
- File: `app/Capability/Shared/MoneyTimingCapability.php`
- The Finance dashboard tile (and Morning Briefing) previously showed the **USD wallet only**,
  ignoring UGX + SSP cash. It now totals all three currencies in USD — the same figure as Books
  and the audit. **Expect the tile to jump** from ~$2,207 to ~$4,949, and Runway to recalculate
  accordingly. This is a display/reporting fix; no ledger data changes. The frozen `CashService`
  is untouched (the USD-total is done in the capability layer, per its design note).

## Notes
- **Inventory** is the manual value you set, until purchases are logged against products; then it
  can go live. Every other line is already live.
- After this fix, **Books cash and the Finance tile agree** (both all-currency USD).
