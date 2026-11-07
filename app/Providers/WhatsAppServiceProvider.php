<?php
namespace App\Providers;

use App\Contracts\WhatsAppGateway;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    public function boot(): void
    {
        // Make tenant isolation structural, not a convention every job has to remember.
        //
        // TenantContext is a per-process singleton, so on a long-lived queue worker (and within a
        // console process) the tenant id — and the super-admin bypass — set by one unit of work
        // lingers into the next. Today every scoped job sets its own tenant first, so nothing leaks;
        // but that safety is discipline, not design. Reset BOTH axes at every job and command
        // boundary: a unit of work that forgets to set() then reads nothing (an empty, safe
        // `tenant_id = NULL` scope) instead of inheriting the previous tenant or a stale super-admin
        // bypass. Jobs still set() their own tenant at the top of handle(); this only clears the slate
        // around them. Sync-dispatched jobs fire these same events, so CI (QUEUE_CONNECTION=sync)
        // exercises the hook too.
        $reset = function () {
            $this->app->make(TenantContext::class)->reset();
        };
        Queue::before($reset);
        Queue::after($reset);
        Queue::failing($reset);
        Event::listen(CommandFinished::class, $reset);
    }
}
