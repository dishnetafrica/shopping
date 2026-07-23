<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Constitution v1.0 · P0 Security — the tenant-isolation guard.
 *
 * Row-level multi-tenancy is enforced by the App\Models\Concerns\BelongsToTenant trait, whose global
 * scope filters every query to the active tenant. The catastrophic failure mode is a model that owns a
 * `tenant_id` but FORGETS the trait: its queries then return every tenant's rows unless the caller
 * remembers `where('tenant_id', …)`. One forgotten filter = a cross-tenant leak.
 *
 * This test is deliberately dependency-free — it reads the model source files directly (no database,
 * no factories, no app boot), so it runs even while the rest of the harness is being repaired.
 *
 * REGISTERS (two, on purpose):
 *   • PLATFORM_GLOBAL — models that are cross-tenant BY DESIGN (SaaS billing, operator/QA). These stay
 *     unscoped forever; their tenant-facing reads already filter explicitly. Permanent allow-list.
 *   • KNOWN_UNSCOPED — tenant-owned models that STILL lack the trait: the shrinking P0 debt. Each has a
 *     no-tenant-context command that must be patched (set TenantContext / withoutGlobalScopes) BEFORE
 *     the trait is added — otherwise that command silently returns nothing. Drive this list to empty.
 *
 * The guard FAILS if a new unscoped tenant model appears (regression), and FAILS if a listed model is
 * fixed but not delisted (keeps the register honest). History: 24 → 11 (batch 1: 13 safe models scoped)
 * → 8 (batch 2: SourcingRequest, FollowupProposal, BotMiss scoped after patching their scheduled
 * commands). Remaining debt = the money five, held for a test-gated batch.
 */
class TenantIsolationTest extends TestCase
{
    /** Cross-tenant BY DESIGN — do NOT add the trait (would break platform behaviour). Permanent. */
    private const PLATFORM_GLOBAL = [
        'FieldValidation',  // operator/QA enrolment — cross-tenant operator dashboard
        'Payment',          // SaaS subscription billing; unauth webhooks resolve the tenant FROM the payment
        'ValidationRun',    // platform validation runs; fixtures carry no tenant
    ];

    /**
     * Tenant-owned models that still lack the trait — the P0 remediation backlog (shrink to zero).
     * Each carries the no-tenant-context site that must be patched first (verified in triage):
     */
    private const KNOWN_UNSCOPED = [
        // The money five — held for a dedicated batch WITH a full test run (they feed cash + the GL).
        'CustomerPayment',  // AuditPaymentsCommand, FixPaymentsCommand -> set ctx in tenant loop
        'LedgerAccount',    // LedgerBackfill via LedgerService
        'LedgerJournal',    // LedgerBackfill via LedgerService
        'LedgerLine',       // LedgerBackfill via LedgerService
        'OrderCost',        // FixPaymentsCommand, RepriceForeignCashCommand, LedgerBackfill
    ];

    public function test_no_new_unscoped_tenant_models(): void
    {
        $allowed = array_merge(self::KNOWN_UNSCOPED, self::PLATFORM_GLOBAL);
        $new = array_values(array_diff($this->offenders(), $allowed));
        $this->assertSame([], $new, sprintf(
            "New tenant-owned model(s) without the BelongsToTenant trait — cross-tenant LEAK risk.\n".
            "If tenant-owned, add `use App\\Models\\Concerns\\BelongsToTenant;`. If platform-level by\n".
            "design, add it to PLATFORM_GLOBAL with a reason. Offending: %s",
            implode(', ', $new)
        ));
    }

    public function test_registers_have_no_stale_entries(): void
    {
        $allowed = array_merge(self::KNOWN_UNSCOPED, self::PLATFORM_GLOBAL);
        $stale = array_values(array_diff($allowed, $this->offenders()));
        $this->assertSame([], $stale, sprintf(
            "These are no longer unscoped (fixed or removed) — delete them from KNOWN_UNSCOPED /\n".
            "PLATFORM_GLOBAL so the register keeps shrinking honestly: %s",
            implode(', ', $stale)
        ));
    }

    public function test_belongs_to_tenant_trait_still_adds_a_global_scope(): void
    {
        $file = dirname(__DIR__, 2) . '/app/Models/Concerns/BelongsToTenant.php';
        $src = @file_get_contents($file);
        $this->assertNotFalse($src, 'BelongsToTenant trait file not found.');
        $this->assertStringContainsString('bootBelongsToTenant', $src, 'trait boot method missing');
        $this->assertStringContainsString("addGlobalScope('tenant'", $src, 'the tenant global scope was removed — isolation is off!');
        $this->assertStringContainsString('tenant_id', $src, 'the scope no longer filters by tenant_id');
    }

    /** Scan app/Models: a model is an "offender" if `tenant_id` is in $fillable but the trait is absent. */
    private function offenders(): array
    {
        $dir = dirname(__DIR__, 2) . '/app/Models';
        $offenders = [];
        foreach (glob($dir . '/*.php') as $file) {
            $src = file_get_contents($file);
            if (! preg_match('/\bclass\s+(\w+)/', $src, $m)) {
                continue; // not a class file
            }
            $class = $m[1];
            $usesTrait = (bool) preg_match('/\bBelongsToTenant\b/', $src);
            $fillableHasTenant = false;
            if (preg_match('/\$fillable\s*=\s*\[(.*?)\]\s*;/s', $src, $fm)) {
                $fillableHasTenant = (bool) preg_match("/['\"]tenant_id['\"]/", $fm[1]);
            }
            if ($fillableHasTenant && ! $usesTrait) {
                $offenders[] = $class;
            }
        }
        sort($offenders);
        return $offenders;
    }
}
