<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tests\TestCase;

/**
 * WS-3 — tenant-context isolation is structural, not by convention.
 *
 * TenantContext is a per-process singleton; on a long-lived queue worker the tenant id and the
 * super-admin bypass set by one job would otherwise linger into the next. WhatsAppServiceProvider
 * registers Queue::before/after/failing + CommandFinished hooks that call reset() at every
 * boundary, so a unit of work that forgets to set() reads an empty scope instead of the previous
 * tenant's data. These tests pin that contract. (Sync-dispatched jobs fire the same queue events,
 * so this runs under CI's QUEUE_CONNECTION=sync.)
 */
class TenantContextResetTest extends TestCase
{
    public function test_reset_clears_both_axes(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(9);
        $ctx->asSuperAdmin(true);

        $ctx->reset();

        $this->assertNull($ctx->id(), 'reset() must clear the tenant id');
        $this->assertFalse($ctx->isSuperAdmin(), 'reset() must clear the super-admin bypass');
    }

    public function test_clear_is_tenant_id_only_by_contract(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(3);
        $ctx->asSuperAdmin(true);

        $ctx->clear();

        $this->assertNull($ctx->id(), 'clear() clears the tenant id');
        $this->assertTrue($ctx->isSuperAdmin(), 'clear() must NOT touch the super-admin flag (use reset() for that)');

        $ctx->reset();
    }

    public function test_queue_boundary_resets_context_so_a_job_cannot_inherit_the_previous_tenant(): void
    {
        $ctx = app(TenantContext::class);

        // Simulate the state a PREVIOUS job left behind.
        $ctx->set(5);
        $ctx->asSuperAdmin(true);

        // A job that deliberately does NOT set tenant context. With the boundary hook, the
        // Queue::before reset means it starts clean; Queue::after leaves the slate clean too.
        NoopTenantContextJob::dispatch();

        $this->assertNull($ctx->id(), 'tenant id must not survive a job boundary');
        $this->assertFalse($ctx->isSuperAdmin(), 'super-admin bypass must not survive a job boundary');
    }
}

/** A no-op queued job used only to drive the queue lifecycle events in the test above. */
class NoopTenantContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Intentionally does not touch TenantContext.
    }
}
