<?php

namespace App\Providers;

use App\Capability\Command\CommandBus;
use App\Capability\Http\CommandController;
use App\Capability\Http\WorkspaceController;
use App\Capability\Registry\CapabilityRegistry;
use App\Capability\Registry\CoreCapabilityProvider;
use App\Http\Middleware\SetTenantFromUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CapabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapabilityRegistry::class, function () {
            $registry = new CapabilityRegistry();
            (new CoreCapabilityProvider())->register($registry);
            // Plugins: discovered CapabilityProviders register + decorate here without core edits.
            $registry->assertDependencies();

            return $registry;
        });

        $this->app->singleton(CommandBus::class, fn ($app) => new CommandBus($app->make(CapabilityRegistry::class)));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(resource_path('views'), 'app');

        // Base-currency ledger: forward-stamp rate_to_usd + usd_amount on sales documents as they save,
        // so every new order/invoice/quotation carries its USD base amount (additive; never mutates the
        // transaction). Existing rows are filled by `php artisan sales:backfill-base-currency --commit`.
        \App\Models\Order::observe(\App\Observers\SalesBaseCurrencyObserver::class);
        \App\Models\Invoice::observe(\App\Observers\SalesBaseCurrencyObserver::class);
        \App\Models\Quotation::observe(\App\Observers\SalesBaseCurrencyObserver::class);

        Route::middleware(['web', 'auth', SetTenantFromUser::class, \App\Http\Middleware\ConfineTransportRole::class])->group(function () {
            // The new shell (SPA). Hash-routed, so one entry point serves every workspace.
            Route::get('/v3/{path?}', fn () => view('shell'))->where('path', '.*');

            Route::prefix('api/v1/workspace')->group(function () {
                Route::get('/', [WorkspaceController::class, 'index']);
                Route::get('/{type}/{id?}', [WorkspaceController::class, 'show'])->where('type', '[a-zA-Z.:]+');
                Route::post('/{type}/{id}/{action}', [CommandController::class, 'dispatch'])->where('type', '[a-zA-Z.:]+');
            });

            // Direct PDF streaming for docs without a legacy-panel route (receipt, price list).
            Route::prefix('api/v1/doc')->group(function () {
                Route::get('receipt/{id}', [\App\Http\Controllers\V3\DocumentController::class, 'receipt'])->whereNumber('id');
                Route::get('price-list', [\App\Http\Controllers\V3\DocumentController::class, 'priceList']);
                Route::get('catalog', [\App\Http\Controllers\V3\DocumentController::class, 'catalog']);
                Route::get('cashbook-export', [\App\Http\Controllers\V3\DocumentController::class, 'cashbookExport']);
                Route::get('purchases-export', [\App\Http\Controllers\V3\DocumentController::class, 'purchasesExport']);
                Route::get('supplier-statement', [\App\Http\Controllers\V3\DocumentController::class, 'supplierStatement']);
                Route::get('customer-statement', [\App\Http\Controllers\V3\DocumentController::class, 'customerStatement']);
                Route::get('products-export', [\App\Http\Controllers\V3\DocumentController::class, 'productsExport']);
                Route::get('spec-sheet', [\App\Http\Controllers\V3\DocumentController::class, 'specSheet']);
                Route::get('tasks-export', [\App\Http\Controllers\V3\DocumentController::class, 'tasksExport']);
            });
        });
    }
}
