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
 * WS-3 — tenant-context isolation is structural, with SAVE/RESTORE at every boundary.
 *
 * TenantContext is a per-process singleton. WhatsAppServiceProvider snapshots the caller's state,
 * resets to a clean slate for each job/command, and restores the caller afterwards. This gives both:
 *   - an independent job starts clean (can't inherit the previous tenant / a stale super-admin);
 *   - a SYNC/inline job (CI's QUEUE_CONNECTION=sync, or dispatchSync) does NOT wipe the caller — the
 *     exact OrderObserver::created() -> ensureFromOrder() regression the first cut would have caused.
 */
class TenantContextResetTest extends TestCase
{
    public function test_reset_clears_both_axes(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(9);
        $ctx->asSuperAdmin(true);
        $ctx->reset();
        $this->assertNull($ctx->id());
        $this->assertFalse($ctx->isSuperAdmin());
    }

    public function test_clear_is_tenant_id_only_by_contract(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(3);
        $ctx->asSuperAdmin(true);
        $ctx->clear();
        $this->assertNull($ctx->id(), 'clear() clears the tenant id');
        $this->assertTrue($ctx->isSuperAdmin(), 'clear() must NOT touch super-admin (use reset())');
        $ctx->reset();
    }

    public function test_snapshot_restore_round_trips_both_axes(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(42);
        $ctx->asSuperAdmin(true);
        $snap = $ctx->snapshot();
        $ctx->reset();
        $this->assertNull($ctx->id());
        $ctx->restore($snap);
        $this->assertSame(42, $ctx->id());
        $this->assertTrue($ctx->isSuperAdmin());
        $ctx->reset();
    }

    public function test_sync_inline_job_starts_clean_and_preserves_caller_context(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(5);                       // the "caller" (e.g. an observer inside a request)

        ContextProbeJob::$seenAtStart = -1;
        ContextProbeJob::dispatch();        // QUEUE_CONNECTION=sync -> runs inline

        // The job must have started on a CLEAN slate (reset by the boundary)...
        $this->assertNull(ContextProbeJob::$seenAtStart, 'a job must start with a clean tenant context');
        // ...and the caller's context must be RESTORED after the inline job returns.
        $this->assertSame(5, $ctx->id(), 'caller tenant must survive an inline/sync job');
        $this->assertFalse($ctx->isSuperAdmin());

        $ctx->reset();
    }
}

/**
 * A probe job: records the tenant id it sees at the very start of handle() (should be null — the
 * boundary reset the slate), then sets its own tenant to prove the restore does not leak it out.
 */
class ContextProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?int $seenAtStart = -1;

    public function handle(): void
    {
        self::$seenAtStart = app(TenantContext::class)->id();
        app(TenantContext::class)->set(9);   // the job's own tenant; must NOT survive back to the caller
    }
}
