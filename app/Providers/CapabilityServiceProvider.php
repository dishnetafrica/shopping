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

        Route::middleware(['web', 'auth', SetTenantFromUser::class])->group(function () {
            // The new shell (SPA). Hash-routed, so one entry point serves every workspace.
            Route::get('/v3/{path?}', fn () => view('shell'))->where('path', '.*');

            Route::prefix('api/v1/workspace')->group(function () {
                Route::get('/', [WorkspaceController::class, 'index']);
                Route::get('/{type}/{id?}', [WorkspaceController::class, 'show'])->where('type', '[a-z.:]+');
                Route::post('/{type}/{id}/{action}', [CommandController::class, 'dispatch'])->where('type', '[a-z.:]+');
            });
        });
    }
}
