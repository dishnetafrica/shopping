<?php declare(strict_types=1);

namespace App\Providers;

use App\Models\Order;
use App\Models\LedgerEntry;
use App\Observers\LedgerEntryObserver;
use App\Observers\OrderLedgerObserver;
use CloudBSS\App\CashSettlementPolicy;
use CloudBSS\App\CogsCorrectionPolicy;
use CloudBSS\App\SaleFxCorrectionPolicy;
use CloudBSS\App\CompositeAccountingPolicy;
use CloudBSS\App\InterestPolicy;
use CloudBSS\App\UgandaVatPurchasePolicy;
use CloudBSS\App\UgandaVatSalesPolicy;
use CloudBSS\Infra\InterestRun;
use CloudBSS\Infra\LedgerPoster;
use CloudBSS\Infra\PaymentLedger;
use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Infra\Postgres\PostgresLedgerProjection;
use CloudBSS\Infra\Postgres\PostgresSubledgerProjection;
use CloudBSS\Infra\PurchaseLedger;
use CloudBSS\Infra\Reporting\Aging;
use CloudBSS\Infra\Reporting\Statement;
use CloudBSS\Infra\Reporting\TrialBalance;
use CloudBSS\Infra\SalesLedger;
use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\AccountingPolicy;
use CloudBSS\Kernel\Ledger\AccountingPolicyResolver;
use CloudBSS\Kernel\Support\RandomIdGenerator;
use CloudBSS\Kernel\Support\SystemClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the CloudBSS event-sourced ledger into ShopBot and auto-posts delivered orders.
 * Register in bootstrap/providers.php. Uses the existing `pgsql` connection (evo_saaspg);
 * posting is opt-in per tenant via the `ledger_enabled` setting.
 */
class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LedgerPoster::class, function () {
            $pdo   = DB::connection('pgsql')->getPdo();
            $clock = new SystemClock();
            $ids   = new RandomIdGenerator();

            // Uganda accounting rules (sales+purchase VAT, cash settlement, overdue interest) applied to
            // EVERY tenant/entity — books stay separated by the (tenant, entity) scope in the ledger,
            // while the posting rules are shared. Different tax regimes = a different resolver branch.
            $policy = new CompositeAccountingPolicy([
                new UgandaVatSalesPolicy($clock, $ids),
                new UgandaVatPurchasePolicy($clock, $ids),
                new CashSettlementPolicy($clock, $ids),
                new InterestPolicy($clock, $ids),
                // Phase 3B: handles the 'CogsCorrected' event only (Dr 1200 Inventory / Cr 5000 COGS for
                // the overstatement). Adds a NEW event name — never alters existing sale/purchase/payment
                // postings. The wildcard resolver above applies it to every book, so cogs:remediate can
                // correct any affected tenant (e.g. Family Shoppers t_17/e1). Nothing posts until a
                // 'CogsCorrected' event is authored, i.e. only when `cogs:remediate --confirm` is run.
                new CogsCorrectionPolicy($clock, $ids),
                // Handles 'SaleFxCorrected' — reverses the revenue-leg overstatement (Dr 4000 / Dr 2200
                // / Cr 1100) for orders whose sell-currency net was booked as base without conversion.
                // New event name only; posts nothing until sales:remediate --confirm authors the event.
                new SaleFxCorrectionPolicy($clock, $ids),
            ]);
            $resolver = new class($policy) implements AccountingPolicyResolver {
                public function __construct(private AccountingPolicy $p) {}
                public function policyFor(DomainEvent $e): ?AccountingPolicy { return $this->p; }
            };

            return new LedgerPoster(
                $pdo, new PostgresEventLog($pdo), new PostgresLedgerProjection($pdo), $clock, $ids, $resolver,
                new PostgresSubledgerProjection($pdo)
            );
        });

        $this->app->singleton(SalesLedger::class,    fn ($a) => new SalesLedger($a->make(LedgerPoster::class)));
        $this->app->singleton(PurchaseLedger::class, fn ($a) => new PurchaseLedger($a->make(LedgerPoster::class)));
        $this->app->singleton(PaymentLedger::class,  fn ($a) => new PaymentLedger($a->make(LedgerPoster::class)));
        $this->app->singleton(InterestRun::class,    fn ($a) => new InterestRun(DB::connection('pgsql')->getPdo(), $a->make(LedgerPoster::class)));
        $this->app->singleton(TrialBalance::class,   fn () => new TrialBalance(DB::connection('pgsql')->getPdo()));
        $this->app->singleton(Aging::class,          fn () => new Aging(DB::connection('pgsql')->getPdo()));
        $this->app->singleton(Statement::class,      fn () => new Statement(DB::connection('pgsql')->getPdo()));
    }

    public function boot(): void
    {
        // Auto-post delivered orders + customer receipts. Runs alongside the existing observers.
        Order::observe(OrderLedgerObserver::class);
        LedgerEntry::observe(LedgerEntryObserver::class);
    }
}
