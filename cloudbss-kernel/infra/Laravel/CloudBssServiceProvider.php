<?php declare(strict_types=1);

namespace App\Providers;

/**
 * Copy into ShopBot's app/Providers/ and register it. Binds the CloudBSS ledger services as
 * singletons wired to your existing Postgres (evo_saaspg). Anywhere in ShopBot:
 *
 *   app(\CloudBSS\Infra\SalesLedger::class)->recordSale('t_ug','e1',(string)$order->id, $netMinor, $cogsMinor, 'UGX');
 *   app(\CloudBSS\Infra\PurchaseLedger::class)->recordPurchase('t_ug','e1',(string)$bill->id, $netMinor, 'UGX');
 *   $tb = app(\CloudBSS\Infra\Reporting\TrialBalance::class)->trialBalance('t_ug','e1','UGX');
 *
 * A tenant's books can span several posting rules (sales VAT + purchase VAT + manufacturing…) via
 * CompositeAccountingPolicy — add more sub-policies as you onboard domains, kernel untouched.
 */

use CloudBSS\App\CompositeAccountingPolicy;
use CloudBSS\App\CogsCorrectionPolicy;
use CloudBSS\App\CashSettlementPolicy;
use CloudBSS\App\InterestPolicy;
use CloudBSS\App\UgandaVatPurchasePolicy;
use CloudBSS\App\UgandaVatSalesPolicy;
use CloudBSS\Infra\LedgerPoster;
use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Infra\Postgres\PostgresLedgerProjection;
use CloudBSS\Infra\Postgres\PostgresSubledgerProjection;
use CloudBSS\Infra\InterestRun;
use CloudBSS\Infra\PaymentLedger;
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

class CloudBssServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The shared write path (lock + idempotency + persist + project).
        $this->app->singleton(LedgerPoster::class, function () {
            $pdo   = DB::connection('pgsql')->getPdo();      // reuse ShopBot's Postgres (evo_saaspg)
            $clock = new SystemClock();
            $ids   = new RandomIdGenerator();

            $resolver = new RegistryAccountingPolicyResolver();
            // Patronics (kernel tenant 't_ug' / entity 'e1'): sales + purchase VAT in one book.
            $resolver->register('t_ug', 'e1', new CompositeAccountingPolicy([
                new UgandaVatSalesPolicy($clock, $ids),
                new UgandaVatPurchasePolicy($clock, $ids),
                new CashSettlementPolicy($clock, $ids),
                new InterestPolicy($clock, $ids),
                new CogsCorrectionPolicy($clock, $ids),   // Phase 3B: compensating COGS-FX entries
            ]));
            // Register more tenants/entities here (DishNet, WinWorld, …) as you onboard them.
            // NOTE: this Registry-resolver provider is a REFERENCE example and is not the one booted by
            // ShopBot — app/Providers/LedgerServiceProvider.php is (see bootstrap/providers.php). That
            // one uses a wildcard resolver that applies its CompositeAccountingPolicy to EVERY book, and
            // is where CogsCorrectionPolicy is added so cogs:remediate can correct any tenant.

            return new LedgerPoster(
                $pdo, new PostgresEventLog($pdo), new PostgresLedgerProjection($pdo), $clock, $ids, $resolver,
                new PostgresSubledgerProjection($pdo)   // per-party AR/AP aging
            );
        });

        $this->app->singleton(SalesLedger::class,    fn ($app) => new SalesLedger($app->make(LedgerPoster::class)));
        $this->app->singleton(PurchaseLedger::class, fn ($app) => new PurchaseLedger($app->make(LedgerPoster::class)));
        $this->app->singleton(PaymentLedger::class,  fn ($app) => new PaymentLedger($app->make(LedgerPoster::class)));
        $this->app->singleton(TrialBalance::class,   fn () => new TrialBalance(DB::connection('pgsql')->getPdo()));
        $this->app->singleton(Aging::class,          fn () => new Aging(DB::connection('pgsql')->getPdo()));
        $this->app->singleton(Statement::class,      fn () => new Statement(DB::connection('pgsql')->getPdo()));
        $this->app->singleton(InterestRun::class,    fn ($app) => new InterestRun(DB::connection('pgsql')->getPdo(), $app->make(LedgerPoster::class)));
    }
}
