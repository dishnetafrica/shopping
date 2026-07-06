# ShopBot → CloudBSS ledger — configured from the seller panel (no commands)

Auto-posts delivered orders and customer payments into the event-sourced books, per customer
(aged). Every seller turns it on and tunes it from **Settings → Accounting / Ledger** — no tinker.

## Files (drop into ShopBot at these paths)
- app/Filament/Pages/Settings.php               MODIFIED — adds the "Accounting / Ledger" panel section
- app/Services/Ledger/OrderLedgerService.php    maps Order → sale, LedgerEntry → receipt
- app/Observers/OrderLedgerObserver.php         posts the SALE on delivery/complete
- app/Observers/LedgerEntryObserver.php         posts a RECEIPT on each order_payment
- app/Providers/LedgerServiceProvider.php       binds services + registers both observers

(Settings.php here already contains your Special Order section — it's the full file, safe to overwrite.)

## One-time setup (per install, still needed)
1. Autoload the kernel (path repo or copy src/ app/ infra/ + PSR-4 CloudBSS\Kernel\, CloudBSS\App\,
   CloudBSS\Infra\) then `composer dump-autoload`.
2. `php artisan migrate` (copy infra/Laravel/create_cloudbss_ledger_migration.php first) — creates
   the cloudbss_* tables.
3. Register App\Providers\LedgerServiceProvider in bootstrap/providers.php.

That's the only command-line part, and it's per install — not per tenant.

## Per-tenant: all from the panel
Seller opens **Settings → Accounting / Ledger** and sets:
- **Keep automatic books** (on/off)
- **My prices already include VAT** (on for VAT-inclusive lists)
- **VAT rate** (e.g. 18)
- **Payment terms (days)** (drives overdue aging)

No kernel ids to enter — each tenant's book id is derived automatically (t_{tenantId}/e1) and kept
separate. Flip the toggle off and orders flow exactly as before.

## Flow (unchanged)
- Sale posts on delivery: Dr AR, Cr Sales, Cr VAT, Dr COGS, Cr Inventory. net = total ÷ (1+VAT%).
- Receipt posts on each order_payment: Dr Cash, Cr AR, applied to that order. Partial payments OK.
- Idempotent + best-effort. Verified against Postgres (sale 118,000 → 50k receipt → AR 68,000; and
  a fresh tenant book t_7 posts + balances).

## See the books (panel dashboards you can add next, or tinker to check)
    app(\CloudBSS\Infra\Reporting\TrialBalance::class)->trialBalance('t_7','e1','UGX');   // balanced
    app(\CloudBSS\Infra\Reporting\Aging::class)->byParty('t_7','e1','AR');                // debtors
    app(\CloudBSS\Infra\Reporting\Statement::class)->forParty('t_7','e1',$phone);         // statement
(t_7 = tenant id 7 = Patronics.)

## Still manual (wire when ready)
- Purchases → PurchaseLedger (stock-in/GRN). Monthly interest → schedule InterestRun::run(...).

## Safety
Per-tenant toggle, cloudbss_* tables only. Off = no effect.
