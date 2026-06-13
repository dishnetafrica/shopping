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

    /** When true (super admin context) the tenant global scope is bypassed. */
    public function asSuperAdmin(bool $v = true): void { $this->superAdmin = $v; }
    public function isSuperAdmin(): bool { return $this->superAdmin; }
}
