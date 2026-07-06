# Wiring the sales→ledger slice into ShopBot (Laravel)

The kernel + adapter are framework-agnostic; ShopBot uses them as a library. When an order is
**confirmed/paid**, ShopBot calls `SalesLedger::recordSale(...)` and a balanced, durable, auditable
ledger entry appears — using the same `UgandaVatSalesPolicy` behind the Patronics quotations.

## 1. Bind `SalesLedger` in a service provider

```php
use CloudBSS\Infra\SalesLedger;
use CloudBSS\Infra\Postgres\{PostgresEventLog, PostgresLedgerProjection};
use CloudBSS\Kernel\Ledger\RegistryAccountingPolicyResolver;
use CloudBSS\Kernel\Support\{SystemClock, RandomIdGenerator};
use CloudBSS\App\UgandaVatSalesPolicy;

$this->app->singleton(SalesLedger::class, function () {
    $pdo   = \DB::connection('pgsql')->getPdo();     // reuse ShopBot's Postgres
    $log   = new PostgresEventLog($pdo);
    $log->migrate();                                  // no-op once created; or run as a migration
    $ledger = new PostgresLedgerProjection($pdo);
    $clock = new SystemClock();
    $ids   = new RandomIdGenerator();

    // Register each tenant's accounting policy. Patronics (t_ug / e1) = Uganda 18% output VAT.
    $resolver = new RegistryAccountingPolicyResolver();
    $resolver->register('t_ug', 'e1', new UgandaVatSalesPolicy($clock, $ids));
    // add more tenants/policies here as you onboard them

    return new SalesLedger($pdo, $log, $ledger, $clock, $ids, $resolver);
});
```

(Add `CloudBSS\Infra\` → `infra/` to your composer autoload, alongside the kernel's
`CloudBSS\Kernel\` → `src/`.)

## 2. Call it when an order is confirmed

Wherever ShopBot marks an order paid/confirmed (order observer, payment webhook, or the
"confirm" step in the bot):

```php
app(\CloudBSS\Infra\SalesLedger::class)->recordSale(
    tenantId:     't_ug',                 // your kernel tenant id for this shop
    entityId:     'e1',                   // legal entity / book
    saleId:       (string) $order->id,    // stable order id (idempotency key)
    revenueMinor: $order->net_ugx,        // NET revenue, ex-VAT (see VAT note)
    costMinor:    $order->cogs_ugx,       // cost of goods sold
    currency:     'UGX',
    occurredAt:   $order->confirmed_at?->toDateTimeImmutable(),
);
```

`recordSale` is **idempotent** on `saleId`, so a double-tap "confirm", a retried webhook, or a
re-processed job never double-posts revenue.

## 3. VAT note (ties to the quotation fix)

Patronics catalogue prices are **VAT-inclusive**. `UgandaVatSalesPolicy` treats `revenue` as the
**net** amount and adds 18% on top to reach the gross (AR). So pass the **net**:

```
revenueMinor = round(order_inclusive_total / 1.18)
```

Then the ledger's AR (gross) equals the inclusive price the customer paid — consistent with the
quotation total. (If you ever store net prices instead, pass them directly and drop the /1.18.)

## 4. Reading balances (reports, dashboards)

```php
$sl = app(\CloudBSS\Infra\SalesLedger::class);
$ar  = $sl->balance('t_ug','e1','1100','UGX');   // Accounts Receivable
$vat = $sl->balance('t_ug','e1','2200','UGX');   // VAT Output Payable
$ok  = $sl->bookBalances('t_ug','e1');           // true if the book nets to zero
```

Balances are a straight indexed query on `ledger_balances` — no log replay per request.

## 5. Recovery / audit

The `ledger_balances` table is a pure projection. If it's ever doubted, rebuild it from the
immutable log:

```php
$proj = new \CloudBSS\Infra\Postgres\PostgresLedgerProjection($pdo);
$proj->rebuildFrom((new \CloudBSS\Infra\Postgres\PostgresEventLog($pdo))->load());
```

Every posting is explainable forever: the `events` table holds the original `DeliveryCompleted`
fact plus the derived `JournalPosted`, with correlation/causation ids linking them.

## Proven
`infra/tests/sales_slice_test.php` (20 assertions) drives two separate "requests" recording sales,
proves balances accumulate correctly, that a repeat confirm is idempotent, and that the book
rebuilds from the log. `infra/tests/persistence_test.php` (21) proves the rehydrate/recovery path.
Kernel stays byte-for-byte frozen (`tests/stress.php` 18/18).
```
```

## EasyPanel deploy (no new container)

The kernel is library code inside the ShopBot container, using your existing Postgres (evo_saaspg).
Nothing new to host. Steps:

1. **Autoload** — add to ShopBot's composer.json psr-4: `"CloudBSS\\Kernel\\":"vendor-or-path/src/"`,
   `"CloudBSS\\App\\":".../app/"`, `"CloudBSS\\Infra\\":".../infra/"` (or require the kernel as a path
   repo). Run `composer dump-autoload`.
2. **Tables** — copy `infra/Laravel/create_cloudbss_ledger_migration.php` into database/migrations/
   (add a timestamp prefix). `php artisan migrate` on deploy creates the prefixed cloudbss_* tables.
3. **Binding** — copy `infra/Laravel/CloudBssServiceProvider.php` into app/Providers/ and register it.
4. **Call it** — on order-confirmed:
   `app(\CloudBSS\Infra\SalesLedger::class)->recordSale('t_ug','e1',(string)$order->id,$netMinor,$cogsMinor,'UGX');`

Concurrency-safe: `recordSale` takes a Postgres transaction-scoped advisory lock on the sale key,
so two PHP-FPM workers confirming the same order at once serialize — no double-post. Different
sales don't contend. Idempotent on retries.

Tables are `cloudbss_*` prefixed so they can't collide with anything in the shared `evo` database.
For stricter isolation you can instead point a dedicated Laravel connection at a `cloudbss` schema
(set `search_path`) — optional; the prefix is enough to start.
