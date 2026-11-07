<?php
namespace App\Providers;

use App\Contracts\WhatsAppGateway;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
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
        // would linger into the next. We SAVE the caller's state, RESET to a clean slate for the
        // unit of work, and RESTORE the caller's state when it finishes. That gives both guarantees:
        //
        //   - An INDEPENDENT job (async, its own worker process) starts with a clean, isolated
        //     context: a job that forgets to set() reads an empty `tenant_id = NULL` scope, never
        //     the previous tenant or a stale super-admin bypass.
        //   - A SYNC / inline job does NOT wipe the caller. With QUEUE_CONNECTION=sync (CI) or an
        //     explicit dispatchSync, the job runs inside the caller's process; e.g.
        //     OrderObserver::created() dispatches a notification and then continues into
        //     CustomerMasterService::ensureFromOrder(), which must keep the request's tenant. The
        //     restore hands that tenant back after the inline job returns.
        //
        // A stack handles nested dispatch. after()=JobProcessed (success) and JobExceptionOccurred
        // (failure) are mutually exclusive, so exactly one restore runs per job, even on exceptions;
        // CommandStarting/Finished do the same for console commands (and Artisan::call nesting).
        $stack = [];
        $push = function () use (&$stack) {
            $ctx = $this->app->make(TenantContext::class);
            $stack[] = $ctx->snapshot();
            $ctx->reset();
        };
        $pop = function () use (&$stack) {
            if ($stack === []) {
                return;
            }
            $this->app->make(TenantContext::class)->restore(array_pop($stack));
        };
        Queue::before($push);
        Queue::after($pop);
        Event::listen(JobExceptionOccurred::class, $pop);
        Event::listen(CommandStarting::class, $push);
        Event::listen(CommandFinished::class, $pop);
    }
}
