<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Participant;

/** A human / business actor. Normal aggregate (not event-sourced). */
final class Party extends Participant
{
    /** @var array<string,string[]> role => permissions */
    private array $roles = [];

    public function __construct(string $tenantId, string $entityId, string $legalName, ?string $id = null)
    {
        parent::__construct($tenantId, $entityId, null, null, null, $id);
        $this->displayName = $legalName;
    }
    public function grantRole(string $role, array $permissions): void { $this->roles[$role] = $permissions; $this->touch(); }
    public function kind(): string { return 'party'; }
    public function roles(): array { return array_keys($this->roles); }
    public function can(string $permission): bool
    { foreach ($this->roles as $perms) if (in_array($permission, $perms, true)) return true; return false; }
}
