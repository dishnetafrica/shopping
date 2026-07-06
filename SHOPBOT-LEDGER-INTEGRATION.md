# ShopBot → CloudBSS ledger — panel-configured, Books dashboard, WhatsApp statements

Delivered orders and customer payments auto-post into event-sourced double-entry books, per
customer (aged). Sellers turn it on in Settings, view everything on the Books page, and send a
debtor their statement on WhatsApp with one click. No commands per tenant.

## Files (drop into ShopBot at these paths)
- app/Filament/Pages/Settings.php                    MODIFIED — "Accounting / Ledger" settings section
- app/Filament/Pages/Books.php                       NEW — Books dashboard + Send-statement action
- resources/views/filament/pages/books.blade.php     NEW — trial balance, aging, P&L, statement buttons
- app/Services/Ledger/OrderLedgerService.php         Order→sale, LedgerEntry→receipt
- app/Services/Ledger/StatementMessenger.php         NEW — formats + sends a statement on WhatsApp
- app/Observers/OrderLedgerObserver.php              posts the SALE on delivery
- app/Observers/LedgerEntryObserver.php              posts a RECEIPT on each order_payment
- app/Providers/LedgerServiceProvider.php            binds services + registers observers

## One-time setup (per install)
1. Autoload the kernel (path repo or copy src/ app/ infra/ + PSR-4) → `composer dump-autoload`.
2. `php artisan migrate` (copy infra/Laravel/create_cloudbss_ledger_migration.php first).
3. Register App\Providers\LedgerServiceProvider in bootstrap/providers.php.

## Per tenant — all in the panel
- **Settings → Accounting / Ledger:** turn on books, prices-include-VAT, VAT rate, payment terms.
  Book ids derive automatically (t_{tenantId}/e1); books stay separate per tenant.
- **Books page:** net profit, receivables/payables, balanced status, debtor & creditor aging, trial
  balance. Each debtor row has a **Statement** button → sends that customer their account statement
  on WhatsApp (open items, overdue tags, total due) via the tenant's existing WhatsApp channel.

## Flow (automatic)
- Sale on delivery: Dr AR, Cr Sales, Cr VAT, Dr COGS, Cr Inventory (net = total ÷ (1+VAT%)).
- Receipt on each order_payment: Dr Cash, Cr AR, applied to that order.
- Statement send: reuses Statement::forParty (reconciles to the GL) + your WhatsAppManager sender.
- All idempotent + best-effort; books toggle off = orders flow exactly as before.

## Still manual (wire when ready)
- Purchases → PurchaseLedger (stock-in/GRN). Monthly interest → schedule InterestRun::run(...).

## Test on staging
Enable books, deliver a test order + take a part-payment, open Books: net profit shows, the
customer is under Debtors in the right bucket, trial balance reads Balanced. Click **Statement** on
the debtor row → the customer gets a WhatsApp statement. Confirm the Settings section saves too.
