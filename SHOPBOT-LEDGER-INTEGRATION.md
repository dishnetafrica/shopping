# ShopBot → CloudBSS ledger integration

Auto-posts a delivered ShopBot order into the event-sourced accounting kernel: AR + Sales +
output VAT + COGS, per customer (aged), reconciling to the general ledger. Opt-in per tenant.

## Files (drop into ShopBot, at these paths)
- app/Services/Ledger/OrderLedgerService.php   maps an Order → SalesLedger::recordSale
- app/Observers/OrderLedgerObserver.php        fires when an order becomes delivered/complete
- app/Providers/LedgerServiceProvider.php      binds ledger services + registers the observer

## Prerequisites (from the kernel repo)
1. Make the kernel autoloadable in ShopBot. Easiest: add it as a Composer path repo, or copy
   src/ + app/ + infra/ in and add PSR-4:
       "CloudBSS\\Kernel\\": "cloudbss-kernel/src/",
       "CloudBSS\\App\\":    "cloudbss-kernel/app/",
       "CloudBSS\\Infra\\":  "cloudbss-kernel/infra/"
   then `composer dump-autoload`.
2. Create the tables: copy cloudbss-kernel/infra/Laravel/create_cloudbss_ledger_migration.php into
   database/migrations/ (timestamp-prefixed) and `php artisan migrate`.
3. Register the provider in bootstrap/providers.php:  App\Providers\LedgerServiceProvider::class

## Per-tenant settings (Patronics = tenant 7)  — tinker
    $t = \App\Models\Tenant::find(7);
    $t->putSetting('ledger_enabled', true);      // turn posting ON for this tenant
    $t->putSetting('ledger_tenant', 't_ug');     // kernel tenant id
    $t->putSetting('ledger_entity', 'e1');       // kernel entity id
    $t->putSetting('credit_terms_days', 30);     // due date = delivered + 30d (0 = due now)
    // prices_include_vat (true) and quote_vat_pct (18) are already set — used to back out net revenue.

## How the mapping works
- Fires when an order becomes **delivered** (or customer-confirmed) — revenue recognition point.
- `revenueMinor` = NET: for VAT-inclusive tenants, order.total ÷ 1.18. Minor units respect the
  currency (UGX has 0 decimals → whole shillings; USD → ×100).
- `costMinor` = Σ(product.base_price × qty) for COGS; 0 if cost isn't tracked (entry still balances).
- `party` = customer phone, `partyName` = customer name → per-customer AR + aging + statements.
- Idempotent on order id, best-effort (a failure logs a warning; it never blocks the order).

## Verify on staging
    // deliver a test order, then:
    $tb = app(\CloudBSS\Infra\Reporting\TrialBalance::class)->trialBalance('t_ug','e1','UGX');
    dump($tb['balanced'], $tb['total_debit'], $tb['rows']);     // balanced === true
    $ar = app(\CloudBSS\Infra\Reporting\Aging::class)->byParty('t_ug','e1','AR');
    dump($ar);                                                   // the customer + amount owed

## Deliberately NOT auto-wired yet (do these once you confirm your payment flow)
- **Customer receipts** — when an order is paid, call
  app(\CloudBSS\Infra\PaymentLedger::class)->recordCustomerPayment('t_ug','e1',"pay_{$order->id}", $paidMinor,'UGX',null,$order->customer_phone);
  (left manual so COD vs credit isn't mis-posted before you confirm how ShopBot records payment).
- **Purchases / supplier bills** — PurchaseLedger is ready; wire it to your stock-in / GRN flow.
- **Monthly interest** — schedule InterestRun::run('t_ug','e1', now(), 300) for the 3%/month charge.

## Safety
Posting is per-tenant opt-in and fully isolated (cloudbss_* tables). If anything misbehaves, set
ledger_enabled=false and orders flow exactly as before — the ledger is a pure add-on projection.
