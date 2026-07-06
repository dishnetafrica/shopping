<?php declare(strict_types=1);

namespace App\Providers;

/**
 * Copy this into ShopBot's app/Providers/ and register it in bootstrap/providers.php
 * (or config/app.php providers). It binds the CloudBSS SalesLedger as a singleton wired to your
 * existing Postgres, so anywhere in ShopBot you can:
 *
 *     app(\CloudBSS\Infra\SalesLedger::class)->recordSale('t_ug','e1',(string)$order->id, $netMinor, $cogsMinor, 'UGX');
 *
 * Register each tenant's accounting policy in the resolver below as you onboard them.
 */

use CloudBSS\App\UgandaVatSalesPolicy;
use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Infra\Postgres\PostgresLedgerProjection;
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
        $this->app->singleton(SalesLedger::class, function () {
            $pdo   = DB::connection('pgsql')->getPdo();      // reuse ShopBot's Postgres (evo_saaspg)
            $clock = new SystemClock();
            $ids   = new RandomIdGenerator();                // unique ids across requests

            $resolver = new RegistryAccountingPolicyResolver();
            // Patronics (kernel tenant 't_ug' / entity 'e1') -> Uganda 18% output VAT.
            $resolver->register('t_ug', 'e1', new UgandaVatSalesPolicy($clock, $ids));
            // Add more tenants/policies here as you onboard them, e.g. DishNet, WinWorld.

            return new SalesLedger(
                $pdo,
                new PostgresEventLog($pdo),
                new PostgresLedgerProjection($pdo),
                $clock,
                $ids,
                $resolver,
            );
        });
    }
}
