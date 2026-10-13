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

        // Asset Lifecycle · S1c-3 — freeze a line's product spec summary onto invoice/quote lines at creation.
        \App\Models\InvoiceItem::observe(\App\Observers\LineSpecSnapshotObserver::class);
        \App\Models\QuotationItem::observe(\App\Observers\LineSpecSnapshotObserver::class);

        // v4.45 — quotation numbers that run in order (QUO-2026-0001), for tenants who switch it on.
        //
        // Registered HERE, and deliberately AFTER the observers above, for one reason: `creating`
        // listeners fire in registration order, and the number has to be ours last. Whatever minted
        // `quote_no` before this point — the frozen QuotationService, the storefront, the bot — set
        // the attribute; this fires immediately before the INSERT and replaces it. That makes every
        // creation path pick up the new series without touching any of them.
        //
        // Only when the switch is on, only when a tenant can be resolved, and never fatal: a
        // quotation that cannot be numbered keeps whatever number it already had rather than
        // failing to save. A customer-facing document is worth more than a tidy sequence.
        // v4.49 — campaigns get CMP-2026-0001 from today onwards. No switch and no legacy mode:
        // campaigns never had numbering, so there is nothing to preserve and nothing to opt into.
        // Everything already sent keeps campaign_no null and is left exactly as it was.
        \App\Models\Campaign::creating(function (\App\Models\Campaign $c) {
            try {
                if (trim((string) ($c->campaign_no ?? '')) !== '') {
                    return;                                  // already numbered (a restore, an import)
                }
                $t = $c->tenant_id ? \App\Models\Tenant::find($c->tenant_id) : null;
                if ($t) {
                    $c->campaign_no = \App\Services\Marketing\CampaignNumber::next($t);
                }
            } catch (\Throwable $e) {
                // A campaign that cannot be numbered still saves. Losing the send would be worse
                // than losing the reference.
                \Log::warning('CampaignNumber: saved without a number — ' . $e->getMessage());
            }
        });

        \App\Models\Quotation::creating(function (\App\Models\Quotation $q) {
            try {
                $t = $q->tenant_id ? \App\Models\Tenant::find($q->tenant_id) : null;
                if (! $t || ! \App\Services\Sales\QuoteNumber::sequential($t)) {
                    return;
                }
                $q->quote_no = \App\Services\Sales\QuoteNumber::next($t);
            } catch (\Throwable $e) {
                \Log::warning('QuoteNumber: kept the minted number — ' . $e->getMessage());
            }
        });

        Route::middleware(['web', 'auth', SetTenantFromUser::class, \App\Http\Middleware\ConfineTransportRole::class])->group(function () {
            // The new shell (SPA). Hash-routed, so one entry point serves every workspace.
            Route::get('/v3/{path?}', fn () => view('shell'))->where('path', '.*');

            Route::prefix('api/v1/workspace')->group(function () {
                // v4.91 — a live CSRF token for the SPA, which otherwise keeps the one it read at
                // page load until the staff member reloads. Inside this auth group on purpose: a
                // dead session 401s here, so one call answers both "is my token stale?" and "am I
                // still signed in?" — and the shell can tell a retryable failure from a real one.
                // Declared before the /{type} catch-all; "_token" cannot match [a-zA-Z.:]+ anyway.
                Route::get('/_token', fn () => response()->json(['ok' => true, 'token' => csrf_token()]));
                Route::get('/', [WorkspaceController::class, 'index']);
                Route::get('/{type}/{id?}', [WorkspaceController::class, 'show'])->where('type', '[a-zA-Z.:]+');
                Route::post('/{type}/{id}/{action}', [CommandController::class, 'dispatch'])->where('type', '[a-zA-Z.:]+');
            });

            // Direct PDF streaming for docs without a legacy-panel route (receipt, price list).
            Route::prefix('api/v1/doc')->group(function () {
                Route::get('receipt/{id}', [\App\Http\Controllers\V3\DocumentController::class, 'receipt'])->whereNumber('id');
                Route::get('delivery-note/{id}', [\App\Http\Controllers\V3\DocumentController::class, 'deliveryNote'])->whereNumber('id');
                Route::get('commercial-invoice/{id}', [\App\Http\Controllers\V3\DocumentController::class, 'commercialInvoice'])->whereNumber('id');
                Route::get('packing-list/{id}', [\App\Http\Controllers\V3\DocumentController::class, 'packingList'])->whereNumber('id');
                Route::get('price-list', [\App\Http\Controllers\V3\DocumentController::class, 'priceList']);
                Route::get('catalog', [\App\Http\Controllers\V3\DocumentController::class, 'catalog']);
                Route::get('cashbook-export', [\App\Http\Controllers\V3\DocumentController::class, 'cashbookExport']);
                Route::get('purchases-export', [\App\Http\Controllers\V3\DocumentController::class, 'purchasesExport']);
                Route::get('supplier-statement', [\App\Http\Controllers\V3\DocumentController::class, 'supplierStatement']);
                Route::get('lpo/{id}', [\App\Http\Controllers\V3\DocumentController::class, 'lpo'])->whereNumber('id');
                Route::get('customer-statement', [\App\Http\Controllers\V3\DocumentController::class, 'customerStatement']);
                Route::get('products-export', [\App\Http\Controllers\V3\DocumentController::class, 'productsExport']);
                Route::get('spec-sheet', [\App\Http\Controllers\V3\DocumentController::class, 'specSheet']);
                Route::get('tasks-export', [\App\Http\Controllers\V3\DocumentController::class, 'tasksExport']);
                Route::get('juba-product', [\App\Http\Controllers\V3\DocumentController::class, 'jubaProduct']);
            });
        });
    }
}
