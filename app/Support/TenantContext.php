<?php
namespace App\Support;

/** Holds the active tenant id for the current request / job. */
class TenantContext
{
    protected ?int $tenantId = null;
    protected bool $superAdmin = false;

    public function set(?int $tenantId): void { $this->tenantId = $tenantId; }
    public function id(): ?int { return $this->tenantId; }
    public function has(): bool { return $this->tenantId !== null; }
    public function clear(): void { $this->tenantId = null; }

    /**
     * Full reset of BOTH axes — the tenant id AND the super-admin bypass flag.
     *
     * clear() intentionally stays tenant-id-only for callers that want exactly that. reset() is
     * what a request/job/command boundary uses so neither a leftover tenant id nor a leftover
     * super-admin bypass can leak into the next unit of work on a long-lived worker. The leak this
     * guards against is structural, not hypothetical: this object is a per-process singleton, so
     * without an explicit reset at the boundary the previous job's state persists.
     */
    public function reset(): void { $this->tenantId = null; $this->superAdmin = false; }

    /**
     * Capture the full state (both axes) so a nested unit of work can run on a clean slate and then
     * hand the caller's state back. Used by the job/command boundary: snapshot the caller, reset for
     * the job, restore the caller when it finishes — so a SYNC/inline job (dispatchSync, or CI's
     * QUEUE_CONNECTION=sync) never wipes the context the caller is still using.
     */
    public function snapshot(): array { return ['tenantId' => $this->tenantId, 'superAdmin' => $this->superAdmin]; }

    /** Restore a snapshot captured by snapshot(). */
    public function restore(array $state): void
    {
        $this->tenantId   = $state['tenantId'] ?? null;
        $this->superAdmin = (bool) ($state['superAdmin'] ?? false);
    }

    /** When true (super admin context) the tenant global scope is bypassed. */
    public function asSuperAdmin(bool $v = true): void { $this->superAdmin = $v; }
    public function isSuperAdmin(): bool { return $this->superAdmin; }
}
