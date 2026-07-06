# ShopBot → CloudBSS ledger — panel-configured, with a Books dashboard

Delivered orders and customer payments auto-post into event-sourced double-entry books, per
customer (aged). Sellers turn it on in Settings and view everything on the new Books page — no
commands per tenant.

## Files (drop into ShopBot at these paths)
- app/Filament/Pages/Settings.php                    MODIFIED — adds "Accounting / Ledger" settings
- app/Filament/Pages/Books.php                       NEW — the Books dashboard page
- resources/views/filament/pages/books.blade.php     NEW — its view (trial balance, aging, P&L)
- app/Services/Ledger/OrderLedgerService.php         maps Order→sale, LedgerEntry→receipt
- app/Observers/OrderLedgerObserver.php              posts the SALE on delivery
- app/Observers/LedgerEntryObserver.php              posts a RECEIPT on each order_payment
- app/Providers/LedgerServiceProvider.php            binds services + registers observers

## One-time setup (per install)
1. Autoload the kernel (path repo or copy src/ app/ infra/ + PSR-4) → `composer dump-autoload`.
2. `php artisan migrate` (copy infra/Laravel/create_cloudbss_ledger_migration.php first).
3. Register App\Providers\LedgerServiceProvider in bootstrap/providers.php.

## Per tenant — all in the panel, no commands
- **Settings → Accounting / Ledger:** turn on "Keep automatic books", set "prices include VAT",
  VAT rate, payment terms. Kernel book ids derive automatically (t_{tenantId}/e1), books stay separate.
- **Books page** (new nav item): net profit, receivables/payables, balanced status, debtor & creditor
  aging tables, and the full trial balance — read straight from the ledger projection (cheap queries).

## Flow (unchanged, all automatic)
- Sale on delivery: Dr AR, Cr Sales, Cr VAT, Dr COGS, Cr Inventory (net = total ÷ (1+VAT%)).
- Receipt on each order_payment: Dr Cash, Cr AR, applied to that order (partial payments OK).
- Idempotent + best-effort; books toggle off = orders flow exactly as before.

## Still manual (wire when ready)
- Purchases → PurchaseLedger (stock-in/GRN). Monthly interest → schedule InterestRun::run(...).
- A per-customer Statement PDF/WhatsApp send (Statement::forParty is ready) — easy next add.

## Test on staging
Enable books for a tenant, deliver a test order + take a part-payment, open the Books page:
net profit shows, the customer appears under Debtors with the right bucket, and the trial balance
reads "Balanced". Click through the Settings section too (confirm it saves).
