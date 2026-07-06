# ShopBot → CloudBSS ledger integration (sales + customer receipts)

Auto-posts delivered orders AND customer payments into the event-sourced ledger, per customer
(aged), reconciling to the general ledger. Opt-in per tenant.

## Files (drop into ShopBot at these paths)
- app/Services/Ledger/OrderLedgerService.php   maps Order → sale, and LedgerEntry → receipt
- app/Observers/OrderLedgerObserver.php        posts the SALE when an order becomes delivered/complete
- app/Observers/LedgerEntryObserver.php         posts a RECEIPT when an order_payment is recorded
- app/Providers/LedgerServiceProvider.php      binds ledger services + registers both observers

## Prerequisites (kernel repo)
1. Autoload the kernel (path repo or copy src/ app/ infra/ + PSR-4: CloudBSS\Kernel\, CloudBSS\App\,
   CloudBSS\Infra\), then `composer dump-autoload`.
2. `php artisan migrate` (copy infra/Laravel/create_cloudbss_ledger_migration.php first).
3. Register App\Providers\LedgerServiceProvider in bootstrap/providers.php.

## Per-tenant settings (Patronics = 7)
    $t=\App\Models\Tenant::find(7);
    $t->putSetting('ledger_enabled',true);
    $t->putSetting('ledger_tenant','t_ug');
    $t->putSetting('ledger_entity','e1');
    $t->putSetting('credit_terms_days',30);
    // prices_include_vat=true, quote_vat_pct=18 already set (used to back out net revenue).

## Flow
- **Sale** fires on delivery (revenue recognized): Dr AR, Cr Sales, Cr VAT, Dr COGS, Cr Inventory.
  net = order.total ÷ 1.18 (VAT-inclusive tenants); party = customer phone; due = delivered + terms.
- **Receipt** fires on each order_payment LedgerEntry (PanelApiController::recordPayment): Dr Cash,
  Cr AR, applied to that order's invoice. Handles partial payments (each entry settles more). It
  posts the sale first (idempotent) so the invoice exists before cash is applied — robust to ordering.
- Both idempotent (sale on order id, receipt on ledger-entry id) and best-effort (failures log, never
  block selling/payment). Verified against Postgres: sale 118,000 → 50k receipt → AR 68,000, and a
  repeat of the same payment is a no-op.

## Verify on staging
    // deliver an order, take a part-payment, then:
    dump(app(\CloudBSS\Infra\Reporting\TrialBalance::class)->trialBalance('t_ug','e1','UGX')['balanced']);  // true
    dump(app(\CloudBSS\Infra\Reporting\Statement::class)->forParty('t_ug','e1', $order->customer_phone));   // owed = total − paid

## Still manual (wire when ready)
- **Purchases / supplier bills** → PurchaseLedger (hook your stock-in/GRN).
- **Monthly interest** → schedule InterestRun::run('t_ug','e1', now(), 300).
- **Advance payments** (paid before delivery) post correctly in the GL (customer credit) but aren't
  auto-allocated in the subledger until the invoice exists — normal flow (deliver → pay) is fine.

## Safety
Per-tenant opt-in, cloudbss_* tables only. Set ledger_enabled=false and everything flows as before.
