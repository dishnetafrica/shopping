<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
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
    protected function setUp(): void
    {
        parent::setUp();
        // Register a probe command so the CommandStarting/CommandFinished boundary can be exercised
        // through a real Artisan invocation (not a hand-fired event).
        $this->app[ConsoleKernel::class]->registerCommand(new ContextProbeCommand());
        ContextProbeCommand::$seenAtStart = -1;
    }

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

    /**
     * Command boundary, lifecycle 1 — a TOP-LEVEL command (a scheduled worker invoking `artisan foo`
     * with no prior context) must start clean AND leave the worker clean afterwards. The command's own
     * internal set() must NOT survive the command, or the next scheduled command on that worker would
     * inherit it. "Restore the caller" here means restore to the empty slate the command started from.
     */
    public function test_top_level_command_starts_and_leaves_the_worker_clean(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->reset();                      // a worker with no tenant bound — the scheduler's starting point

        $this->artisan('testing:context-probe')->assertExitCode(0);

        $this->assertNull(ContextProbeCommand::$seenAtStart, 'a top-level command must start on a clean slate');
        $this->assertNull($ctx->id(), 'the worker must be left clean — the command\'s internal set() cannot leak');
        $this->assertFalse($ctx->isSuperAdmin());
    }

    /**
     * Command boundary, lifecycle 2 — a NESTED command (e.g. Artisan::call() from within a tenant
     * request or another command) must still start clean, but on finishing must RESTORE the caller's
     * (non-empty) context, not wipe it. This is the distinct expectation from the top-level case: the
     * worker-clean case restores to null, the nested case restores to the caller's tenant.
     */
    public function test_nested_command_restores_the_callers_context(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set(7);                       // a caller already operating inside tenant 7

        $this->artisan('testing:context-probe')->assertExitCode(0);

        $this->assertNull(ContextProbeCommand::$seenAtStart, 'the nested command still starts on a clean slate');
        $this->assertSame(7, $ctx->id(), 'the caller\'s tenant context must be restored after the nested command');
        $this->assertFalse($ctx->isSuperAdmin());

        $ctx->reset();
    }

    /**
     * The super-admin axis must not survive a command either: a command run under a super-admin caller
     * restores the bypass to the caller, and a top-level command cannot leave the bypass set.
     */
    public function test_command_boundary_restores_the_super_admin_axis(): void
    {
        $ctx = app(TenantContext::class);

        // Nested under a super-admin caller → bypass restored to the caller.
        $ctx->reset();
        $ctx->asSuperAdmin(true);
        $this->artisan('testing:context-probe')->assertExitCode(0);
        $this->assertTrue($ctx->isSuperAdmin(), 'a super-admin caller keeps the bypass after a nested command');

        // Top-level (no caller) → the bypass cannot leak out of the command.
        $ctx->reset();
        $this->artisan('testing:context-probe')->assertExitCode(0);
        $this->assertFalse($ctx->isSuperAdmin(), 'a top-level command must not leave the super-admin bypass set');
        $this->assertNull($ctx->id());
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

/**
 * A probe command: records the tenant id it sees at the start of handle() (should be null — the
 * CommandStarting boundary reset the slate), then sets its own tenant. The CommandFinished boundary
 * must restore the caller's context afterward (null for a top-level run, the caller's tenant for a
 * nested one), so the command's own set() never leaks past it.
 */
class ContextProbeCommand extends Command
{
    protected $signature = 'testing:context-probe';

    protected $description = 'Test probe: record the tenant context at start, then set the command\'s own.';

    public static ?int $seenAtStart = -1;

    public function handle(TenantContext $ctx): int
    {
        self::$seenAtStart = $ctx->id();
        $ctx->set(9);                        // the command's own tenant; must NOT survive past the boundary

        return self::SUCCESS;
    }
}
