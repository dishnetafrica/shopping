<?php declare(strict_types=1);

namespace App\Providers;

use App\Models\Order;
use App\Models\LedgerEntry;
use App\Observers\LedgerEntryObserver;
use App\Observers\OrderLedgerObserver;
use CloudBSS\App\CashSettlementPolicy;
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
use CloudBSS\Kernel\Ledger\RegistryAccountingPolicyResolver;
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

            $resolver = new RegistryAccountingPolicyResolver();
            // Patronics (kernel 't_ug' / 'e1'): sales + purchase VAT + cash settlement + overdue interest.
            $resolver->register('t_ug', 'e1', new CompositeAccountingPolicy([
                new UgandaVatSalesPolicy($clock, $ids),
                new UgandaVatPurchasePolicy($clock, $ids),
                new CashSettlementPolicy($clock, $ids),
                new InterestPolicy($clock, $ids),
            ]));
            // Register more tenants/entities here as you onboard them.

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
