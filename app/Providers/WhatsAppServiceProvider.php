<?php
namespace App\Providers;

use App\Contracts\WhatsAppGateway;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(WhatsAppManager::class);

        // Default binding resolves the global default driver.
        $this->app->bind(WhatsAppGateway::class, fn ($app) => $app->make(WhatsAppManager::class)->driver());
    }
}
